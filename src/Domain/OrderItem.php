<?php
/**
 * An item line of a Dalli order.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for an <item> element.
 */
final class OrderItem {

	/**
	 * Name.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Quantity.
	 *
	 * @var int
	 */
	public $quantity = 1;

	/**
	 * Unit weight in kg.
	 *
	 * @var float
	 */
	public $weight = 0.0;

	/**
	 * Unit price.
	 *
	 * @var float
	 */
	public $price = 0.0;

	/**
	 * Barcode.
	 *
	 * @var string
	 */
	public $barcode = '';

	/**
	 * Article (SKU).
	 *
	 * @var string
	 */
	public $article = '';

	/**
	 * VAT percent; 0 means "no VAT".
	 *
	 * @var int
	 */
	public $vat_rate = 22;
}
