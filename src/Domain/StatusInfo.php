<?php
/**
 * Delivery status of an order.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for an <order> of statusreq.
 */
final class StatusInfo {

	/**
	 * Order number in the shop (our number).
	 *
	 * @var string
	 */
	public $number = '';

	/**
	 * Dalli barcode.
	 *
	 * @var string
	 */
	public $barcode = '';

	/**
	 * Current status code.
	 *
	 * @var string
	 */
	public $status = '';

	/**
	 * Localised (Russian) status title as sent by Dalli.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * Event time in the branch time zone.
	 *
	 * @var string
	 */
	public $event_time = '';

	/**
	 * Comment.
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Delivered date.
	 *
	 * @var string
	 */
	public $delivered_date = '';

	/**
	 * Courier name.
	 *
	 * @var string
	 */
	public $courier_name = '';

	/**
	 * Courier phone.
	 *
	 * @var string
	 */
	public $courier_phone = '';

	/**
	 * Receipt URL (fiscal receipt).
	 *
	 * @var string
	 */
	public $receipt_url = '';

	/**
	 * History, newest first as sent. Each: status,title,event_time,event_store,created_gmt.
	 *
	 * @var array<int,array<string,string>>
	 */
	public $history = array();
}
