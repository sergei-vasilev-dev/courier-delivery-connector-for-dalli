<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use DalliDelivery\Admin\OrderActions;
use DalliDelivery\Admin\OrderMetabox;
use DalliDelivery\Admin\SettingsPage;
use DalliDelivery\Admin\SystemStatus;
use DalliDelivery\Admin\ToolsPage;
use DalliDelivery\Api\Client;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Api\XmlBuilder;
use DalliDelivery\Api\XmlParser;
use DalliDelivery\Frontend\AjaxController;
use DalliDelivery\Frontend\BlocksIntegration;
use DalliDelivery\Frontend\Checkout;
use DalliDelivery\Frontend\CustomerDisplay;
use DalliDelivery\Frontend\Selection;
use DalliDelivery\Orders\OrderSync;
use DalliDelivery\Orders\PayloadFactory;
use DalliDelivery\Orders\StatusSync;
use DalliDelivery\Shipping\PvzService;
use DalliDelivery\Shipping\RateCalculator;
use DalliDelivery\Shipping\ShippingMethod;
use DalliDelivery\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Wires everything together. Services are created lazily and shared.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Lazily created services.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Get the instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Entry point called by the main plugin file.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$plugin = self::instance();
		add_action( 'before_woocommerce_init', array( $plugin, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $plugin, 'init' ), 20 );
		register_deactivation_hook( DALLI_DELIVERY_FILE, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Declare HPOS and Cart & Checkout Blocks compatibility.
	 *
	 * @return void
	 */
	public function declare_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', DALLI_DELIVERY_FILE, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DALLI_DELIVERY_FILE, true );
		}
	}

	/**
	 * Initialise once all plugins are loaded.
	 *
	 * @return void
	 */
	public function init(): void {
		load_plugin_textdomain( 'dalli-delivery-for-woocommerce', false, dirname( DALLI_DELIVERY_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		add_filter( 'plugin_action_links_' . DALLI_DELIVERY_BASENAME, array( $this, 'action_links' ) );

		$this->selection(); // Ensure the session helper exists for the hooks below.
		$this->ajax()->register();
		( new Checkout( $this->selection(), $this->ajax(), $this->settings() ) )->register();
		( new CustomerDisplay( $this->settings() ) )->register();
		$this->order_sync()->register();
		$this->status_sync()->register();

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			static function ( $registry ): void {
				if ( interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
					$registry->register( new BlocksIntegration() );
				}
			}
		);

		if ( is_admin() ) {
			( new SettingsPage( $this->settings(), $this->api() ) )->register();
			( new ToolsPage( $this->settings() ) )->register();
			( new SystemStatus( $this->settings() ) )->register();
			( new OrderMetabox() )->register();
			( new OrderActions( $this->order_sync(), $this->status_sync(), $this->api(), $this->settings() ) )->register();
		}
	}

	/**
	 * Show an admin notice when WooCommerce is not active.
	 *
	 * @return void
	 */
	public function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Delivery Integration for Dalli requires WooCommerce to be installed and active.', 'dalli-delivery-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Register the shipping method.
	 *
	 * @param array<string,string> $methods Methods.
	 * @return array<string,string>
	 */
	public function register_shipping_method( $methods ) {
		$methods[ ShippingMethod::ID ] = ShippingMethod::class;
		return $methods;
	}

	/**
	 * Plugin list links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( SettingsPage::url() ) . '">' . esc_html__( 'Settings', 'dalli-delivery-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Remove scheduled actions on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( StatusSync::ACTION_RUN, array(), OrderSync::GROUP );
		}
	}

	/**
	 * Settings.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		if ( ! isset( $this->services['settings'] ) ) {
			$this->services['settings'] = new Settings();
		}
		return $this->services['settings'];
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		if ( ! isset( $this->services['logger'] ) ) {
			$this->services['logger'] = new Logger( $this->settings() );
		}
		return $this->services['logger'];
	}

	/**
	 * API facade.
	 *
	 * @return DalliApi
	 */
	public function api(): DalliApi {
		if ( ! isset( $this->services['api'] ) ) {
			$this->services['api'] = new DalliApi( new Client( $this->settings(), $this->logger() ), new XmlBuilder(), new XmlParser() );
		}
		return $this->services['api'];
	}

	/**
	 * Rate calculator.
	 *
	 * @return RateCalculator
	 */
	public function rate_calculator(): RateCalculator {
		if ( ! isset( $this->services['rates'] ) ) {
			$this->services['rates'] = new RateCalculator( $this->api(), $this->settings(), $this->logger() );
		}
		return $this->services['rates'];
	}

	/**
	 * Pickup point service.
	 *
	 * @return PvzService
	 */
	public function pvz_service(): PvzService {
		if ( ! isset( $this->services['pvz'] ) ) {
			$this->services['pvz'] = new PvzService( $this->api(), $this->settings(), $this->logger() );
		}
		return $this->services['pvz'];
	}

	/**
	 * Session selection helper.
	 *
	 * @return Selection
	 */
	public function selection(): Selection {
		if ( ! isset( $this->services['selection'] ) ) {
			$this->services['selection'] = new Selection();
		}
		return $this->services['selection'];
	}

	/**
	 * AJAX controller.
	 *
	 * @return AjaxController
	 */
	public function ajax(): AjaxController {
		if ( ! isset( $this->services['ajax'] ) ) {
			$this->services['ajax'] = new AjaxController( $this->selection(), $this->pvz_service(), $this->api(), $this->settings() );
		}
		return $this->services['ajax'];
	}

	/**
	 * Order sync.
	 *
	 * @return OrderSync
	 */
	public function order_sync(): OrderSync {
		if ( ! isset( $this->services['order_sync'] ) ) {
			$this->services['order_sync'] = new OrderSync( $this->api(), $this->settings(), new PayloadFactory( $this->settings() ), $this->logger() );
		}
		return $this->services['order_sync'];
	}

	/**
	 * Status sync.
	 *
	 * @return StatusSync
	 */
	public function status_sync(): StatusSync {
		if ( ! isset( $this->services['status_sync'] ) ) {
			$this->services['status_sync'] = new StatusSync( $this->api(), $this->settings(), $this->logger() );
		}
		return $this->services['status_sync'];
	}
}
