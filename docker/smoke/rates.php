<?php
// Calculates shipping for a cart to Moscow with the real WooCommerce pipeline.
WC()->frontend_includes();
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( wc_get_product_id_by_sku( 'TEA-BOX' ), 2 );
WC()->customer->set_shipping_country( 'RU' );
WC()->customer->set_shipping_city( 'Москва' );
WC()->customer->set_shipping_address_1( 'ул Тверская, 1' );
WC()->customer->set_shipping_postcode( '125009' ); WC()->customer->set_shipping_state( 'Москва' );
WC()->cart->calculate_totals();
WC()->shipping()->calculate_shipping( WC()->cart->get_shipping_packages() );
foreach ( WC()->shipping()->get_packages() as $package ) {
	foreach ( $package['rates'] as $id => $rate ) {
		echo $id, ' | ', $rate->get_label(), ' | ', $rate->get_cost(), ' | ', wp_json_encode( $rate->get_meta_data() ), "\n";
	}
}
