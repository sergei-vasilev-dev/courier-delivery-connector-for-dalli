<?php
/**
 * Finds the Dalli shipping rate currently selected by the customer.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the chosen rate from the WooCommerce session and the calculated packages.
 */
final class ChosenRate {

	/**
	 * Describe a Dalli rate (from a WC_Shipping_Rate or an order shipping item) as a plain array.
	 *
	 * @param array<string,mixed> $meta Rate meta data (partner, type, service ...).
	 * @return array<string,mixed>
	 */
	public static function normalize( array $meta ): array {
		return array(
			'partner'      => (string) ( $meta['dalli_partner'] ?? '' ),
			'type'         => (string) ( $meta['dalli_type'] ?? '' ),
			'service'      => (int) ( $meta['dalli_service'] ?? 0 ),
			'period'       => (string) ( $meta['dalli_period'] ?? '' ),
			'zone'         => (string) ( $meta['dalli_zone'] ?? '' ),
			'vat_rate'     => isset( $meta['dalli_vat_rate'] ) && '' !== $meta['dalli_vat_rate'] ? (int) $meta['dalli_vat_rate'] : null,
			'only_prepaid' => ! empty( $meta['dalli_only_prepaid'] ),
		);
	}

	/**
	 * The chosen Dalli rate of the current cart.
	 *
	 * @return array<string,mixed>|null Normalised rate plus 'rate_id'.
	 */
	public static function current(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session instanceof \WC_Session ) {
			return null;
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		if ( ! is_array( $chosen ) ) {
			return null;
		}
		// Plain AJAX requests do not calculate shipping; rates come from the session cache, so this is cheap.
		if ( ! WC()->shipping()->get_packages() && WC()->cart instanceof \WC_Cart && ! WC()->cart->is_empty() ) {
			WC()->cart->calculate_shipping();
		}
		foreach ( WC()->shipping()->get_packages() as $index => $package ) {
			$rate_id = $chosen[ $index ] ?? '';
			if ( '' === $rate_id || ! isset( $package['rates'][ $rate_id ] ) ) {
				continue;
			}
			$rate = $package['rates'][ $rate_id ];
			if ( ShippingMethod::ID !== $rate->get_method_id() ) {
				continue;
			}
			$data            = self::normalize( $rate->get_meta_data() );
			$data['rate_id'] = $rate_id;
			return $data;
		}
		return null;
	}
}
