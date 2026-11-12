<?php
/**
 * Tests for the response parser (fixtures follow the Dalli API documentation examples).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\XmlParser;
use DalliDelivery\Tests\TestCase;

class XmlParserTest extends TestCase {

	public function test_delivery_cost_single_format(): void {
		$rates = ( new XmlParser() )->delivery_cost( $this->fixture( 'deliverycost-single.xml' ) );
		$this->assertCount( 1, $rates );
		$this->assertSame( 'DS', $rates[0]->partner );
		$this->assertSame( 'KUR', $rates[0]->type );
		$this->assertSame( 1, $rates[0]->service );
		$this->assertSame( 329.0, $rates[0]->price );
		$this->assertSame( '1', $rates[0]->period );
		$this->assertSame( 22, $rates[0]->vat_rate );
		$this->assertFalse( $rates[0]->only_prepaid );
	}

	public function test_delivery_cost_extended_format(): void {
		$rates = ( new XmlParser() )->delivery_cost( $this->fixture( 'deliverycost-x2.xml' ) );
		$this->assertCount( 3, $rates );
		$this->assertSame( 1061.5, $rates[1]->price );
		$this->assertSame( '0', $rates[1]->period );
		$this->assertSame( 2, $rates[1]->service );
		$this->assertSame( 'PVZ', $rates[2]->type );
		$this->assertTrue( $rates[2]->only_prepaid );
		$this->assertSame( 'DS', $rates[2]->partner );
	}

	public function test_delivery_cost_error_is_an_api_exception(): void {
		try {
			( new XmlParser() )->delivery_cost( $this->fixture( 'deliverycost-error-4.xml' ) );
			$this->fail( 'Expected an exception.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::TYPE_API, $e->get_type() );
			$this->assertSame( '4', $e->get_api_code() );
			$this->assertFalse( $e->is_retryable() );
		}
	}

	public function test_auth_error(): void {
		try {
			( new XmlParser() )->services( $this->fixture( 'auth-error.xml' ) );
			$this->fail( 'Expected an exception.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::TYPE_AUTH, $e->get_type() );
			$this->assertSame( '401', $e->get_api_code() );
		}
	}

	public function test_rates_with_unknown_type_or_price_are_skipped(): void {
		$xml   = '<deliverycost partner="CDEK"><price service="9" typedelivery="XXX" price="10"/><price service="9" typedelivery="PVZ" price="abc"/><price service="9" typedelivery="PVZ" price="100"/></deliverycost>';
		$rates = ( new XmlParser() )->delivery_cost( $xml );
		$this->assertCount( 1, $rates );
		$this->assertSame( 'CDEK', $rates[0]->partner );
	}

	public function test_services(): void {
		$services = ( new XmlParser() )->services( $this->fixture( 'services.xml' ) );
		$this->assertSame(
			array(
				1  => 'Обычная МСК и МО',
				4  => 'ПВЗ МСК',
				13 => 'ПВЗ Яндекс',
			),
			$services
		);
	}

	public function test_points(): void {
		$points = ( new XmlParser() )->points( $this->fixture( 'pointsinfo.xml' ) );
		$this->assertCount( 2, $points, 'A point without a code is ignored.' );

		$this->assertSame( '53f5b1e4-9cf0-437c-b8b3-d082dbb9c2f4', $points[0]->code );
		$this->assertSame( 'YANDEX', $points[0]->partner, 'BOXBERRY is normalised to YANDEX.' );
		$this->assertSame( 55.634239196777344, $points[0]->lat );
		$this->assertSame( 37.33216857910156, $points[0]->lng );
		$this->assertTrue( $points[0]->acquiring );
		$this->assertFalse( $points[0]->cash );
		$this->assertSame( 30.0, $points[0]->weight_limit );

		$this->assertSame( 'CDEK', $points[1]->partner, 'SDEK is normalised to CDEK.' );
		$this->assertSame( 'Постамат & "Тест"', $points[1]->name );
		$this->assertSame( 'Борисовский проезд, д.15 к1 стр2', $points[1]->address, 'Falls back to addressReduce.' );
		$this->assertNull( $points[1]->lat );
		$this->assertTrue( $points[1]->only_prepaid );
		$this->assertTrue( $points[1]->postamat );
	}

	public function test_basket_create(): void {
		$result = ( new XmlParser() )->basket_create( $this->fixture( 'basketcreate.xml' ) );
		$this->assertSame( '', $result['ABC-1']['barcode'] );
		$this->assertCount( 2, $result['ABC-1']['errors'] );
		$this->assertSame( '4', $result['ABC-1']['errors'][0]['code'] );
		$this->assertSame( 'number', $result['ABC-1']['errors'][0]['field'] );
		$this->assertSame( 'A1594133', $result['ABC-2']['barcode'] );
		$this->assertSame( array(), $result['ABC-2']['errors'] );
	}

	public function test_send_basket_success_is_error_code_zero(): void {
		$result = ( new XmlParser() )->send_basket( $this->fixture( 'sendbasket.xml' ) );
		$this->assertCount( 2, $result );
		$this->assertFalse( $result[0]['ok'] );
		$this->assertSame( '26', $result[0]['code'] );
		$this->assertTrue( $result[1]['ok'] );
		$this->assertSame( 'A1594146', $result[1]['barcode'] );
	}

	public function test_cancel_order(): void {
		$parser = new XmlParser();
		$ok     = $parser->cancel_order( $this->fixture( 'cancelorder-ok.xml' ) );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( '22704658', $ok['ordercode'] );
		$err = $parser->cancel_order( $this->fixture( 'cancelorder-error.xml' ) );
		$this->assertFalse( $err['ok'] );
		$this->assertSame( '1', $err['code'] );
	}

	public function test_statuses(): void {
		$list = ( new XmlParser() )->statuses( $this->fixture( 'statusreq.xml' ) );
		$this->assertCount( 1, $list );
		$info = $list[0];
		$this->assertSame( '2039140001', $info->number );
		$this->assertSame( 'A2833052', $info->barcode );
		$this->assertSame( 'COMPLETE', $info->status );
		$this->assertSame( 'Доставлен', $info->title );
		$this->assertSame( '2020-09-03 17:33:00', $info->event_time );
		$this->assertSame( '2020-09-03', $info->delivered_date );
		$this->assertSame( 'Николаев Николай Николаевич', $info->courier_name );
		$this->assertSame( 'https://example.test/receipt', $info->receipt_url );
		$this->assertCount( 3, $info->history );
		$this->assertSame( 'NEW', $info->history[2]['status'] );
	}

	public function test_commit(): void {
		$parser = new XmlParser();
		$this->assertTrue( $parser->commit( '<commitlaststatus error="0">OK </commitlaststatus>' ) );
		$this->assertFalse( $parser->commit( '<commitlaststatus error="5">fail</commitlaststatus>' ) );
	}

	public function test_intervals(): void {
		$result = ( new XmlParser() )->intervals( $this->fixture( 'intervals.xml' ) );
		$this->assertSame(
			array(
				array(
					'min'  => '09:00',
					'max'  => '13:00',
					'type' => 'basic',
				),
				array(
					'min'  => '09:30',
					'max'  => '15:00',
					'type' => 'client',
				),
			),
			$result[''],
			'Hour-only and seconds formats are normalised; invalid hours are dropped.'
		);
	}

	public function test_intervals_by_date(): void {
		$result = ( new XmlParser() )->intervals( $this->fixture( 'intervals-dates.xml' ) );
		$this->assertSame( array( '2025-10-26', '2025-10-27' ), array_keys( $result ) );
		$this->assertCount( 2, $result['2025-10-26'] );
		$this->assertCount( 1, $result['2025-10-27'] );
	}

	public function test_normalize_time(): void {
		$this->assertSame( '09:00', XmlParser::normalize_time( '9' ) );
		$this->assertSame( '09:05', XmlParser::normalize_time( '09:05:00' ) );
		$this->assertSame( '', XmlParser::normalize_time( '24:00' ) );
		$this->assertSame( '', XmlParser::normalize_time( 'abc' ) );
	}

	public function test_file_from_base64_and_stream(): void {
		$parser = new XmlParser();
		$this->assertSame( '%PDF-1.4' . "\n" . '% fake pdf for tests', $parser->file( $this->fixture( 'file-base64.xml' ) ) );
		$this->assertSame( '%PDF-1.7 raw', $parser->file( '%PDF-1.7 raw' ) );
	}

	public function test_file_errors(): void {
		$this->expectException( ApiException::class );
		( new XmlParser() )->file( '<stickers></stickers>' );
	}

	public function test_doctype_and_entities_are_rejected(): void {
		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'DOCTYPE' );
		( new XmlParser() )->services( $this->fixture( 'xxe.xml' ) );
	}

	public function test_malformed_and_empty_documents(): void {
		$parser = new XmlParser();
		foreach ( array( '', '   ', '<a><b></a>', 'not xml', "\xEF\xBB\xBF" ) as $bad ) {
			try {
				$parser->services( $bad );
				$this->fail( 'Expected an exception for: ' . $bad );
			} catch ( ApiException $e ) {
				$this->assertSame( ApiException::TYPE_MALFORMED, $e->get_type() );
			}
		}
	}

	public function test_bom_is_tolerated(): void {
		$this->assertCount( 3, ( new XmlParser() )->services( "\xEF\xBB\xBF" . $this->fixture( 'services.xml' ) ) );
	}
}
