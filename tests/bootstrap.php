<?php
/**
 * PHPUnit bootstrap: no WordPress, no WooCommerce. WordPress functions are stubbed per test with Brain Monkey.
 *
 * @package DalliDelivery
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'DALLI_DELIVERY_VERSION', '1.0.0-test' );
define( 'DALLI_DELIVERY_FILE', dirname( __DIR__ ) . '/kurvio-courier-connector-for-dalli.php' );
define( 'DALLI_DELIVERY_PATH', dirname( __DIR__ ) . '/' );
define( 'DALLI_DELIVERY_URL', 'http://example.test/wp-content/plugins/kurvio-courier-connector-for-dalli/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require_once dirname( __DIR__ ) . '/src/Autoloader.php';
\DalliDelivery\Autoloader::register( dirname( __DIR__ ) . '/src/' );

require_once __DIR__ . '/stubs/WP_Error.php';
