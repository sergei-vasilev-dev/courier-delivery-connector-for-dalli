<?php
/**
 * Tests for formatting helpers, logger redaction, catalog, address formatting and checkout validation.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Frontend\CheckoutValidator;
use DalliDelivery\Settings;
use DalliDelivery\Shipping\AddressFormatter;
use DalliDelivery\Support\Format;
use DalliDelivery\Support\Logger;
use DalliDelivery\Tests\TestCase;

class SupportTest extends TestCase {

	/**
	 * @dataProvider order_numbers
	 */
	public function test_order_number_sanitizer( string $input, string $expected ): void {
		$this->assertSame( $expected, Format::order_number( $input, 'fallback' ) );
	}

	public function order_numbers(): array {
		return array(
			'plain'                  => array( '1001', '1001' ),
			'with prefix and dash'   => array( 'SHOP-1001_a', 'SHOP-1001_a' ),
			'cyrillic is allowed'    => array( 'Заказ-5', 'Заказ-5' ),
			'forbidden chars'        => array( '#1001/2 (copy)', '1001-2-copy' ),
			'only forbidden chars'   => array( '###', 'fallback' ),
			'too short gets padded'  => array( '7', 'N-7' ),
			'truncated to 50'        => array( str_repeat( 'a', 80 ), str_repeat( 'a', 50 ) ),
			'cyrillic truncated'     => array( str_repeat( 'я', 60 ), str_repeat( 'я', 50 ) ),
			'empty'                  => array( '', 'fallback' ),
			'single cyrillic letter' => array( 'я', 'N-я' ),
		);
	}

	/**
	 * @dataProvider phones
	 */
	public function test_phone_normalisation( string $input, string $expected ): void {
		$this->assertSame( $expected, Format::phone( $input ) );
	}

	public function phones(): array {
		return array(
			array( '+7 (999) 123-45-67', '79991234567' ),
			array( '8 999 123 45 67', '79991234567' ),
			array( '9991234567', '79991234567' ),
			array( '+375 29 123 45 67', '375291234567' ),
			array( 'no digits', '' ),
		);
	}

	public function test_period_formatting(): void {
		$this->assertSame( 'same day', Format::period( '0' ) );
		$this->assertSame( '1 day', Format::period( '1' ) );
		$this->assertSame( '3 days', Format::period( '3' ) );
		$this->assertSame( '1-2 days', Format::period( '1-2' ) );
		$this->assertSame( '', Format::period( '' ) );
	}

	public function test_address_and_text_helpers(): void {
		$this->assertSame( '101000, Москва, ул Тверская', Format::address( array( '101000', ' ', 'Москва', 'Москва', 'ул Тверская' ) ) );
		$this->assertSame( 'Tea & Co', Format::plain_text( "<b>Tea &amp; Co</b>\n" ) );
		$this->assertTrue( Format::same_text( ' Москва ', 'москва' ) );
		$this->assertFalse( Format::same_text( 'Москва', 'Казань' ) );
	}

	public function test_logger_redaction(): void {
		$this->assertSame( '<auth token="[redacted]"/>', Logger::redact( '<auth token="abcdef123456"/>' ) );
		$this->assertSame( "<auth token='[redacted]'/>", Logger::redact( "<auth token='abcdef123456'/>" ) );
		$this->assertSame( 'error with [redacted] inside', Logger::redact( 'error with SECRETVALUE inside', 'SECRETVALUE' ) );
		$this->assertSame( 'short ab stays', Logger::redact( 'short ab stays', 'ab' ), 'Tiny strings are not treated as secrets.' );
	}

	public function test_secret_masking_shows_at_most_four_characters(): void {
		$this->assertSame( '', Settings::mask( '' ) );
		$this->assertSame( '••••••••••••', Settings::mask( 'short' ) );
		$this->assertSame( '••••••••••••7890', Settings::mask( '1234567890' ) );
	}

	public function test_catalog(): void {
		$this->assertSame( 'CDEK', Catalog::normalize_partner( 'sdek' ) );
		$this->assertSame( 'YANDEX', Catalog::normalize_partner( 'BOXBERRY' ) );
		$this->assertSame( 'DS', Catalog::normalize_partner( 'Dalli' ) );
		$this->assertSame( 1, Catalog::fallback_service( 'DS', 'KUR', 'msk' ) );
		$this->assertSame( 11, Catalog::fallback_service( 'DS', 'KUR', 'spb' ) );
		$this->assertSame( 13, Catalog::fallback_service( 'YANDEX', 'PVZ' ) );
		$this->assertSame( 0, Catalog::fallback_service( 'MAGNIT', 'KUR' ) );
		$this->assertTrue( Catalog::supports_cancel( 22 ) );
		$this->assertFalse( Catalog::supports_cancel( 13 ) );
		$this->assertTrue( Catalog::is_final_status( 'COMPLETE' ) );
		$this->assertFalse( Catalog::is_final_status( 'CANCELED' ) );
		$this->assertSame( 'New', Catalog::status_label( 'NEW' ) );
		$this->assertSame( 'UNKNOWN', Catalog::status_label( 'UNKNOWN' ) );
		$this->assertCount( 30, Catalog::statuses(), 'Every documented statusreq status is known.' );
	}

	public function test_address_formatter(): void {
		$states = array(
			'MOW' => 'Москва',
			'SPE' => 'Saint Petersburg',
		);
		$this->assertSame(
			'101000, Москва, ул Тверская, д 1',
			AddressFormatter::format(
				array(
					'postcode'  => '101000',
					'state'     => 'MOW',
					'city'      => 'Москва',
					'address_1' => 'ул Тверская',
					'address_2' => 'д 1',
				),
				$states
			)
		);
		$this->assertSame(
			'Санкт-Петербург, Невский пр',
			AddressFormatter::format(
				array(
					'state'     => 'SPE',
					'city'      => 'Санкт-Петербург',
					'address_1' => 'Невский пр',
				),
				$states
			),
			'Transliterated region names are left out.'
		);
		$this->assertSame( '', AddressFormatter::format( array( 'address_1' => 'ул Тверская' ), $states ), 'No city, no rate.' );
	}

	public function test_checkout_validator(): void {
		$validator = new CheckoutValidator();
		$courier   = array(
			'partner'      => 'DS',
			'type'         => 'KUR',
			'only_prepaid' => false,
		);
		$pvz_rate  = array(
			'partner'      => 'CDEK',
			'type'         => 'PVZ',
			'only_prepaid' => false,
		);
		$point     = array(
			'code'         => 'X1',
			'only_prepaid' => false,
			'cash'         => true,
			'acquiring'    => true,
		);

		$this->assertSame( '', $validator->check( $courier, null, 'CASH' ) );
		$this->assertNotSame( '', $validator->check( $pvz_rate, null, 'NO' ), 'A pickup point is required.' );
		$this->assertStringContainsString( 'CDEK', $validator->check( $pvz_rate, null, 'NO' ) );
		$this->assertSame( '', $validator->check( $pvz_rate, $point, 'CASH' ) );

		$prepaid_rate = array_merge( $courier, array( 'only_prepaid' => true ) );
		$this->assertNotSame( '', $validator->check( $prepaid_rate, null, 'CASH' ) );
		$this->assertSame( '', $validator->check( $prepaid_rate, null, 'NO' ) );

		$prepaid_point = array_merge( $point, array( 'only_prepaid' => true ) );
		$this->assertNotSame( '', $validator->check( $pvz_rate, $prepaid_point, 'CASH' ) );
		$this->assertSame( '', $validator->check( $pvz_rate, $prepaid_point, 'NO' ) );

		$card_only = array_merge( $point, array( 'cash' => false ) );
		$this->assertNotSame( '', $validator->check( $pvz_rate, $card_only, 'CASH' ) );
		$this->assertSame( '', $validator->check( $pvz_rate, $card_only, 'CARD' ) );
	}
}
