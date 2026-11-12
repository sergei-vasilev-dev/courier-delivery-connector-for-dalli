<?php
/**
 * Tests for price rules and the parcel builder.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use DalliDelivery\Shipping\PackageBuilder;
use DalliDelivery\Shipping\PriceRules;
use DalliDelivery\Tests\TestCase;

class PricingTest extends TestCase {

	public function test_no_rules_returns_the_base_price(): void {
		$this->assertSame( 329.0, ( new PriceRules() )->apply( 329.0, 1000, array() ) );
	}

	public function test_percent_and_fixed_markup(): void {
		$rules = array(
			'markup_percent' => '10',
			'markup_fixed'   => '5',
		);
		$this->assertSame( 115.0, ( new PriceRules() )->apply( 100.0, 0, $rules ) );
	}

	public function test_markup_accepts_comma_decimals_and_negative_discount(): void {
		$this->assertSame( 90.5, ( new PriceRules() )->apply( 100.0, 0, array( 'markup_fixed' => '-9,5' ) ) );
		$this->assertSame( 0.0, ( new PriceRules() )->apply( 10.0, 0, array( 'markup_fixed' => '-50' ) ), 'Never negative.' );
	}

	public function test_free_shipping_threshold(): void {
		$rules = array(
			'free_over'      => '3000',
			'markup_percent' => '20',
		);
		$this->assertSame( 0.0, ( new PriceRules() )->apply( 300.0, 3000, $rules ), 'Reaching the threshold is free.' );
		$this->assertSame( 360.0, ( new PriceRules() )->apply( 300.0, 2999.99, $rules ) );
		$this->assertSame( 300.0, ( new PriceRules() )->apply( 300.0, 99999, array( 'free_over' => '' ) ), 'Empty means disabled.' );
	}

	public function test_fixed_price_wins_over_everything(): void {
		$rules = array(
			'fixed_price'    => '199',
			'free_over'      => '100',
			'markup_percent' => '50',
		);
		$this->assertSame( 199.0, ( new PriceRules() )->apply( 500.0, 5000, $rules ) );
		$this->assertSame( 0.0, ( new PriceRules() )->apply( 500.0, 5000, array( 'fixed_price' => '0' ) ), 'A fixed price of 0 is a valid "always free".' );
	}

	/**
	 * @dataProvider rounding_cases
	 */
	public function test_rounding( float $cost, float $step, string $direction, float $expected ): void {
		$this->assertSame( $expected, ( new PriceRules() )->round_cost( $cost, $step, $direction ) );
	}

	public function rounding_cases(): array {
		return array(
			'none'              => array( 329.456, 0, 'nearest', 329.46 ),
			'nearest 1'         => array( 329.4, 1, 'nearest', 329.0 ),
			'nearest 1 up half' => array( 329.5, 1, 'nearest', 330.0 ),
			'up 1'              => array( 329.01, 1, 'up', 330.0 ),
			'nearest 10'        => array( 324.0, 10, 'nearest', 320.0 ),
			'up 10'             => array( 321.0, 10, 'up', 330.0 ),
			'up exact'          => array( 330.0, 10, 'up', 330.0 ),
			'up 100'            => array( 1.0, 100, 'up', 100.0 ),
			'nearest 5'         => array( 332.6, 5, 'nearest', 335.0 ),
		);
	}

	public function test_rounding_is_applied_after_markup(): void {
		$rules = array(
			'markup_percent'     => '10',
			'rounding_step'      => '10',
			'rounding_direction' => 'up',
		);
		// 300 * 1.1 = 330.00000000000006 in floating point: must still round up to 330, not 340.
		$this->assertSame( 330.0, ( new PriceRules() )->apply( 300.0, 0, $rules ) );
	}

	private const DEFAULTS = array(
		'weight' => 0.5,
		'length' => 10.0,
		'width'  => 10.0,
		'height' => 10.0,
	);

	public function test_single_item_keeps_its_own_dimensions(): void {
		$spec = ( new PackageBuilder() )->build(
			array(
				array(
					'qty'    => 1,
					'weight' => 1.2,
					'length' => 30.0,
					'width'  => 20.0,
					'height' => 5.0,
				),
			),
			self::DEFAULTS
		);
		$this->assertSame( 1.2, $spec->weight );
		$this->assertSame( 30.0, $spec->length );
		$this->assertSame( 20.0, $spec->width );
		$this->assertSame( 5.0, $spec->height );
	}

	public function test_dimensions_are_orientation_independent_and_stack_by_volume(): void {
		$spec = ( new PackageBuilder() )->build(
			array(
				array(
					'qty'    => 4,
					'weight' => 0.5,
					'length' => 5.0,
					'width'  => 30.0,
					'height' => 20.0,
				),
			),
			self::DEFAULTS
		);
		// Unit sorted: 30 x 20 x 5; 4 units stack to height 20 on a 30 x 20 footprint.
		$this->assertSame( 2.0, $spec->weight );
		$this->assertSame( 30.0, $spec->length );
		$this->assertSame( 20.0, $spec->width );
		$this->assertSame( 20.0, $spec->height );
	}

	public function test_missing_data_falls_back_to_defaults(): void {
		$spec = ( new PackageBuilder() )->build(
			array(
				array(
					'qty'    => 2,
					'weight' => 0.0,
					'length' => 0.0,
					'width'  => 0.0,
					'height' => 0.0,
				),
			),
			self::DEFAULTS
		);
		$this->assertSame( 1.0, $spec->weight );
		$this->assertSame( 10.0, $spec->length );
		$this->assertSame( 20.0, $spec->height );
	}

	public function test_packaging_adds_weight_and_sets_minimum_size(): void {
		$spec = ( new PackageBuilder() )->build(
			array(
				array(
					'qty'    => 1,
					'weight' => 1.0,
					'length' => 10.0,
					'width'  => 10.0,
					'height' => 10.0,
				),
			),
			self::DEFAULTS,
			array(
				'weight' => 0.2,
				'length' => 25.0,
				'width'  => 15.0,
				'height' => 12.0,
			)
		);
		$this->assertSame( 1.2, $spec->weight );
		$this->assertSame( 25.0, $spec->length );
		$this->assertSame( 15.0, $spec->width );
		$this->assertSame( 12.0, $spec->height );
	}

	public function test_empty_cart_uses_the_defaults_and_never_zero_weight(): void {
		$spec = ( new PackageBuilder() )->build( array(), array_merge( self::DEFAULTS, array( 'weight' => 0.0 ) ) );
		$this->assertGreaterThan( 0, $spec->weight );
		$this->assertSame( 10.0, $spec->length );
	}
}
