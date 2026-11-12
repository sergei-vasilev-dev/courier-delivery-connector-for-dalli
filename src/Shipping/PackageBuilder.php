<?php
/**
 * Combines cart lines into one parcel specification.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Pure calculation (no WordPress dependency) so it can be unit tested.
 *
 * Algorithm (a deliberate simplification, documented in docs/api-notes.md):
 *  - weight is the sum of line weights plus the packaging weight;
 *  - each unit is oriented so that length >= width >= height;
 *  - the parcel footprint is the largest length by the largest width, and its height is
 *    the total volume divided by the footprint (never lower than the tallest unit);
 *  - the configured packaging box acts as a lower bound for every dimension.
 */
class PackageBuilder {

	/**
	 * Build a parcel.
	 *
	 * @param array<int,array{qty:int,weight:float,length:float,width:float,height:float}> $lines    Cart lines (0 = unknown).
	 * @param array<string,float>                                                          $defaults Fallbacks: weight,length,width,height.
	 * @param array<string,float>                                                          $box      Packaging: weight,length,width,height.
	 * @return PackageSpec
	 */
	public function build( array $lines, array $defaults, array $box = array() ): PackageSpec {
		$weight   = (float) ( $box['weight'] ?? 0 );
		$volume   = 0.0;
		$max_l    = 0.0;
		$max_w    = 0.0;
		$max_h    = 0.0;
		$had_line = false;

		foreach ( $lines as $line ) {
			$qty = max( 0, (int) $line['qty'] );
			if ( 0 === $qty ) {
				continue;
			}
			$had_line = true;

			$unit_weight = (float) $line['weight'] > 0 ? (float) $line['weight'] : (float) $defaults['weight'];
			$weight     += $unit_weight * $qty;

			$dims = array(
				(float) $line['length'] > 0 ? (float) $line['length'] : (float) $defaults['length'],
				(float) $line['width'] > 0 ? (float) $line['width'] : (float) $defaults['width'],
				(float) $line['height'] > 0 ? (float) $line['height'] : (float) $defaults['height'],
			);
			rsort( $dims );

			$volume += $dims[0] * $dims[1] * $dims[2] * $qty;
			$max_l   = max( $max_l, $dims[0] );
			$max_w   = max( $max_w, $dims[1] );
			$max_h   = max( $max_h, $dims[2] );
		}

		if ( ! $had_line ) {
			$weight += (float) $defaults['weight'];
			$max_l   = (float) $defaults['length'];
			$max_w   = (float) $defaults['width'];
			$max_h   = (float) $defaults['height'];
			$volume  = $max_l * $max_w * $max_h;
		}

		$height = $max_h;
		if ( $max_l > 0 && $max_w > 0 ) {
			$height = max( $max_h, $volume / ( $max_l * $max_w ) );
		}

		$length = max( $max_l, (float) ( $box['length'] ?? 0 ) );
		$width  = max( $max_w, (float) ( $box['width'] ?? 0 ) );
		$height = max( $height, (float) ( $box['height'] ?? 0 ) );

		return new PackageSpec(
			round( max( 0.001, $weight ), 3 ),
			ceil( $length * 10 ) / 10,
			ceil( $width * 10 ) / 10,
			ceil( $height * 10 ) / 10
		);
	}
}
