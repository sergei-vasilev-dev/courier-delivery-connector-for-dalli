<?php
/**
 * Dalli data stored on WooCommerce orders (order CRUD / HPOS safe).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Orders;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Shipping\ChosenRate;
use DalliDelivery\Shipping\ShippingMethod;
use DalliDelivery\Support\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors around order meta. Only the WC_Order CRUD API is used, never post meta functions.
 */
final class OrderData {

	public const META_DELIVERY       = '_dalli_delivery';
	public const META_PVZ            = '_dalli_pvz';
	public const META_INTERVAL       = '_dalli_interval';
	public const META_NUMBER         = '_dalli_number';
	public const META_BARCODE        = '_dalli_barcode';
	public const META_STATUS         = '_dalli_status';
	public const META_STATUS_TITLE   = '_dalli_status_title';
	public const META_STATUS_TIME    = '_dalli_status_time';
	public const META_STATUS_HISTORY = '_dalli_status_history';
	public const META_STATUS_FINAL   = '_dalli_status_final';
	public const META_COURIER        = '_dalli_courier';
	public const META_SENT_AT        = '_dalli_sent_at';
	public const META_SYNCED_AT      = '_dalli_synced_at';
	public const META_ERROR          = '_dalli_error';

	/**
	 * The Dalli shipping line item of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return \WC_Order_Item_Shipping|null
	 */
	public static function shipping_item( \WC_Order $order ): ?\WC_Order_Item_Shipping {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( $item instanceof \WC_Order_Item_Shipping && ShippingMethod::ID === $item->get_method_id() ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Whether the order ships with the Dalli method.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_dalli_order( \WC_Order $order ): bool {
		return null !== self::shipping_item( $order );
	}

	/**
	 * Delivery description (stored at checkout, falling back to the shipping item meta).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public static function delivery( \WC_Order $order ): array {
		$stored = $order->get_meta( self::META_DELIVERY );
		if ( is_array( $stored ) && ! empty( $stored['partner'] ) ) {
			return $stored;
		}
		$item = self::shipping_item( $order );
		if ( $item ) {
			$meta = array();
			foreach ( $item->get_meta_data() as $meta_data ) {
				$data                 = $meta_data->get_data();
				$meta[ $data['key'] ] = $data['value'];
			}
			return ChosenRate::normalize( $meta );
		}
		return array();
	}

	/**
	 * Selected pickup point.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>|null
	 */
	public static function pvz( \WC_Order $order ): ?array {
		$pvz = $order->get_meta( self::META_PVZ );
		return is_array( $pvz ) && ! empty( $pvz['code'] ) ? $pvz : null;
	}

	/**
	 * Chosen delivery interval.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{date:string,min:string,max:string}|null
	 */
	public static function interval( \WC_Order $order ): ?array {
		$interval = $order->get_meta( self::META_INTERVAL );
		if ( is_array( $interval ) && ! empty( $interval['date'] ) && ! empty( $interval['min'] ) && ! empty( $interval['max'] ) ) {
			return array(
				'date' => (string) $interval['date'],
				'min'  => (string) $interval['min'],
				'max'  => (string) $interval['max'],
			);
		}
		return null;
	}

	/**
	 * Dalli barcode.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function barcode( \WC_Order $order ): string {
		return (string) $order->get_meta( self::META_BARCODE );
	}

	/**
	 * Current Dalli status code.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function status( \WC_Order $order ): string {
		return (string) $order->get_meta( self::META_STATUS );
	}

	/**
	 * Status history.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,string>>
	 */
	public static function history( \WC_Order $order ): array {
		$history = $order->get_meta( self::META_STATUS_HISTORY );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Whether the order has been sent to Dalli.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_sent( \WC_Order $order ): bool {
		return '' !== self::barcode( $order );
	}

	/**
	 * Whether Dalli can still cancel the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function can_cancel( \WC_Order $order ): bool {
		$delivery = self::delivery( $order );
		return self::is_sent( $order )
			&& isset( $delivery['service'] )
			&& Catalog::supports_cancel( (int) $delivery['service'] )
			&& ! in_array( self::status( $order ), array( 'COMPLETE', 'PARTIALLY', 'CANCELED', 'RETURNED', 'DELIVERY' ), true );
	}

	/**
	 * Customer-facing delivery summary lines (label => value), shared by templates and emails.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,string>
	 */
	public static function summary( \WC_Order $order ): array {
		$delivery = self::delivery( $order );
		if ( ! $delivery ) {
			return array();
		}
		$rows = array();

		$carrier = Catalog::partner_label( (string) $delivery['partner'] );
		$rows[ __( 'Carrier', 'courier-delivery-connector-for-dalli' ) ] = $carrier . ', ' . Format::lower( Catalog::type_label( (string) $delivery['type'] ) );

		$pvz = self::pvz( $order );
		if ( $pvz ) {
			$rows[ __( 'Pickup point', 'courier-delivery-connector-for-dalli' ) ] = trim( $pvz['name'] . ( '' !== (string) $pvz['address'] ? ' — ' . $pvz['address'] : '' ) );
			if ( ! empty( $pvz['schedule'] ) ) {
				$rows[ __( 'Opening hours', 'courier-delivery-connector-for-dalli' ) ] = (string) $pvz['schedule'];
			}
		}

		$interval = self::interval( $order );
		if ( $interval ) {
			$rows[ __( 'Delivery interval', 'courier-delivery-connector-for-dalli' ) ] = sprintf(
				/* translators: 1: date, 2: start time, 3: end time */
				__( '%1$s, %2$s–%3$s', 'courier-delivery-connector-for-dalli' ),
				wp_date( get_option( 'date_format' ), (int) strtotime( $interval['date'] . ' 12:00:00' ) ),
				$interval['min'],
				$interval['max']
			);
		}

		$barcode = self::barcode( $order );
		if ( '' !== $barcode ) {
			$rows[ __( 'Tracking number', 'courier-delivery-connector-for-dalli' ) ] = $barcode;
			$status = self::status( $order );
			if ( '' !== $status ) {
				$rows[ __( 'Delivery status', 'courier-delivery-connector-for-dalli' ) ] = Catalog::status_label( $status );
			}
		}
		return $rows;
	}
}
