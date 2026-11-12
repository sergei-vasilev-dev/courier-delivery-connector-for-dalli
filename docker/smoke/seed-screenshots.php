<?php
/**
 * Demo data for the WordPress.org screenshots (fake customers, mock Dalli API only).
 * Run: wp eval-file /smoke/seed-screenshots.php
 */
use DalliDelivery\Plugin;
use DalliDelivery\Orders\OrderData;

function seed_order( $partner, $type, $service, $gateway, $person, $pvz = null, $items = null ) {
	$order = wc_create_order();
	foreach ( $items ?: array( array( 'TEA-BOX', 2 ), array( 'MUG-1', 1 ) ) as $row ) {
		$order->add_product( wc_get_product( wc_get_product_id_by_sku( $row[0] ) ), $row[1] );
	}
	$addr = array( 'first_name' => $person[0], 'last_name' => $person[1], 'address_1' => '12 Example Street, apt. 4', 'city' => 'Kazan', 'postcode' => '420111', 'country' => 'RU' );
	$order->set_address( $addr + array( 'phone' => '+7 900 000-00-00', 'email' => 'customer@example.test' ), 'billing' );
	$order->set_address( $addr, 'shipping' );
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
	$order->set_status( 'processing' );
	$order->save();
	if ( $pvz ) {
		$order->update_meta_data( OrderData::META_PVZ, $pvz );
	}
	$order->update_meta_data( OrderData::META_DELIVERY, array( 'partner' => $partner, 'type' => $type, 'service' => $service, 'period' => '2', 'zone' => '0', 'vat_rate' => 22, 'only_prepaid' => false ) );
	$order->save();
	return $order;
}
function seed_mock( $path ) {
	wp_remote_get( 'http://mock:8100' . $path );
}

$plugin = Plugin::instance();
seed_mock( '/mock/reset' );
$pvz = array( 'code' => 'cdek-abc-1', 'partner' => 'CDEK', 'name' => 'CDEK pickup point', 'address' => 'Kazan, 1 Sample Avenue', 'town' => 'Kazan', 'schedule' => '9:00-21:00' );
$a   = seed_order( 'DS', 'KUR', 1, 'cod', array( 'Alice', 'Brown' ) );
$b   = seed_order( 'CDEK', 'PVZ', 9, 'bacs', array( 'Bob', 'Green' ), $pvz );
$c   = seed_order( 'DS', 'KUR', 1, 'cod', array( 'Carol', 'White' ), null, array( array( 'KETTLE', 1 ) ) );
$plugin->order_sync()->send( $a );
$plugin->order_sync()->send( $b );
$ba = OrderData::barcode( wc_get_order( $a->get_id() ) );
seed_mock( '/mock/advance?barcode=' . $ba . '&status=DELIVERY' );
$plugin->status_sync()->sync_order( wc_get_order( $a->get_id() ) );
echo 'orders: ' . $a->get_id() . ' ' . $b->get_id() . ' ' . $c->get_id() . "\n";
