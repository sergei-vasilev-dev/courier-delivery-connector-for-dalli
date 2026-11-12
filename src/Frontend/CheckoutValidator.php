<?php
/**
 * Checkout rules for Dalli rates (pure logic).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Frontend;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Orders\AmountPlanner;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the combination of rate, pickup point and payment method can be fulfilled.
 */
class CheckoutValidator {

	/**
	 * Validate.
	 *
	 * @param array<string,mixed>      $rate    Normalised Dalli rate (partner, type, only_prepaid ...).
	 * @param array<string,mixed>|null $pvz     Selected pickup point (already matched to partner and town) or null.
	 * @param string                   $paytype CASH|CARD|NO as it will be sent to Dalli.
	 * @return string Error message; empty when valid.
	 */
	public function check( array $rate, ?array $pvz, string $paytype ): string {
		$prepaid = ( AmountPlanner::PAY_PREPAID === $paytype );

		if ( ! $prepaid && ! empty( $rate['only_prepaid'] ) ) {
			return __( 'This delivery option is available for prepaid orders only. Please choose online payment or another delivery option.', 'courier-delivery-connector-for-dalli' );
		}

		if ( Catalog::TYPE_PVZ !== ( $rate['type'] ?? '' ) ) {
			return '';
		}

		if ( ! $pvz || empty( $pvz['code'] ) ) {
			return sprintf(
				/* translators: %s: carrier name */
				__( 'Please choose a %s pickup point.', 'courier-delivery-connector-for-dalli' ),
				Catalog::partner_label( (string) ( $rate['partner'] ?? '' ) )
			);
		}

		if ( ! $prepaid ) {
			if ( ! empty( $pvz['only_prepaid'] ) ) {
				return __( 'The chosen pickup point accepts prepaid orders only. Please choose online payment or another pickup point.', 'courier-delivery-connector-for-dalli' );
			}
			if ( AmountPlanner::PAY_CASH === $paytype && isset( $pvz['cash'] ) && empty( $pvz['cash'] ) && ! empty( $pvz['acquiring'] ) ) {
				return __( 'The chosen pickup point does not accept cash. Please choose another payment method or pickup point.', 'courier-delivery-connector-for-dalli' );
			}
		}
		return '';
	}
}
