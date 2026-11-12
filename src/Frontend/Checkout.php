<?php
/**
 * Checkout integration: widget output, validation and persisting the choices on the order.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Frontend;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Orders\AmountPlanner;
use DalliDelivery\Orders\OrderData;
use DalliDelivery\Plugin;
use DalliDelivery\Settings;
use DalliDelivery\Shipping\ChosenRate;
use DalliDelivery\Shipping\ShippingMethod;

defined( 'ABSPATH' ) || exit;

/**
 * Works for the classic (shortcode) checkout and the Cart & Checkout blocks.
 */
class Checkout {

	/**
	 * Selection storage.
	 *
	 * @var Selection
	 */
	private $selection;

	/**
	 * AJAX controller (provides the widget state).
	 *
	 * @var AjaxController
	 */
	private $ajax;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Selection      $selection Selection.
	 * @param AjaxController $ajax      AJAX controller.
	 * @param Settings       $settings  Settings.
	 */
	public function __construct( Selection $selection, AjaxController $ajax, Settings $settings ) {
		$this->selection = $selection;
		$this->ajax      = $ajax;
		$this->settings  = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_classic_assets' ) );
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_widget' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist_classic' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'clear_selection' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'handle_blocks_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'clear_selection' ) );
	}

	/**
	 * Data passed to the checkout scripts.
	 *
	 * @return array<string,mixed>
	 */
	public static function script_data(): array {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( AjaxController::NONCE ),
			'i18n'    => array(
				'choosePoint'      => __( 'Choose pickup point', 'courier-delivery-connector-for-dalli' ),
				'changePoint'      => __( 'Change pickup point', 'courier-delivery-connector-for-dalli' ),
				'pickupPoint'      => __( 'Pickup point', 'courier-delivery-connector-for-dalli' ),
				'noPointChosen'    => __( 'No pickup point chosen yet.', 'courier-delivery-connector-for-dalli' ),
				'modalTitle'       => __( 'Choose a pickup point', 'courier-delivery-connector-for-dalli' ),
				/* translators: %s: town name */
				'modalTown'        => __( 'Pickup points in %s', 'courier-delivery-connector-for-dalli' ),
				'search'           => __( 'Search by name or address', 'courier-delivery-connector-for-dalli' ),
				'close'            => __( 'Close', 'courier-delivery-connector-for-dalli' ),
				'select'           => __( 'Select', 'courier-delivery-connector-for-dalli' ),
				'selected'         => __( 'Selected', 'courier-delivery-connector-for-dalli' ),
				'loading'          => __( 'Loading…', 'courier-delivery-connector-for-dalli' ),
				'nothingFound'     => __( 'No pickup points found.', 'courier-delivery-connector-for-dalli' ),
				/* translators: %d: number of pickup points */
				'found'            => __( '%d pickup points', 'courier-delivery-connector-for-dalli' ),
				'showingFirst'     => __( 'Showing the first results, refine your search to see more.', 'courier-delivery-connector-for-dalli' ),
				'error'            => __( 'Something went wrong. Please try again.', 'courier-delivery-connector-for-dalli' ),
				'prepaidOnly'      => __( 'Prepaid orders only', 'courier-delivery-connector-for-dalli' ),
				'locker'           => __( 'Parcel locker', 'courier-delivery-connector-for-dalli' ),
				'deliveryDate'     => __( 'Delivery date', 'courier-delivery-connector-for-dalli' ),
				'deliveryInterval' => __( 'Delivery time', 'courier-delivery-connector-for-dalli' ),
				'earliest'         => __( 'As soon as possible', 'courier-delivery-connector-for-dalli' ),
				'pickPointError'   => __( 'Please choose a pickup point.', 'courier-delivery-connector-for-dalli' ),
			),
		);
	}

	/**
	 * Enqueue the shared widget on the classic checkout / cart pages.
	 *
	 * @return void
	 */
	public function enqueue_classic_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// The block checkout registers its own scripts through the integration; skip when blocks render the page.
		$has_block = function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' );
		if ( $has_block ) {
			return;
		}

		wp_enqueue_style( 'dalli-delivery-checkout', DALLI_DELIVERY_URL . 'assets/css/checkout.css', array(), Plugin::asset_version( 'assets/css/checkout.css' ) );
		wp_enqueue_script( 'dalli-delivery-widget', DALLI_DELIVERY_URL . 'assets/js/dalli-widget.js', array(), Plugin::asset_version( 'assets/js/dalli-widget.js' ), true );
		wp_enqueue_script( 'dalli-delivery-classic', DALLI_DELIVERY_URL . 'assets/js/checkout-classic.js', array( 'jquery', 'dalli-delivery-widget' ), Plugin::asset_version( 'assets/js/checkout-classic.js' ), true );
		wp_add_inline_script( 'dalli-delivery-widget', 'window.dalliDeliveryCheckout = ' . wp_json_encode( self::script_data() ) . ';', 'before' );
	}

	/**
	 * Print the widget container below the selected Dalli rate (classic checkout).
	 *
	 * @param \WC_Shipping_Rate $method Rate.
	 * @param int               $index  Package index.
	 * @return void
	 */
	public function render_widget( $method, $index ): void {
		if ( ! $method instanceof \WC_Shipping_Rate || ShippingMethod::ID !== $method->get_method_id() || ! is_checkout() ) {
			return;
		}
		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
		if ( ( $chosen[ $index ] ?? '' ) !== $method->get_id() ) {
			return;
		}

		$rate            = ChosenRate::normalize( $method->get_meta_data() );
		$rate['rate_id'] = $method->get_id();
		$state           = $this->ajax->state_payload( $rate );
		if ( ! $state['needsPvz'] && ! $state['intervals'] ) {
			return;
		}

		printf(
			'<div class="dalli-widget" data-dalli-widget="1" data-state="%s"></div>',
			esc_attr( (string) wp_json_encode( $state ) )
		);
	}

	/**
	 * Payment type that would be sent for a payment gateway.
	 *
	 * @param string $gateway Gateway ID.
	 * @param string $partner Partner code.
	 * @return string
	 */
	private function paytype_for( string $gateway, string $partner ): string {
		return ( new AmountPlanner() )->paytype(
			$gateway,
			(array) $this->settings->get( 'cod_gateways' ),
			(array) $this->settings->get( 'card_gateways' ),
			Catalog::PARTNER_DS === $partner
		);
	}

	/**
	 * Current validation message for the cart state.
	 *
	 * @param string $gateway Payment gateway ID.
	 * @return string
	 */
	private function validation_message( string $gateway ): string {
		$rate = ChosenRate::current();
		if ( ! $rate ) {
			return '';
		}
		$customer = WC()->customer;
		$town     = $customer ? ( '' !== $customer->get_shipping_city() ? $customer->get_shipping_city() : $customer->get_billing_city() ) : '';
		$pvz      = $this->selection->pvz_for( (string) $rate['partner'], (string) $town );
		return ( new CheckoutValidator() )->check( $rate, $pvz, $this->paytype_for( $gateway, (string) $rate['partner'] ) );
	}

	/**
	 * Classic checkout validation.
	 *
	 * @param array<string,mixed> $data   Posted data.
	 * @param \WP_Error           $errors Errors collector.
	 * @return void
	 */
	public function validate_classic( $data, $errors ): void {
		$message = $this->validation_message( isset( $data['payment_method'] ) ? (string) $data['payment_method'] : '' );
		if ( '' !== $message && $errors instanceof \WP_Error ) {
			$errors->add( 'dalli_delivery_validation', $message );
		}
	}

	/**
	 * Classic checkout: copy the choices onto the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function persist_classic( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->persist( $order );
		}
	}

	/**
	 * Blocks checkout: validate and copy the choices onto the order.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When validation fails.
	 * @throws \InvalidArgumentException When validation fails and the Store API exception class is unavailable.
	 */
	public function handle_blocks_order( $order, $request ): void {
		if ( ! $order instanceof \WC_Order || ! OrderData::is_dalli_order( $order ) ) {
			return;
		}
		$gateway = (string) $order->get_payment_method();
		if ( '' === $gateway && $request instanceof \WP_REST_Request ) {
			$gateway = (string) $request->get_param( 'payment_method' );
		}
		$message = $this->validation_message( $gateway );
		if ( '' !== $message ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'dalli_delivery_validation', esc_html( $message ), 400 );
			}
			throw new \InvalidArgumentException( esc_html( $message ) );
		}
		$this->persist( $order );
	}

	/**
	 * Copy delivery details onto the order meta (the order is saved by the caller).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function persist( \WC_Order $order ): void {
		$item = OrderData::shipping_item( $order );
		if ( ! $item ) {
			return;
		}

		$meta = array();
		foreach ( $item->get_meta_data() as $meta_data ) {
			$data                 = $meta_data->get_data();
			$meta[ $data['key'] ] = $data['value'];
		}
		$delivery = ChosenRate::normalize( $meta );
		$order->update_meta_data( OrderData::META_DELIVERY, $delivery );

		if ( Catalog::TYPE_PVZ === $delivery['type'] ) {
			$town = '' !== $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
			$pvz  = $this->selection->pvz_for( (string) $delivery['partner'], (string) $town );
			if ( $pvz ) {
				unset( $pvz['_town'] );
				$order->update_meta_data( OrderData::META_PVZ, $pvz );
			}
		} elseif ( Catalog::supports_intervals( (int) $delivery['service'] ) ) {
			$interval = $this->selection->interval();
			if ( $interval ) {
				$order->update_meta_data( OrderData::META_INTERVAL, $interval );
			}
		}
	}

	/**
	 * Forget the choices after the order has been created.
	 *
	 * @return void
	 */
	public function clear_selection(): void {
		$this->selection->clear();
	}
}
