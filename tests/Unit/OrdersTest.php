<?php
/**
 * Tests for order amount planning, item splitting and status mapping.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use DalliDelivery\Orders\AmountPlanner;
use DalliDelivery\Orders\ItemPlanner;
use DalliDelivery\Orders\StatusMapper;
use DalliDelivery\Tests\TestCase;

class OrdersTest extends TestCase {

	private function line( string $name, int $qty, float $total, string $sku = 'S' ): array {
		return array(
			'name'   => $name,
			'qty'    => $qty,
			'total'  => $total,
			'weight' => 0.3,
			'sku'    => $sku,
		);
	}

	public function test_item_prices_add_up_exactly(): void {
		$planner = new ItemPlanner();
		$items   = $planner->plan(
			array(
				$this->line( 'A', 2, 1000.0 ),
				$this->line( 'B', 3, 100.0 ), // 33.33 x 3 = 99.99, remainder 0.01.
				$this->line( 'C', 1, 49.99 ),
			),
			22
		);
		$this->assertSame( 1000.0 + 100.0 + 49.99, $planner->total( $items ) );

		$this->assertCount( 4, $items, 'B is split in two lines to keep the total exact.' );
		$this->assertSame( 2, $items[0]->quantity );
		$this->assertSame( 500.0, $items[0]->price );
		$this->assertSame( 2, $items[1]->quantity );
		$this->assertSame( 33.33, $items[1]->price );
		$this->assertSame( 1, $items[2]->quantity );
		$this->assertSame( 33.34, $items[2]->price );
		$this->assertSame( 22, $items[0]->vat_rate );
		$this->assertSame( 'S', $items[0]->article );
	}

	public function test_free_lines_are_dropped(): void {
		$items = ( new ItemPlanner() )->plan( array( $this->line( 'Gift', 1, 0.0 ), $this->line( 'Paid', 1, 10.0 ) ), 0 );
		$this->assertCount( 1, $items );
		$this->assertSame( 'Paid', $items[0]->name );
		$this->assertSame( 0, $items[0]->vat_rate );
	}

	public function test_tiny_totals_do_not_produce_zero_prices(): void {
		$planner = new ItemPlanner();
		$items   = $planner->plan( array( $this->line( 'Sample', 10, 0.04 ) ), 22 );
		$this->assertSame( 0.04, $planner->total( $items ) );
		foreach ( $items as $item ) {
			$this->assertGreaterThan( 0, $item->price );
		}
	}

	public function test_paytype_mapping(): void {
		$planner = new AmountPlanner();
		$this->assertSame( 'CASH', $planner->paytype( 'cod', array( 'cod' ), array(), true ) );
		$this->assertSame( 'NO', $planner->paytype( 'stripe', array( 'cod' ), array(), true ) );
		$this->assertSame( 'CARD', $planner->paytype( 'pos', array( 'cod' ), array( 'pos' ), true ) );
		$this->assertSame( 'CASH', $planner->paytype( 'pos', array( 'cod' ), array( 'pos' ), false ), 'Partners do not accept cards (error 20).' );
		$this->assertSame( 'NO', $planner->paytype( '', array(), array(), true ) );
	}

	public function test_cash_on_delivery_equals_items_plus_delivery(): void {
		$plan = ( new AmountPlanner() )->plan( 1100.0, 250.0, 'CASH', 'items', 100.0 );
		$this->assertSame( 1350.0, $plan['price'], 'Dalli error 26 requires price = items + delivery.' );
		$this->assertSame( 250.0, $plan['priced'] );
		$this->assertSame( 1350.0, $plan['declared'], 'Declared value can never be below the cash on delivery (error 31).' );
	}

	public function test_prepaid_orders_collect_nothing(): void {
		$plan = ( new AmountPlanner() )->plan( 1100.0, 250.0, 'NO', 'items', 100.0 );
		$this->assertSame( 0.0, $plan['price'] );
		$this->assertSame( 0.0, $plan['priced'] );
		$this->assertSame( 1100.0, $plan['declared'] );
	}

	public function test_declared_percentage_and_floor(): void {
		$planner = new AmountPlanner();
		$this->assertSame( 550.0, $planner->plan( 1100.0, 0, 'NO', 'percent', 50.0 )['declared'] );
		$this->assertSame( 0.01, $planner->plan( 0.0, 0, 'NO', 'items', 100.0 )['declared'], 'Must be positive (error 32).' );
		$this->assertSame( 1100.0, $planner->plan( 1100.0, 0, 'NO', 'percent', 500.0 )['declared'], 'Percentage is capped at 100.' );
	}

	public function test_status_mapper(): void {
		$mapper = new StatusMapper(
			array(
				'COMPLETE' => 'completed',
				'DELIVERY' => 'wc-out-for-delivery',
				'CANCELED' => 'cancelled',
				'RETURNED' => 'nonexistent',
				'NEW'      => '',
			)
		);
		$valid  = array( 'pending', 'processing', 'completed', 'cancelled', 'out-for-delivery' );

		$this->assertSame( 'completed', $mapper->target( 'COMPLETE', 'processing', $valid ) );
		$this->assertSame( 'out-for-delivery', $mapper->target( 'DELIVERY', 'processing', $valid ), 'wc- prefix is stripped.' );
		$this->assertSame( '', $mapper->target( 'COMPLETE', 'completed', $valid ), 'Already there.' );
		$this->assertSame( '', $mapper->target( 'RETURNED', 'processing', $valid ), 'Unknown WooCommerce status is ignored.' );
		$this->assertSame( '', $mapper->target( 'NEW', 'processing', $valid ), 'Empty target means do not change.' );
		$this->assertSame( '', $mapper->target( 'PICKUP', 'processing', $valid ), 'Unmapped status.' );
		$this->assertSame( '', $mapper->target( 'CANCELED', 'completed', $valid ), 'A completed order is never moved back.' );
	}

	public function test_status_map_sanitizing(): void {
		$map = StatusMapper::sanitize(
			array(
				'complete' => 'Completed',
				'BOGUS'    => 'completed',
				'NEW'      => '',
				'DELIVERY' => 'wc-processing',
			)
		);
		$this->assertSame(
			array(
				'COMPLETE' => 'completed',
				'DELIVERY' => 'wc-processing',
			),
			$map
		);
	}
}
