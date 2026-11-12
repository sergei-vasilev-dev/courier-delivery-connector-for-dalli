<?php
/**
 * Builds a Dalli order payload from a WooCommerce order.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Orders;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Domain\Catalog;
use DalliDelivery\Domain\OrderPayload;
use DalliDelivery\Settings;
use DalliDelivery\Shipping\AddressFormatter;
use DalliDelivery\Support\Format;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Dalli mapping.
 */
class PayloadFactory {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the payload.
	 *
	 * @param \WC_Order $order Order.
	 * @return OrderPayload
	 * @throws ApiException When the order cannot be expressed as a Dalli order.
	 */
	public function build( \WC_Order $order ): OrderPayload {
		$delivery = OrderData::delivery( $order );
		if ( ! $delivery ) {
			throw ApiException::config( esc_html__( 'The order does not use the Dalli shipping method.', 'courier-delivery-connector-for-dalli' ) );
		}

		$partner = (string) $delivery['partner'];
		$type    = (string) $delivery['type'];
		$service = (int) $delivery['service'];
		if ( $service <= 0 ) {
			$service = Catalog::fallback_service( $partner, $type, $this->settings->branch() );
		}
		if ( $service <= 0 ) {
			throw ApiException::config( esc_html__( 'The Dalli service code for this order is unknown.', 'courier-delivery-connector-for-dalli' ) );
		}

		$payload          = new OrderPayload();
		$payload->service = $service;
		$payload->number  = $this->order_number( $order );
		$payload->person  = $this->person( $order );
		$payload->phone   = $this->phone( $order );

		if ( Catalog::TYPE_PVZ === $type ) {
			$pvz = OrderData::pvz( $order );
			if ( ! $pvz ) {
				throw ApiException::config( esc_html__( 'No pickup point was chosen for this order.', 'courier-delivery-connector-for-dalli' ) );
			}
			$payload->pvz_code = (string) $pvz['code'];
			$payload->address  = (string) ( $pvz['address'] ?? '' );
		} else {
			$payload->address = $this->address( $order );
		}

		list( $payload->date, $payload->time_min, $payload->time_max ) = $this->schedule( $order );

		$payload->sender_code = (string) $this->settings->get( 'sender_code' );
		$payload->quantity    = 1;
		$payload->instruction = $this->instruction( $order );

		$amounts = new AmountPlanner();
		$paytype = $amounts->paytype(
			(string) $order->get_payment_method(),
			(array) $this->settings->get( 'cod_gateways' ),
			(array) $this->settings->get( 'card_gateways' ),
			Catalog::PARTNER_DS === $partner
		);
		if ( AmountPlanner::PAY_PREPAID !== $paytype && ! empty( $delivery['only_prepaid'] ) ) {
			throw ApiException::config( esc_html__( 'This delivery option accepts prepaid orders only, but the order is paid on receipt.', 'courier-delivery-connector-for-dalli' ) );
		}
		$payload->paytype = $paytype;

		$planner        = new ItemPlanner();
		$payload->items = $planner->plan( $this->lines( $order ), (int) $this->settings->get( 'item_vat' ) );
		if ( ! $payload->items ) {
			$fallback = new \DalliDelivery\Domain\OrderItem();
			/* translators: %s: order number */
			$fallback->name     = sprintf( __( 'Order %s', 'courier-delivery-connector-for-dalli' ), $order->get_order_number() );
			$fallback->price    = max( 0.01, round( (float) $order->get_total() - $this->shipping_total( $order ), 2 ) );
			$fallback->vat_rate = (int) $this->settings->get( 'item_vat' );
			$payload->items     = array( $fallback );
		}

		$plan                    = $amounts->plan(
			$planner->total( $payload->items ),
			$this->shipping_total( $order ),
			$paytype,
			(string) $this->settings->get( 'declared_value' ),
			$this->settings->get_float( 'declared_percent' )
		);
		$payload->price          = $plan['price'];
		$payload->priced         = $plan['priced'];
		$payload->declared_value = $plan['declared'];

		$accept = (string) $this->settings->get( 'accept_partially' );
		if ( in_array( $accept, array( 'YES', 'NO' ), true ) && AmountPlanner::PAY_PREPAID !== $paytype ) {
			$payload->accept_partially = $accept;
		}

		/**
		 * Filters the payload sent to Dalli.
		 *
		 * @since 1.0.0
		 *
		 * @param OrderPayload $payload Payload.
		 * @param \WC_Order    $order   Order.
		 */
		$filtered = apply_filters( 'dalli_delivery_order_payload', $payload, $order );
		return $filtered instanceof OrderPayload ? $filtered : $payload;
	}

