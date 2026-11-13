<?php
/**
 * Delivery details in plain text e-mails.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/dalli-delivery/emails/plain/delivery.php.
 *
 * @package DalliDelivery
 * @var \WC_Order           $order        Order.
 * @var array<string,string> $rows        Label => value.
 * @var string               $tracking_url Tracking URL (may be empty).
 */

defined( 'ABSPATH' ) || exit;

echo "\n" . esc_html( strtoupper( __( 'Delivery', 'kurvio-courier-connector-for-dalli' ) ) ) . "\n\n";
foreach ( $rows as $dalli_delivery_label => $dalli_delivery_value ) {
	echo esc_html( $dalli_delivery_label . ': ' . $dalli_delivery_value ) . "\n";
}
if ( ! empty( $tracking_url ) ) {
	echo esc_html( __( 'Track the delivery', 'kurvio-courier-connector-for-dalli' ) . ': ' . esc_url_raw( $tracking_url ) ) . "\n";
}
echo "\n";
