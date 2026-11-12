<?php
/**
 * Plugin Name:          Delivery Integration for Dalli
 * Plugin URI:           https://github.com/sergei-vasilev-dev/dalli-delivery-for-woocommerce
 * Description:          Unofficial WooCommerce integration with the Dalli courier service: live shipping rates, pickup points, order creation, tracking and labels. Not affiliated with or endorsed by Dalli.
 * Version:              1.0.0
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Sergey Vasilev
 * Author URI:           https://github.com/sergei-vasilev-dev
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          dalli-delivery-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.5
 * WC tested up to:      11.3
 *
 * @package DalliDelivery
 */

defined( 'ABSPATH' ) || exit;

define( 'DALLI_DELIVERY_VERSION', '1.0.0' );
define( 'DALLI_DELIVERY_FILE', __FILE__ );
define( 'DALLI_DELIVERY_PATH', plugin_dir_path( __FILE__ ) );
define( 'DALLI_DELIVERY_URL', plugin_dir_url( __FILE__ ) );
define( 'DALLI_DELIVERY_BASENAME', plugin_basename( __FILE__ ) );

require_once DALLI_DELIVERY_PATH . 'src/Autoloader.php';

\DalliDelivery\Autoloader::register( DALLI_DELIVERY_PATH . 'src/' );

\DalliDelivery\Plugin::boot();