	/**
	 * Order number sent to Dalli (unique per account).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function order_number( \WC_Order $order ): string {
		$stored = (string) $order->get_meta( OrderData::META_NUMBER );
		if ( '' !== $stored ) {
			return $stored;
		}
		$number = Format::order_number( (string) $this->settings->get( 'order_prefix' ) . $order->get_order_number(), 'wc-' . $order->get_id() );
		/**
		 * Filters the order number sent to Dalli. It must stay unique and match [A-Za-z0-9А-Яа-я_-]{2,50}.
		 *
		 * @since 1.0.0
		 *
		 * @param string    $number Number.
		 * @param \WC_Order $order  Order.
		 */
		return Format::order_number( (string) apply_filters( 'dalli_delivery_order_number', $number, $order ), 'wc-' . $order->get_id() );
	}

	/**
	 * Recipient name.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function person( \WC_Order $order ): string {
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		return $name;
	}

	/**
	 * Phone, optionally followed by the e-mail address (the API accepts both in one field).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function phone( \WC_Order $order ): string {
		$raw = $order->get_billing_phone();
		if ( '' !== $order->get_shipping_phone() ) {
			$raw = $order->get_shipping_phone();
		}
		$phone = Format::phone( (string) $raw );
		$email = $order->get_billing_email();
		return '' !== $email ? $phone . ', ' . $email : $phone;
	}

	/**
	 * Delivery address for courier delivery.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function address( \WC_Order $order ): string {
		$states = AddressFormatter::russian_states();

		if ( '' !== $order->get_shipping_city() || '' !== $order->get_shipping_address_1() ) {
			return AddressFormatter::format( $order->get_address( 'shipping' ), $states );
		}
		return AddressFormatter::format( $order->get_address( 'billing' ), $states );
	}

	/**
	 * Delivery date and interval.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{0:string,1:string,2:string} Date, from, to.
	 */
	private function schedule( \WC_Order $order ): array {
		$today    = wp_date( 'Y-m-d' );
		$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );

		$interval = OrderData::interval( $order );
		if ( $interval && $interval['date'] >= $today ) {
			return array( $interval['date'], $interval['min'], $interval['max'] );
		}
		return array(
			$tomorrow,
			(string) $this->settings->get( 'default_time_min' ),
			(string) $this->settings->get( 'default_time_max' ),
		);
	}

	/**
	 * Courier instruction from the customer note.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function instruction( \WC_Order $order ): string {
		$note = trim( (string) $order->get_customer_note() );
		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 250, 'UTF-8' ) : substr( $note, 0, 250 );
	}

	/**
	 * Shipping total with tax.
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	private function shipping_total( \WC_Order $order ): float {
		return (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
	}

	/**
	 * Product lines (and fees) as planner input.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array{name:string,qty:int,total:float,weight:float,sku:string}>
	 */
	private function lines( \WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$weight  = $product instanceof \WC_Product ? (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) : 0.0;
			$lines[] = array(
				'name'   => Format::plain_text( $item->get_name() ),
				'qty'    => max( 1, (int) $item->get_quantity() ),
				'total'  => (float) $item->get_total() + (float) $item->get_total_tax(),
				'weight' => $weight,
				'sku'    => $product instanceof \WC_Product ? (string) preg_replace( '/[^A-Za-z0-9А-Яа-я_.\-\/]/u', '', $product->get_sku() ) : '',
			);
		}
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( ! $fee instanceof \WC_Order_Item_Fee ) {
				continue;
			}
			$lines[] = array(
				'name'   => Format::plain_text( $fee->get_name() ),
				'qty'    => 1,
				'total'  => (float) $fee->get_total() + (float) $fee->get_total_tax(),
				'weight' => 0.0,
				'sku'    => '',
			);
		}
		return $lines;
	}
}
