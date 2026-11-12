<?php
/**
 * Delivery information for customers: thank-you page, My Account order view and e-mails.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Frontend;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Orders\OrderData;
use DalliDelivery\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders templates that themes can override in yourtheme/woocommerce/dalli-delivery/.
 */
class CustomerDisplay {

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_details' ) );
		add_action( 'woocommerce_email_order_meta', array( $this, 'render_email' ), 20, 3 );
	}

	/**
	 * Template arguments.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function args( \WC_Order $order ): array {
		$barcode = OrderData::barcode( $order );
		$url     = '';
		$pattern = trim( (string) $this->settings->get( 'tracking_url' ) );
		if ( '' !== $barcode && '' !== $pattern ) {
			$url = esc_url_raw( str_replace( '{barcode}', rawurlencode( $barcode ), $pattern ), array( 'http', 'https' ) );
		}

		$history = array();
		foreach ( OrderData::history( $order ) as $entry ) {
			$history[] = array(
				'label' => Catalog::status_label( (string) $entry['status'] ),
				'time'  => (string) $entry['event_time'],
			);
		}

		return array(
			'order'        => $order,
			'rows'         => OrderData::summary( $order ),
			'tracking_url' => $url,
			'history'      => $history,
		);
	}

	/**
	 * Order details block (thank-you page and My Account → View order).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function render_order_details( $order ): void {
		if ( ! $order instanceof \WC_Order || ! OrderData::is_dalli_order( $order ) ) {
			return;
		}
		$args = $this->args( $order );
		if ( ! $args['rows'] ) {
			return;
		}
		wc_get_template( 'order-delivery.php', $args, 'dalli-delivery/', DALLI_DELIVERY_PATH . 'templates/' );
	}

	/**
	 * E-mail block.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the e-mail goes to the shop owner.
	 * @param bool      $plain_text    Plain text e-mail.
	 * @return void
	 */
	public function render_email( $order, $sent_to_admin = false, $plain_text = false ): void {
		unset( $sent_to_admin );
		if ( ! $order instanceof \WC_Order || ! OrderData::is_dalli_order( $order ) ) {
			return;
		}
		$args = $this->args( $order );
		if ( ! $args['rows'] ) {
			return;
		}
		$template = $plain_text ? 'emails/plain/delivery.php' : 'emails/delivery.php';
		wc_get_template( $template, $args, 'dalli-delivery/', DALLI_DELIVERY_PATH . 'templates/' );
	}
}
