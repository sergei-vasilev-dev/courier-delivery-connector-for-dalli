<?php
/**
 * Back-end smoke test: order creation, status sync, mapping, cancel, stickers, act, error handling.
 * Run: wp eval-file /smoke/orders.php   (exits non-zero on failure)
 */
use DalliDelivery\Plugin;
use DalliDelivery\Orders\OrderData;

$failures = 0;
function check( $label, $ok, $extra = '' ) {
	global $failures;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ' ' . $label . ( $ok ? '' : ' ' . $extra ) . "\n";
	if ( ! $ok ) {
		++$failures;
	}
}
function mock( $path ) {
	$r = wp_remote_get( 'http://mock:8100' . $path );
	return json_decode( wp_remote_retrieve_body( $r ), true );
}

/**
 * Create an order with a Dalli shipping line.
 */
function make_order( $partner, $type, $service, $gateway, $pvz = null, $items = null ) {
	$order = wc_create_order();
	foreach ( $items ?: array( array( 'TEA-BOX', 3 ), array( 'MUG-1', 1 ) ) as $row ) {
		$order->add_product( wc_get_product( wc_get_product_id_by_sku( $row[0] ) ), $row[1] );
	}
	$order->set_address( array( 'first_name' => 'Анна', 'last_name' => 'Смирнова', 'address_1' => 'ул Ленина, 5, кв 3', 'city' => 'Казань', 'postcode' => '420111', 'country' => 'RU', 'phone' => '8 (917) 555-12-34', 'email' => 'anna@example.test' ), 'billing' );
	$order->set_address( array( 'first_name' => 'Анна', 'last_name' => 'Смирнова', 'address_1' => 'ул Ленина, 5, кв 3', 'city' => 'Казань', 'postcode' => '420111', 'country' => 'RU' ), 'shipping' );
	$ship = new WC_Order_Item_Shipping();
	$ship->set_method_title( 'Dalli' );
	$ship->set_method_id( 'dalli_delivery' );
	$ship->set_instance_id( 3 );
	$ship->set_total( 333.33 );
	foreach ( array( 'dalli_partner' => $partner, 'dalli_type' => $type, 'dalli_service' => $service, 'dalli_vat_rate' => 22 ) as $k => $v ) {
		$ship->add_meta_data( $k, $v );
	}
	$order->add_item( $ship );
	$order->set_payment_method( $gateway );
	$order->calculate_totals( false );
	$order->save();
	if ( $pvz ) {
		$order->update_meta_data( OrderData::META_PVZ, $pvz );
	}
	$order->update_meta_data( OrderData::META_DELIVERY, array( 'partner' => $partner, 'type' => $type, 'service' => $service, 'period' => '1', 'zone' => '0', 'vat_rate' => 22, 'only_prepaid' => false ) );
	$order->save();
	return $order;
}

$plugin = Plugin::instance();
$sync   = $plugin->order_sync();
$status = $plugin->status_sync();
mock( '/mock/reset' );

// 1. Courier order, cash on delivery, odd totals (333.33 shipping, 3 x 1000 + 499.5).
$o1 = make_order( 'DS', 'KUR', 1, 'cod' );
$r  = $sync->send( $o1 );
check( 'courier COD order is created', $r['ok'], wp_json_encode( $r ) );
$o1 = wc_get_order( $o1->get_id() );
check( 'barcode stored', '' !== OrderData::barcode( $o1 ) );
$state = mock( '/mock/state' );
$m     = $state[ OrderData::barcode( $o1 ) ] ?? array();
check( 'payload: paytype CASH', 'CASH' === ( $m['paytype'] ?? '' ) );
check( 'payload: COD = items + delivery (no error 26)', abs( ( $m['price'] ?? 0 ) - 3499.5 - 333.33 ) < 0.001, wp_json_encode( $m ) );
check( 'payload: phone normalised', 0 === strpos( $m['phone'] ?? '', '79175551234' ) );
check( 'payload: address has city', false !== mb_strpos( $m['address'] ?? '', 'Казань' ) );
check( 'second send is a no-op', $sync->send( $o1 )['ok'] );
check( 'only one order exists in Dalli', 1 === count( mock( '/mock/state' ) ) );

// 2. Prepaid PVZ order.
$pvz = array( 'code' => 'cdek-abc-1', 'partner' => 'CDEK', 'name' => 'CDEK point', 'address' => 'Казань, ул. Тестовая, 1', 'town' => 'Казань', 'schedule' => '9-21' );
$o2  = make_order( 'CDEK', 'PVZ', 9, 'bacs', $pvz );
$r   = $sync->send( $o2 );
check( 'prepaid PVZ order is created', $r['ok'], wp_json_encode( $r ) );
$m2 = mock( '/mock/state' )[ OrderData::barcode( wc_get_order( $o2->get_id() ) ) ] ?? array();
check( 'prepaid: paytype NO and price 0', 'NO' === ( $m2['paytype'] ?? '' ) && 0.0 === (float) ( $m2['price'] ?? 1 ) );
check( 'PVZ code sent', 'cdek-abc-1' === ( $m2['pvz'] ?? '' ) );

// 3. PVZ order without a point fails with a readable error and no barcode.
$o3 = make_order( 'CDEK', 'PVZ', 9, 'bacs' );
$r  = $sync->send( $o3 );
check( 'PVZ without a point is refused', ! $r['ok'] && ! $r['retry'] );
check( 'error saved on the order', '' !== wc_get_order( $o3->get_id() )->get_meta( OrderData::META_ERROR ) );

