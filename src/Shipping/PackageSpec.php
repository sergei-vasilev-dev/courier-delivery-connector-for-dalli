<?php
/**
 * Dimensions and weight of the parcel being priced.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Value object: weight in kg, dimensions in cm.
 */
final class PackageSpec {

	/**
	 * Weight in kg.
	 *
	 * @var float
	 */
	public $weight;

	/**
	 * Length in cm.
	 *
	 * @var float
	 */
	public $length;

	/**
	 * Width in cm.
	 *
	 * @var float
	 */
	public $width;

	/**
	 * Height in cm.
	 *
	 * @var float
	 */
	public $height;

	/**
	 * Constructor.
	 *
	 * @param float $weight Weight, kg.
	 * @param float $length Length, cm.
	 * @param float $width  Width, cm.
	 * @param float $height Height, cm.
	 */
	public function __construct( float $weight, float $length, float $width, float $height ) {
		$this->weight = $weight;
		$this->length = $length;
		$this->width  = $width;
		$this->height = $height;
	}
}
