<?php
/**
 * Turns order lines into Dalli items whose prices add up exactly.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Orders;

use DalliDelivery\Domain\OrderItem;

defined( 'ABSPATH' ) || exit;

/**
 * Dalli validates that cash-on-delivery = sum(item price x quantity) + delivery (error 26),
 * so every line is converted from its final total (after discounts, with tax) into a unit price
 * and, when the total does not divide evenly, split into two item lines.
 * Free lines are dropped because the API rejects them (error 49).
 */
class ItemPlanner {

	/**
	 * Plan items.
	 *
	 * @param array<int,array{name:string,qty:int,total:float,weight:float,sku:string}> $lines Lines; total is the final line amount including tax.
	 * @param int                                                                       $vat   VAT percent for every item.
	 * @return OrderItem[]
	 */
	public function plan( array $lines, int $vat ): array {
		$items = array();
		foreach ( $lines as $line ) {
			$qty   = max( 1, (int) $line['qty'] );
			$total = round( (float) $line['total'], 2 );
			if ( $total <= 0 ) {
				continue;
			}

			$unit      = round( $total / $qty, 2 );
			$remainder = round( $total - $unit * $qty, 2 );

			if ( $unit <= 0 ) {
				// Cheaper than one kopeck per unit: sell as one unit.
				$items[] = $this->item( $line, 1, $total, $vat );
				continue;
			}

			if ( 0.0 === $remainder || 1 === $qty ) {
				$items[] = $this->item( $line, $qty, 1 === $qty ? $total : $unit, $vat );
				continue;
			}

			$items[] = $this->item( $line, $qty - 1, $unit, $vat );
			$items[] = $this->item( $line, 1, round( $unit + $remainder, 2 ), $vat );
		}
		return $items;
	}

	/**
	 * Sum of price x quantity.
	 *
	 * @param OrderItem[] $items Items.
	 * @return float
	 */
	public function total( array $items ): float {
		$sum = 0.0;
		foreach ( $items as $item ) {
			$sum += $item->price * $item->quantity;
		}
		return round( $sum, 2 );
	}

	/**
	 * Build one item.
	 *
	 * @param array<string,mixed> $line  Source line.
	 * @param int                 $qty   Quantity.
	 * @param float               $price Unit price.
	 * @param int                 $vat   VAT percent.
	 * @return OrderItem
	 */
	private function item( array $line, int $qty, float $price, int $vat ): OrderItem {
		$item           = new OrderItem();
		$item->name     = (string) $line['name'];
		$item->quantity = $qty;
		$item->weight   = (float) $line['weight'];
		$item->price    = $price;
		$item->article  = (string) $line['sku'];
		$item->barcode  = (string) $line['sku'];
		$item->vat_rate = $vat;
		return $item;
	}
}
