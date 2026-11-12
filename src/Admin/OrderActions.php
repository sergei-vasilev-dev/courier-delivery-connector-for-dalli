<?php
/**
 * Admin actions on orders: send, cancel, refresh status, stickers, acts, bulk actions.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Admin;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Orders\OrderData;
use DalliDelivery\Orders\OrderSync;
use DalliDelivery\Orders\StatusSync;
use DalliDelivery\Settings;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * All state-changing admin requests: nonce + capability checked, then redirected with a one-time notice.
 */
class OrderActions {

	public const ACTION_HOOK = 'dalli_delivery_order_action';
	public const ACT_HOOK    = 'dalli_delivery_download_act';

	/**
	 * Order sync.
	 *
	 * @var OrderSync
	 */
	private $sync;

	/**
	 * Status sync.
	 *
	 * @var StatusSync
	 */
	private $status_sync;

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
	 * Constructor.
	 *
	 * @param OrderSync  $sync        Order sync.
	 * @param StatusSync $status_sync Status sync.
	 * @param DalliApi   $api         API.
	 * @param Settings   $settings    Settings.
	 */
	public function __construct( OrderSync $sync, StatusSync $status_sync, DalliApi $api, Settings $settings ) {
		$this->sync        = $sync;
		$this->status_sync = $status_sync;
		$this->api         = $api;
		$this->settings    = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_HOOK, array( $this, 'handle_order_action' ) );
		add_action( 'admin_post_' . self::ACT_HOOK, array( $this, 'handle_act' ) );
		add_action( 'admin_notices', array( $this, 'show_notice' ) );

		$list_screen = $this->is_hpos() ? 'woocommerce_page_wc-orders' : 'edit-shop_order';
		add_filter( 'bulk_actions-' . $list_screen, array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-' . $list_screen, array( $this, 'handle_bulk_actions' ), 10, 3 );
	}

