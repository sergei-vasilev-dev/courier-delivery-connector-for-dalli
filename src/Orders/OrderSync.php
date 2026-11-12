<?php
/**
 * Creates orders in Dalli and cancels them.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Orders;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Settings;
use DalliDelivery\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Order creation service plus the hooks that trigger it (automatically or manually).
 */
class OrderSync {

	public const ACTION_SEND       = 'dalli_delivery_send_order';
	public const ACTION_SYNC_ORDER = 'dalli_delivery_sync_order';
	public const GROUP             = 'dalli-delivery';

	private const MAX_ATTEMPTS = 4;

	/**
	 * API.
	 *
	 * @var DalliApi
	 */
	private $api;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Payload factory.
	 *
	 * @var PayloadFactory
	 */
	private $factory;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param DalliApi       $api      API.
	 * @param Settings       $settings Settings.
	 * @param PayloadFactory $factory  Payload factory.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct( DalliApi $api, Settings $settings, PayloadFactory $factory, Logger $logger ) {
		$this->api      = $api;
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( self::ACTION_SEND, array( $this, 'handle_scheduled_send' ), 10, 2 );
	}

	/**
	 * Queue creation when the order reaches the configured status.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to ): void {
		unset( $from );
		$trigger = (string) $this->settings->get( 'order_trigger' );
		if ( 'manual' === $trigger || $to !== $trigger ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! OrderData::is_dalli_order( $order ) || OrderData::is_sent( $order ) ) {
			return;
		}
		if ( ! $this->settings->has_token() ) {
			return;
		}
		$this->enqueue( $order );
	}

	/**
	 * Schedule the asynchronous creation (or run it right away when Action Scheduler is unavailable).
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $attempt Attempt number.
	 * @param int       $delay   Delay in seconds.
	 * @return void
	 */
	public function enqueue( \WC_Order $order, int $attempt = 1, int $delay = 0 ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			$this->send( $order );
			return;
		}
		$args = array( $order->get_id(), $attempt );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::ACTION_SEND, $args, self::GROUP ) ) {
			return;
		}
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::ACTION_SEND, $args, self::GROUP );
		} else {
			as_enqueue_async_action( self::ACTION_SEND, $args, self::GROUP );
		}
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $order_id Order ID.
	 * @param int $attempt  Attempt number.
	 * @return void
	 */
	public function handle_scheduled_send( $order_id, $attempt = 1 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || OrderData::is_sent( $order ) ) {
			return;
		}
		$result = $this->send( $order );
		if ( ! $result['ok'] && $result['retry'] && (int) $attempt < self::MAX_ATTEMPTS ) {
			$this->enqueue( $order, (int) $attempt + 1, 300 * (int) $attempt );
		}
	}

	/**
	 * Create the order in Dalli (sent straight to pickup).
	 *
	 * @param \WC_Order $order Order.
	 * @return array{ok:bool,message:string,retry:bool}
	 */
	public function send( \WC_Order $order ): array {
		if ( ! OrderData::is_dalli_order( $order ) ) {
			return $this->fail( $order, __( 'The order does not use the Dalli shipping method.', 'courier-delivery-connector-for-dalli' ), false );
		}
		if ( OrderData::is_sent( $order ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'The order was already sent to Dalli.', 'courier-delivery-connector-for-dalli' ),
				'retry'   => false,
			);
		}

		try {
			$payload = $this->factory->build( $order );
			$result  = $this->api->create_order( $payload, true );
		} catch ( ApiException $e ) {
			return $this->fail( $order, $e->display_message(), $e->is_retryable() );
		}

		$barcode = $result['barcode'];
		$errors  = $result['errors'];

		// Error 4 / 33: the number exists already (e.g. a previous attempt timed out after Dalli accepted it).
		if ( '' === $barcode && $this->has_duplicate_error( $errors ) ) {
			$barcode = $this->recover_barcode( $payload->number );
		}

		if ( '' === $barcode ) {
			$messages = array();
			foreach ( $errors as $error ) {
				$messages[] = '' !== $error['code'] ? sprintf( '[%s] %s', $error['code'], $error['message'] ) : $error['message'];
			}
			$message = $messages ? implode( '; ', $messages ) : __( 'Dalli did not return a barcode.', 'courier-delivery-connector-for-dalli' );
			return $this->fail( $order, $message, false );
		}

		$order->update_meta_data( OrderData::META_NUMBER, $payload->number );
		$order->update_meta_data( OrderData::META_BARCODE, $barcode );
		$order->update_meta_data( OrderData::META_SENT_AT, (string) time() );
		$order->delete_meta_data( OrderData::META_ERROR );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Dalli barcode */
				__( 'Order sent to Dalli. Tracking number: %s', 'courier-delivery-connector-for-dalli' ),
				$barcode
			)
		);
		$order->save();

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 2 * MINUTE_IN_SECONDS, self::ACTION_SYNC_ORDER, array( $order->get_id() ), self::GROUP );
		}

		/**
		 * Fires after an order has been created in Dalli.
		 *
		 * @since 1.0.0
		 *
		 * @param \WC_Order $order   Order.
		 * @param string    $barcode Dalli barcode.
		 */
		do_action( 'dalli_delivery_order_sent', $order, $barcode );

		return array(
			'ok'      => true,
			'message' => $barcode,
			'retry'   => false,
		);
	}

	/**
	 * Cancel an order in Dalli.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{ok:bool,message:string}
	 */
	public function cancel( \WC_Order $order ): array {
		$barcode = OrderData::barcode( $order );
		if ( '' === $barcode ) {
			return array(
				'ok'      => false,
				'message' => __( 'The order has not been sent to Dalli.', 'courier-delivery-connector-for-dalli' ),
			);
		}
		try {
			$result = $this->api->cancel_order( $barcode );
		} catch ( ApiException $e ) {
			$this->logger->error( 'Cancel failed: ' . $e->display_message() );
			return array(
				'ok'      => false,
				'message' => $e->display_message(),
			);
		}

		if ( $result['ok'] ) {
			$order->add_order_note( __( 'Delivery cancelled in Dalli. The status will change after Dalli processes the request.', 'courier-delivery-connector-for-dalli' ) );
			$order->save();
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::ACTION_SYNC_ORDER, array( $order->get_id() ), self::GROUP );
			}
		}
		return array(
			'ok'      => $result['ok'],
			'message' => $result['message'],
		);
	}

	/**
	 * Record a failure on the order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $message Message.
	 * @param bool      $retry   Whether a retry may help.
	 * @return array{ok:bool,message:string,retry:bool}
	 */
	private function fail( \WC_Order $order, string $message, bool $retry ): array {
		$this->logger->error( sprintf( 'Order %s was not sent to Dalli: %s', $order->get_order_number(), $message ) );
		if ( $order->get_meta( OrderData::META_ERROR ) !== $message ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Dalli: the order could not be sent. %s', 'courier-delivery-connector-for-dalli' ),
					$message
				)
			);
		}
		$order->update_meta_data( OrderData::META_ERROR, $message );
		$order->save();
		return array(
			'ok'      => false,
			'message' => $message,
			'retry'   => $retry,
		);
	}

	/**
	 * Whether the errors say that the order number is taken.
	 *
	 * @param array<int,array{field:string,code:string,message:string}> $errors Errors.
	 * @return bool
	 */
	private function has_duplicate_error( array $errors ): bool {
		foreach ( $errors as $error ) {
			if ( in_array( $error['code'], array( '4', '33' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Look an existing order up by its number.
	 *
	 * @param string $number Order number.
	 * @return string Barcode or empty string.
	 */
	private function recover_barcode( string $number ): string {
		try {
			$info = $this->api->status( $number );
			return $info ? $info->barcode : '';
		} catch ( ApiException $e ) {
			return '';
		}
	}
}
