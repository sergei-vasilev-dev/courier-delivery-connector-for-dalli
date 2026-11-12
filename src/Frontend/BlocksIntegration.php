<?php
/**
 * Cart & Checkout Blocks integration.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Frontend;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the scripts that add the pickup point / interval selector to the Checkout block.
 * Rates themselves are rendered natively by the block.
 */
class BlocksIntegration implements IntegrationInterface {

	/**
	 * Integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'dalli-delivery';
	}

	/**
	 * Register scripts and styles.
	 *
	 * @return void
	 */
	public function initialize() {
		$version = DALLI_DELIVERY_VERSION;

		wp_register_style( 'dalli-delivery-checkout', DALLI_DELIVERY_URL . 'assets/css/checkout.css', array(), $version );
		wp_register_script( 'dalli-delivery-widget', DALLI_DELIVERY_URL . 'assets/js/dalli-widget.js', array(), $version, true );
		wp_add_inline_script( 'dalli-delivery-widget', 'window.dalliDeliveryCheckout = ' . wp_json_encode( Checkout::script_data() ) . ';', 'before' );
		wp_register_script(
			'dalli-delivery-blocks',
			DALLI_DELIVERY_URL . 'assets/js/checkout-blocks.js',
			array(
				'dalli-delivery-widget',
				'wc-blocks-checkout',
				'wc-blocks-data-store',
				'wp-data',
				'wp-element',
				'wp-plugins',
			),
			$version,
			true
		);
	}

	/**
	 * Script handles loaded on the storefront.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		wp_enqueue_style( 'dalli-delivery-checkout' );
		return array( 'dalli-delivery-blocks' );
	}

	/**
	 * Script handles loaded in the block editor (none needed).
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data exposed to the block scripts through getSetting().
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data() {
		return array();
	}
}
