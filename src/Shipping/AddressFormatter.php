<?php
/**
 * Builds the single-line address Dalli expects.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

use DalliDelivery\Support\Format;

defined( 'ABSPATH' ) || exit;

/**
 * "postcode, region, city, street, flat" with a Russian region name only (the Dalli geocoder works with Russian names).
 */
class AddressFormatter {

	/**
	 * Format a WooCommerce address.
	 *
	 * @param array<string,string> $address Keys: postcode, state, city, address_1, address_2.
	 * @param array<string,string> $states  State code => name map for Russia.
	 * @return string Empty when no city is known.
	 */
	public static function format( array $address, array $states ): string {
		$city = trim( (string) ( $address['city'] ?? '' ) );
		if ( '' === $city ) {
			return '';
		}

		$state      = (string) ( $address['state'] ?? '' );
		$state_name = '';
		if ( '' !== $state ) {
			$state_name = isset( $states[ $state ] ) ? (string) $states[ $state ] : $state;
			if ( ! preg_match( '/[А-Яа-яЁё]/u', $state_name ) ) {
				$state_name = '';
			}
		}

		return Format::address(
			array(
				(string) ( $address['postcode'] ?? '' ),
				$state_name,
				$city,
				(string) ( $address['address_1'] ?? '' ),
				(string) ( $address['address_2'] ?? '' ),
			)
		);
	}

	/**
	 * Russian state names from WooCommerce.
	 *
	 * @return array<string,string>
	 */
	public static function russian_states(): array {
		$states = WC()->countries->get_states( 'RU' );
		return is_array( $states ) ? $states : array();
	}
}
