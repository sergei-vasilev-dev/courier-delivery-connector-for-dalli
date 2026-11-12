/**
 * Delivery Integration for Dalli - Checkout block integration.
 *
 * Adds the pickup point / delivery interval widget under the shipping options of the Checkout block
 * through the ExperimentalOrderShippingPackages slot, and blocks order submission until a pickup point
 * is chosen (the server validates again when the order is placed).
 */
( function ( window ) {
	'use strict';

	var wp = window.wp;
	var wc = window.wc;
	if ( ! wp || ! wc || ! wc.blocksCheckout || ! wp.plugins || ! window.DalliWidget ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var registerPlugin = wp.plugins.registerPlugin;
	var Slot = wc.blocksCheckout.ExperimentalOrderShippingPackages || wc.blocksCheckout.OrderShippingPackages;
	var t = ( window.dalliDeliveryCheckout || { i18n: {} } ).i18n;
	var VALIDATION_ID = 'dalli-delivery-pvz';

	if ( ! Slot ) {
		return;
	}

	function metaValue( rate, key ) {
		var list = ( rate && rate.meta_data ) || [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].key === key ) {
				return list[ i ].value;
			}
		}
		return '';
	}

	function selectedDalliRate( packages ) {
		for ( var p = 0; p < ( packages || [] ).length; p++ ) {
			var rates = packages[ p ].shipping_rates || [];
			for ( var r = 0; r < rates.length; r++ ) {
				if ( rates[ r ].selected && String( rates[ r ].rate_id ).indexOf( 'dalli_delivery:' ) === 0 ) {
					return rates[ r ];
				}
			}
		}
		return null;
	}

	function setValidation( missing ) {
		try {
			var validation = wp.data.dispatch( 'wc/store/validation' );
			if ( ! validation ) {
				return;
			}
			if ( missing ) {
				validation.setValidationErrors( {
					'dalli-delivery-pvz': { message: t.pickPointError, hidden: false },
				} );
			} else if ( validation.clearValidationError ) {
				validation.clearValidationError( VALIDATION_ID );
			}
		} catch ( e ) {
			// The server-side validation still protects the order.
		}
	}

	function Extras() {
		var packages = useSelect( function ( select ) {
			return select( 'wc/store/cart' ).getShippingRates();
		}, [] );
		var rate = selectedDalliRate( packages );
		var rateId = rate ? rate.rate_id : '';
		var ref = useRef( null );

		useEffect(
			function () {
				var node = ref.current;
				if ( ! rateId || ! node ) {
					setValidation( false );
					return undefined;
				}
				var cancelled = false;
				window.DalliWidget.state()
					.then( function ( state ) {
						if ( cancelled || ! node ) {
							return;
						}
						if ( ! state.needsPvz && ! state.intervals ) {
							while ( node.firstChild ) {
								node.removeChild( node.firstChild );
							}
							setValidation( false );
							return;
						}
						setValidation( state.needsPvz && ! state.pvz );
						window.DalliWidget.mount( node, state, function ( next ) {
							setValidation( next.needsPvz && ! next.pvz );
						} );
					} )
					.catch( function () {
						// No session yet or the cart changed; the widget stays empty.
					} );
				return function () {
					cancelled = true;
					setValidation( false );
				};
			},
			[ rateId, metaValue( rate, 'dalli_type' ) ]
		);

		if ( ! rateId ) {
			return null;
		}
		return el( 'div', { className: 'dalli-widget-slot', ref: ref } );
	}

	registerPlugin( 'dalli-delivery', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( Slot, null, el( Extras ) );
		},
	} );
}( window ) );
