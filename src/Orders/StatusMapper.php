<?php
/**
 * Maps Dalli delivery statuses to WooCommerce order statuses.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Orders;

use DalliDelivery\Domain\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping logic; the map itself is configured in the settings (empty = leave the order status alone).
 */
class StatusMapper {

	/**
	 * Mapping Dalli status => WooCommerce status slug without the "wc-" prefix.
	 *
	 * @var array<string,string>
	 */
	private $map;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $map Configured map.
	 */
	public function __construct( array $map ) {
		$this->map = $map;
	}

	/**
	 * Target WooCommerce status for a Dalli status.
	 *
	 * @param string   $dalli_status    Dalli status code.
	 * @param string   $current         Current WooCommerce status (without "wc-").
	 * @param string[] $valid_statuses  Valid WooCommerce status slugs (without "wc-").
	 * @return string Empty when no change is needed.
	 */
	public function target( string $dalli_status, string $current, array $valid_statuses ): string {
		if ( ! isset( $this->map[ $dalli_status ] ) ) {
			return '';
		}
		$target = (string) $this->map[ $dalli_status ];
		$target = 0 === strpos( $target, 'wc-' ) ? substr( $target, 3 ) : $target;
		if ( '' === $target || $target === $current || ! in_array( $target, $valid_statuses, true ) ) {
			return '';
		}
		// Never move a finished order back to an earlier state.
		if ( in_array( $current, array( 'completed', 'refunded', 'cancelled' ), true ) ) {
			return '';
		}
		return $target;
	}

	/**
	 * Keep only known Dalli statuses in a user supplied map.
	 *
	 * @param array<string,mixed> $raw Raw map.
	 * @return array<string,string>
	 */
	public static function sanitize( array $raw ): array {
		$known = array_keys( Catalog::statuses() );
		$out   = array();
		foreach ( $raw as $status => $target ) {
			$status = strtoupper( (string) $status );
			$target = sanitize_key( (string) $target );
			if ( in_array( $status, $known, true ) && '' !== $target ) {
				$out[ $status ] = $target;
			}
		}
		return $out;
	}
}
