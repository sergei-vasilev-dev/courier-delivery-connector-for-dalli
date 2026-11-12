<?php
/**
 * Uninstall: remove options, transients and scheduled actions. Order meta (tracking data) is kept on purpose.
 *
 * @package DalliDelivery
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Options.
$dalli_delivery_like = $wpdb->esc_like( 'dalli_delivery_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $dalli_delivery_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Shipping method instance settings (zones).
$dalli_delivery_instances = $wpdb->esc_like( 'woocommerce_dalli_delivery_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $dalli_delivery_instances ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Transients (rates, pickup points, intervals, one-time notices).
foreach ( array( 'dalli_rates_', 'dalli_pvz_', 'dalli_int_', 'dalli_delivery_notice_' ) as $dalli_delivery_prefix ) {
	$dalli_delivery_transient = $wpdb->esc_like( '_transient_' . $dalli_delivery_prefix ) . '%';
	$dalli_delivery_timeout   = $wpdb->esc_like( '_transient_timeout_' . $dalli_delivery_prefix ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $dalli_delivery_transient, $dalli_delivery_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// Scheduled actions (Action Scheduler).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'dalli_delivery_sync_statuses', 'dalli_delivery_sync_order', 'dalli_delivery_send_order' ) as $dalli_delivery_hook ) {
		as_unschedule_all_actions( $dalli_delivery_hook, array(), 'dalli-delivery' );
	}
}
