<?php
/**
 * Data needed to create an order in Dalli.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for one <order> of basketcreate.
 */
final class OrderPayload {

	/**
	 * Order number (2-50 chars).
	 *
	 * @var string
	 */
	public $number = '';

	/**
	 * Service code.
	 *
	 * @var int
	 */
	public $service = 0;

	/**
	 * Number of places.
	 *
	 * @var int
	 */
	public $quantity = 1;

	/**
	 * Payment type: CASH, CARD or NO.
	 *
	 * @var string
	 */
	public $paytype = 'NO';

	/**
	 * Cash on delivery amount.
	 *
	 * @var float
	 */
	public $price = 0.0;

	/**
	 * Delivery cost charged to the recipient.
	 *
	 * @var float
	 */
	public $priced = 0.0;

	/**
	 * Declared value.
	 *
	 * @var float
	 */
	public $declared_value = 0.0;

	/**
	 * Courier instruction.
	 *
	 * @var string
	 */
	public $instruction = '';

	/**
	 * Partial redemption: YES, NO or empty (account default).
	 *
	 * @var string
	 */
	public $accept_partially = '';

	/**
	 * Sender (pickup address) code.
	 *
	 * @var string
	 */
	public $sender_code = '';

	/**
	 * Recipient address (courier delivery).
	 *
	 * @var string
	 */
	public $address = '';

	/**
	 * Pickup point code (PVZ delivery).
	 *
	 * @var string
	 */
	public $pvz_code = '';

	/**
	 * Recipient name.
	 *
	 * @var string
	 */
	public $person = '';

	/**
	 * Phone (optionally followed by ", e-mail").
	 *
	 * @var string
	 */
	public $phone = '';

	/**
	 * Delivery date, Y-m-d.
	 *
	 * @var string
	 */
	public $date = '';

	/**
	 * Interval start, H:i.
	 *
	 * @var string
	 */
	public $time_min = '10:00';

	/**
	 * Interval end, H:i.
	 *
	 * @var string
	 */
	public $time_max = '22:00';

	/**
	 * Items.
	 *
	 * @var OrderItem[]
	 */
	public $items = array();
}
