/**
 * Delivery Integration for Dalli - settings page helpers (connection test).
 */
( function ( window, document ) {
	'use strict';

	var button = document.getElementById( 'dalli-delivery-test-connection' );
	var result = document.getElementById( 'dalli-delivery-test-result' );
	var cfg = window.dalliDeliveryAdmin;
	if ( ! button || ! result || ! cfg ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var body = new URLSearchParams();
		body.append( 'action', 'dalli_delivery_test_connection' );
		body.append( 'nonce', cfg.nonce );
		button.disabled = true;
		result.textContent = cfg.working;
		result.style.color = '';

		window
			.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var ok = json && json.success;
				result.textContent = json && json.data && json.data.message ? json.data.message : cfg.failed;
				result.style.color = ok ? '#008a20' : '#b32d2e';
			} )
			.catch( function () {
				result.textContent = cfg.failed;
				result.style.color = '#b32d2e';
			} )
			.then( function () {
				button.disabled = false;
			} );
	} );
}( window, document ) );
