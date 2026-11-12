<?php
var_dump( defined('DALLI_TOKEN'), defined('DALLI_API_URL') );
WC()->frontend_includes(); wc_load_cart();
WC()->cart->empty_cart();
var_dump( WC()->cart->add_to_cart( wc_get_product_id_by_sku( 'TEA-BOX' ), 2 ) );
WC()->customer->set_shipping_country( 'RU' ); WC()->customer->set_shipping_city( 'Москва' );
WC()->cart->calculate_totals();
$p = WC()->cart->get_shipping_packages(); var_dump( count($p), $p[0]['destination'] ?? null );
var_dump( WC()->cart->needs_shipping() );
$zone = WC_Shipping_Zones::get_zone_matching_package( $p[0] ); var_dump( $zone->get_zone_name(), count( $zone->get_shipping_methods(true) ) );