	/**
	 * Whether HPOS is the order storage.
	 *
	 * @return bool
	 */
	private function is_hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * URL of an order action link.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $action send|cancel|refresh|sticker.
	 * @return string
	 */
	public static function action_url( \WC_Order $order, string $action ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION_HOOK,
					'order_id' => $order->get_id(),
					'do'       => $action,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_HOOK . '_' . $order->get_id()
		);
	}

	/**
	 * Store a notice for the next admin page load.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error|warning|info.
	 * @return void
	 */
	private function flash( string $message, string $type ): void {
		set_transient(
			'dalli_delivery_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and clear the stored notice.
	 *
	 * @return void
	 */
	public function show_notice(): void {
		$key    = 'dalli_delivery_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) $notice['message'] ) );
	}

	/**
	 * Handle a single-order action.
	 *
	 * @return void
	 */
	public function handle_order_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below with a per-order nonce.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$do       = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		// phpcs:enable

		check_admin_referer( self::ACTION_HOOK . '_' . $order_id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dalli-delivery-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'dalli-delivery-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		switch ( $do ) {
			case 'send':
				$result = $this->sync->send( $order );
				if ( $result['ok'] ) {
					/* translators: %s: Dalli barcode */
					$this->flash( sprintf( __( 'Order sent to Dalli. Tracking number: %s', 'dalli-delivery-for-woocommerce' ), OrderData::barcode( $order ) ), 'success' );
				} else {
					/* translators: %s: error message */
					$this->flash( sprintf( __( 'Dalli rejected the order: %s', 'dalli-delivery-for-woocommerce' ), $result['message'] ), 'error' );
				}
				break;

			case 'cancel':
				$result = $this->sync->cancel( $order );
				$this->flash( $result['message'], $result['ok'] ? 'success' : 'error' );
				break;

			case 'refresh':
				if ( $this->status_sync->sync_order( $order ) ) {
					$this->flash( __( 'Delivery status refreshed.', 'dalli-delivery-for-woocommerce' ), 'success' );
				} else {
					$this->flash( __( 'Could not refresh the delivery status. See the Dalli log for details.', 'dalli-delivery-for-woocommerce' ), 'warning' );
				}
				break;

			case 'sticker':
				$barcode = OrderData::barcode( $order );
				if ( '' === $barcode ) {
					$this->flash( __( 'The order has not been sent to Dalli.', 'dalli-delivery-for-woocommerce' ), 'error' );
					break;
				}
				$this->stream_stickers( array( $barcode ), $order->get_edit_order_url() );
				return;
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Download the transfer act for today's orders.
	 *
	 * @return void
	 */
	public function handle_act(): void {
		check_admin_referer( self::ACT_HOOK );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dalli-delivery-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		try {
			$pdf = $this->api->act( array() );
		} catch ( ApiException $e ) {
			$this->flash( $e->display_message(), 'error' );
			wp_safe_redirect( ToolsPage::url() );
			exit;
		}
		$this->send_pdf( $pdf, 'dalli-act-' . wp_date( 'Y-m-d' ) . '.pdf' );
	}

	/**
	 * Stream stickers for barcodes, or redirect with an error notice.
	 *
	 * @param string[] $barcodes Barcodes.
	 * @param string   $back     URL to return to on failure.
	 * @return void
	 */
	private function stream_stickers( array $barcodes, string $back ): void {
		try {
			$pdf = $this->api->stickers( $barcodes, (int) $this->settings->get( 'sticker_format' ) );
		} catch ( ApiException $e ) {
			$this->flash(
				sprintf(
					/* translators: %s: error message */
					__( 'Could not get the stickers: %s Dalli only returns stickers for orders sent today.', 'dalli-delivery-for-woocommerce' ),
					$e->display_message()
				),
				'error'
			);
			wp_safe_redirect( $back );
			exit;
		}
		$this->send_pdf( $pdf, 'dalli-stickers-' . wp_date( 'Y-m-d-His' ) . '.pdf' );
	}

	/**
	 * Send a PDF to the browser and stop.
	 *
	 * @param string $pdf      Binary PDF.
	 * @param string $filename File name.
	 * @return void
	 */
	private function send_pdf( string $pdf, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * Add bulk actions.
	 *
	 * @param array<string,string> $actions Actions.
	 * @return array<string,string>
	 */
	public function add_bulk_actions( $actions ) {
		$actions['dalli_send']     = __( 'Send to Dalli', 'dalli-delivery-for-woocommerce' );
		$actions['dalli_stickers'] = __( 'Print Dalli stickers', 'dalli-delivery-for-woocommerce' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action.
	 * @param int[]  $ids         Order IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $ids ) {
		if ( ! in_array( $action, array( 'dalli_send', 'dalli_stickers' ), true ) ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		if ( 'dalli_send' === $action ) {
			$queued = 0;
			$failed = 0;
			foreach ( (array) $ids as $id ) {
				$order = wc_get_order( (int) $id );
				if ( ! $order instanceof \WC_Order || ! OrderData::is_dalli_order( $order ) || OrderData::is_sent( $order ) ) {
					continue;
				}
				$result = $this->sync->send( $order );
				$result['ok'] ? ++$queued : ++$failed;
			}
			$this->flash(
				sprintf(
					/* translators: 1: number of orders sent, 2: number of failures */
					__( 'Dalli: %1$d order(s) sent, %2$d failed. Failures are listed in the order notes.', 'dalli-delivery-for-woocommerce' ),
					$queued,
					$failed
				),
				$failed ? 'warning' : 'success'
			);
			return $redirect_to;
		}

		$barcodes = array();
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof \WC_Order && OrderData::is_sent( $order ) ) {
				$barcodes[] = OrderData::barcode( $order );
			}
		}
		if ( ! $barcodes ) {
			$this->flash( __( 'None of the selected orders has been sent to Dalli.', 'dalli-delivery-for-woocommerce' ), 'warning' );
			return $redirect_to;
		}
		$this->stream_stickers( $barcodes, (string) $redirect_to );
		return $redirect_to;
	}
}
