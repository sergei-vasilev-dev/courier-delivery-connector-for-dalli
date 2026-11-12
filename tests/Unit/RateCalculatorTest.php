<?php
/**
 * Tests for the rate calculator and its transient cache.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use Brain\Monkey\Functions;
use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Domain\DeliveryCostQuery;
use DalliDelivery\Domain\Rate;
use DalliDelivery\Settings;
use DalliDelivery\Shipping\PackageSpec;
use DalliDelivery\Shipping\RateCalculator;
use DalliDelivery\Support\Logger;
use DalliDelivery\Tests\TestCase;

class RateCalculatorTest extends TestCase {

	/**
	 * Fake transient store.
	 *
	 * @var array<string,mixed>
	 */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();
		$this->transients = array();
		$store            = &$this->transients;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn( \Mockery::mock()->shouldIgnoreMissing() );
	}

	private function rate( string $partner, string $type, float $price, int $service = 1 ): Rate {
		$rate          = new Rate();
		$rate->partner = $partner;
		$rate->type    = $type;
		$rate->price   = $price;
		$rate->service = $service;
		$rate->period  = '2';
		return $rate;
	}

	private function calculator( $api ): RateCalculator {
		$settings = new Settings();
		return new RateCalculator( $api, $settings, new Logger( $settings ) );
	}

	public function test_collects_rates_per_partner_and_filters_types(): void {
		$api = \Mockery::mock( DalliApi::class );
		$api->shouldReceive( 'delivery_cost' )
			->twice()
			->andReturnUsing(
				function ( DeliveryCostQuery $query ) {
					$this->assertSame( 'г Москва', $query->to );
					$this->assertSame( 1.5, $query->weight );
					$this->assertSame( 1000.0, $query->declared_value );
					$this->assertSame( '55', $query->sender_code );
					$this->assertTrue( $query->extended );
					if ( 'DS' === $query->partner ) {
						return array( $this->rate( 'DS', 'KUR', 329 ), $this->rate( 'DS', 'PVZ', 239, 4 ) );
					}
					return array( $this->rate( 'CDEK', 'PVZ', 199, 9 ), $this->rate( 'CDEK', 'KUR', 399, 10 ) );
				}
			);

		$rates = $this->calculator( $api )->calculate(
			new PackageSpec( 1.5, 20, 10, 5 ),
			'г Москва',
			1000.0,
			array(
				'DS'   => array( 'KUR', 'PVZ' ),
				'CDEK' => array( 'PVZ' ),
			),
			'55'
		);

		$this->assertCount( 3, $rates );
		$this->assertSame(
			array( 'DS', 'DS', 'CDEK' ),
			array_map(
				static function ( Rate $r ) {
					return $r->partner;
				},
				$rates
			)
		);
		$this->assertSame( 'PVZ', $rates[2]->type );
	}

	public function test_identical_requests_are_served_from_the_cache(): void {
		$api = \Mockery::mock( DalliApi::class );
		$api->shouldReceive( 'delivery_cost' )->once()->andReturn( array( $this->rate( 'DS', 'KUR', 329 ) ) );

		$calculator = $this->calculator( $api );
		$spec       = new PackageSpec( 1.5, 20, 10, 5 );
		$first      = $calculator->calculate( $spec, 'г Москва', 0.0, array( 'DS' => array( 'KUR' ) ) );
		$second     = $calculator->calculate( $spec, 'г Москва', 0.0, array( 'DS' => array( 'KUR' ) ) );

		$this->assertEquals( $first, $second );
		$this->assertSame( 329.0, $second[0]->price );

		// A different address is a different cache key.
		$api->shouldReceive( 'delivery_cost' )->once()->andReturn( array( $this->rate( 'DS', 'KUR', 500 ) ) );
		$third = $calculator->calculate( $spec, 'г Казань', 0.0, array( 'DS' => array( 'KUR' ) ) );
		$this->assertSame( 500.0, $third[0]->price );
	}

	public function test_api_errors_yield_no_rates_and_are_reported(): void {
		$api = \Mockery::mock( DalliApi::class );
		$api->shouldReceive( 'delivery_cost' )
			->twice()
			->andReturnUsing(
				static function ( DeliveryCostQuery $query ) {
					if ( 'DS' === $query->partner ) {
						throw new ApiException( ApiException::TYPE_API, 'Нет ПВЗ в указанном городе', '4' );
					}
					throw new ApiException( ApiException::TYPE_TRANSPORT, 'timeout' );
				}
			);

		$calculator = $this->calculator( $api );
		$rates      = $calculator->calculate(
			new PackageSpec( 1, 10, 10, 10 ),
			'г Тверь',
			0.0,
			array(
				'DS'   => array( 'PVZ' ),
				'CDEK' => array( 'PVZ' ),
			)
		);

		$this->assertSame( array(), $rates );
		$this->assertCount( 2, $calculator->errors() );
	}

	public function test_a_failing_partner_does_not_break_the_others(): void {
		$api = \Mockery::mock( DalliApi::class );
		$api->shouldReceive( 'delivery_cost' )
			->twice()
			->andReturnUsing(
				function ( DeliveryCostQuery $query ) {
					if ( 'DS' === $query->partner ) {
						throw new ApiException( ApiException::TYPE_HTTP, 'HTTP 500' );
					}
					return array( $this->rate( 'CDEK', 'PVZ', 199, 9 ) );
				}
			);
		$rates = $this->calculator( $api )->calculate(
			new PackageSpec( 1, 10, 10, 10 ),
			'г Москва',
			0.0,
			array(
				'DS'   => array( 'PVZ' ),
				'CDEK' => array( 'PVZ' ),
			)
		);
		$this->assertCount( 1, $rates );
		$this->assertSame( 'CDEK', $rates[0]->partner );
	}
}
