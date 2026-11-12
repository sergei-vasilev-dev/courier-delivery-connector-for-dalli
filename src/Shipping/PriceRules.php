<?php
/**
 * Price rules applied on top of the carrier cost.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Pure price calculation: fixed price, free shipping threshold, markup and rounding.
 */
class PriceRules {

	/**
	 * Apply the rules.
	 *
	 * Precedence: a fixed price replaces everything; otherwise markup is applied, then the free
	 * shipping threshold (which wins over markup), then rounding.
	 *
	 * @param float               $base       Carrier cost.
	 * @param float               $cart_total Cart contents cost used for the free shipping threshold.
	 * @param array<string,mixed> $rules      Keys: fixed_price (''|number), free_over (number), markup_percent, markup_fixed, rounding_step, rounding_direction (nearest|up).
	 * @return float
	 */
	public function apply( float $base, float $cart_total, array $rules ): float {
		$fixed = isset( $rules['fixed_price'] ) ? trim( (string) $rules['fixed_price'] ) : '';
		if ( '' !== $fixed && is_numeric( str_replace( ',', '.', $fixed ) ) ) {
			return max( 0.0, round( (float) str_replace( ',', '.', $fixed ), 2 ) );
		}

		$free_over = (float) str_replace( ',', '.', (string) ( $rules['free_over'] ?? 0 ) );
		if ( $free_over > 0 && $cart_total >= $free_over ) {
			return 0.0;
		}

		$percent = (float) str_replace( ',', '.', (string) ( $rules['markup_percent'] ?? 0 ) );
		$fixed_m = (float) str_replace( ',', '.', (string) ( $rules['markup_fixed'] ?? 0 ) );

		$cost = $base * ( 1 + $percent / 100 ) + $fixed_m;
		$cost = max( 0.0, $cost );

		return $this->round_cost( $cost, (float) ( $rules['rounding_step'] ?? 0 ), (string) ( $rules['rounding_direction'] ?? 'nearest' ) );
	}

	/**
	 * Round to a step.
	 *
	 * @param float  $cost      Cost.
	 * @param float  $step      Step (0 = only round to kopecks).
	 * @param string $direction nearest|up.
	 * @return float
	 */
	public function round_cost( float $cost, float $step, string $direction ): float {
		if ( $step <= 0 ) {
			return round( $cost, 2 );
		}
		$units = $cost / $step;
		$units = 'up' === $direction ? ceil( round( $units, 6 ) ) : round( $units );
		return round( $units * $step, 2 );
	}
}
