<?php
// Idempotently create the "Russia" shipping zone with the Dalli method.
foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
	if ( 'Russia' === $zone_data['zone_name'] ) {
		$zone = new WC_Shipping_Zone( $zone_data['id'] );
		$zone->delete();
	}
}
$zone = new WC_Shipping_Zone( null );
$zone->set_zone_name( 'Russia' );
$zone->add_location( 'RU', 'country' );
$zone->save();
$instance = $zone->add_shipping_method( 'dalli_delivery' );
echo 'zone ', $zone->get_id(), ' instance ', $instance, "\n";