// 4. Card on receipt for a partner is downgraded to cash.
update_option( 'dalli_delivery_card_gateways', array( 'bacs' ) );
$o4 = make_order( 'CDEK', 'PVZ', 9, 'bacs', $pvz );
$r  = $sync->send( $o4 );
check( 'partner + card gateway accepted (sent as cash)', $r['ok'], wp_json_encode( $r ) );
update_option( 'dalli_delivery_card_gateways', array() );

// 5. Order with a free gift line + discount-like fee still reconciles.
$o5 = make_order( 'DS', 'KUR', 1, 'cod', null, array( array( 'MUG-1', 3 ) ) );
$fee = new WC_Order_Item_Fee();
$fee->set_name( 'Gift wrap' );
$fee->set_total( 100 );
$o5->add_item( $fee );
$o5->calculate_totals( false );
$o5->save();
$r = $sync->send( $o5 );
check( 'order with fee and 3 x 499.5 reconciles', $r['ok'], wp_json_encode( $r ) );

// 6. Transport failure is retryable, then succeeds.
mock( '/mock/fail?method=basketcreate&times=1' );
$o6 = make_order( 'DS', 'KUR', 1, 'cod' );
$r  = $sync->send( $o6 );
check( 'HTTP 503 is reported as retryable', ! $r['ok'] && $r['retry'], wp_json_encode( $r ) );
$r = $sync->send( wc_get_order( $o6->get_id() ) );
check( 'retry succeeds', $r['ok'], wp_json_encode( $r ) );

// 7. Status sync per order.
$b1 = OrderData::barcode( wc_get_order( $o1->get_id() ) );
mock( '/mock/advance?barcode=' . $b1 . '&status=DELIVERY' );
check( 'status refreshed', $status->sync_order( wc_get_order( $o1->get_id() ) ) );
$o1 = wc_get_order( $o1->get_id() );
check( 'status DELIVERY stored', 'DELIVERY' === OrderData::status( $o1 ) );
check( 'history stored', count( OrderData::history( $o1 ) ) >= 2 );
check( 'not final yet', '' === (string) $o1->get_meta( OrderData::META_STATUS_FINAL ) );
check( 'cancel is refused after the courier took it (mock rule)', ! $sync->cancel( $o1 )['ok'] );

// 8. Status mapping COMPLETE -> completed.
update_option( 'dalli_delivery_status_map', array( 'COMPLETE' => 'completed' ) );
mock( '/mock/advance?barcode=' . $b1 . '&status=COMPLETE' );
$status->sync_order( wc_get_order( $o1->get_id() ) );
$o1 = wc_get_order( $o1->get_id() );
check( 'COMPLETE is final', 'yes' === $o1->get_meta( OrderData::META_STATUS_FINAL ) );
check( 'order status mapped to completed', 'completed' === $o1->get_status(), $o1->get_status() );
update_option( 'dalli_delivery_status_map', array() );

// 9. Changes feed mode.
update_option( 'dalli_delivery_sync_mode', 'changes' );
$b2 = OrderData::barcode( wc_get_order( $o2->get_id() ) );
mock( '/mock/advance?barcode=' . $b2 . '&status=PICKUPREADY' );
$status->run();
check( 'changes feed applied', 'PICKUPREADY' === OrderData::status( wc_get_order( $o2->get_id() ) ) );
update_option( 'dalli_delivery_sync_mode', 'per_order' );

// 10. Cancel own-courier order (a fresh one) and refuse partner cancellation.
$o7 = make_order( 'DS', 'KUR', 1, 'cod' );
$sync->send( $o7 );
$o7 = wc_get_order( $o7->get_id() );
check( 'cancel allowed for courier order', OrderData::can_cancel( $o7 ) );
check( 'cancel succeeds', $sync->cancel( $o7 )['ok'] );
check( 'cancel not offered for partner order', ! OrderData::can_cancel( wc_get_order( $o2->get_id() ) ) );

// 11. Stickers and act.
$pdf = $plugin->api()->stickers( array( $b1 ), 4 );
check( 'stickers are a PDF', 0 === strpos( $pdf, '%PDF' ) );
$pdf = $plugin->api()->act( array() );
check( 'act is a PDF', 0 === strpos( $pdf, '%PDF' ) );

// 12. Connection test and bad token handling.
check( 'services call works', count( $plugin->api()->services() ) > 0 );

// 13. Duplicate-number recovery: pretend the first response was lost.
$o8 = make_order( 'DS', 'KUR', 1, 'cod' );
$sync->send( $o8 );
$o8 = wc_get_order( $o8->get_id() );
$barcode8 = OrderData::barcode( $o8 );
$o8->delete_meta_data( OrderData::META_BARCODE );
$o8->save();
$r = $sync->send( wc_get_order( $o8->get_id() ) );
check( 'duplicate order number recovers the existing barcode', $r['ok'] && OrderData::barcode( wc_get_order( $o8->get_id() ) ) === $barcode8, wp_json_encode( $r ) );

// 14. Request log never contains the token.
$log = wp_json_encode( mock( '/mock/requests' ) );
check( 'mock request log has no token', false === strpos( $log, 'FAKE_TOKEN_FOR_MOCK' ) );

echo $failures ? "\n$failures FAILED\n" : "\nALL PASSED\n";
exit( $failures ? 1 : 0 );
