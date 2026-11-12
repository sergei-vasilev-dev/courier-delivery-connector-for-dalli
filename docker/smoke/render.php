<?php
// Renders the customer-facing delivery blocks (order page + e-mails) in Russian for the latest Dalli order.
load_textdomain( 'courier-delivery-connector-for-dalli', WP_PLUGIN_DIR . '/courier-delivery-connector-for-dalli/languages/courier-delivery-connector-for-dalli-ru_RU.mo' );
$orders = wc_get_orders( array( 'limit' => 1, 'meta_key' => '_dalli_barcode', 'meta_compare' => 'EXISTS' ) );
$order  = $orders[0];
update_option( 'dalli_delivery_tracking_url', 'https://example.test/track/{barcode}' );
echo "--- order page\n";
do_action( 'woocommerce_order_details_after_order_table', $order );
echo "\n--- html e-mail\n";
do_action( 'woocommerce_email_order_meta', $order, false, false );
echo "\n--- plain e-mail\n";
do_action( 'woocommerce_email_order_meta', $order, false, true );
echo "\nplural: ", sprintf( _n( '%d day', '%d days', 2, 'courier-delivery-connector-for-dalli' ), 2 ), ' / ', sprintf( _n( '%d day', '%d days', 5, 'courier-delivery-connector-for-dalli' ), 5 ), "\n";
update_option( 'dalli_delivery_tracking_url', '' );
