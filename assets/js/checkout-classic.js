/**
 * Delivery Integration for Dalli - classic checkout bootstrap.
 */
( function ( $, window ) {
	'use strict';

	if ( ! window.DalliWidget ) {
		return;
	}

	function init() {
		window.DalliWidget.init( document );
	}

	$( document.body ).on( 'updated_checkout', init );
	$( init );
}( window.jQuery, window ) );
