<?php
/**
 * Cash-on-delivery, delivery and declared amounts of a Dalli order.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Pure calculation of the money fields so the arithmetic stays consistent.
 */
class AmountPlanner {

	public const PAY_CASH    = 'CASH';
	public const PAY_CARD    = 'CARD';
	public const PAY_PREPAID = 'NO';

	/**
	 * Decide the Dalli payment type from the WooCommerce payment gateway ID.
	 *
	 * @param string   $gateway       Gateway ID of the order.
	 * @param string[] $cod_gateways  Gateways paid in cash on receipt.
	 * @param string[] $card_gateways Gateways paid by card on receipt.
	 * @param bool     $card_allowed  Whether card-on-receipt is available (own couriers only, error 20 otherwise).
	 * @return string CASH|CARD|NO
	 */
	public function paytype( string $gateway, array $cod_gateways, array $card_gateways, bool $card_allowed ): string {
		if ( in_array( $gateway, $card_gateways, true ) ) {
			return $card_allowed ? self::PAY_CARD : self::PAY_CASH;
		}
		if ( in_array( $gateway, $cod_gateways, true ) ) {
			return self::PAY_CASH;
		}
		return self::PAY_PREPAID;
	}

	/**
	 * Plan amounts.
	 *
	 * @param float  $items_total     Sum of item prices x quantities.
	 * @param float  $shipping_total  Shipping charged to the customer (with tax).
	 * @param string $paytype         CASH|CARD|NO.
	 * @param string $declared_mode   items|percent.
	 * @param float  $declared_percent Percent of the items total when mode is percent.
	 * @return array{price:float,priced:float,declared:float}
	 */
	public function plan( float $items_total, float $shipping_total, string $paytype, string $declared_mode, float $declared_percent ): array {
		$prepaid = ( self::PAY_PREPAID === $paytype );
		$priced  = $prepaid ? 0.0 : round( max( 0.0, $shipping_total ), 2 );
		$price   = $prepaid ? 0.0 : round( $items_total + $priced, 2 );

		$declared = $items_total;
		if ( 'percent' === $declared_mode ) {
			$declared = $items_total * max( 0.0, min( 100.0, $declared_percent ) ) / 100;
		}
		// The cash-on-delivery amount may not exceed the declared value (error 31), which must be positive (error 32).
		$declared = round( max( $declared, $price, 0.01 ), 2 );

		return array(
			'price'    => $price,
			'priced'   => $priced,
			'declared' => $declared,
		);
	}
}
