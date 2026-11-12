/**
 * Courier Delivery Connector for Dalli - Checkout block integration.
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
					'dalli-delivery-pvz': { message: t.pickPointError, hidden: true },
				} );
			} else if ( validation.clearValidationError ) {
				validation.clearValidationError( VALIDATION_ID );
			}
		} catch ( e ) {
			// The server-side validation still protects the order.
		}
	}

	/**
	 * Fetch the widget state for the selected rate. The Store API may still be saving the newly selected rate,
	 * so retry a few times until the server reports the rate the customer sees.
	 *
	 * @param {string}   rateId    Selected rate ID.
	 * @param {Function} cancelled Returns true when the effect was cleaned up.
	 * @param {number}   attempt   Attempt number.
	 * @return {Promise<Object|null>} State or null.
	 */
	function fetchState( rateId, cancelled, attempt ) {
		return window.DalliWidget.state().then( function ( state ) {
			if ( cancelled() ) {
				return null;
			}
			if ( state.rate.id !== rateId && attempt < 6 ) {
				return new Promise( function ( resolve ) {
					window.setTimeout( resolve, 400 );
				} ).then( function () {
					return fetchState( rateId, cancelled, attempt + 1 );
				} );
			}
			return state;
		} );
	}

	function Extras() {
		var packages = useSelect( function ( select ) {
			return select( 'wc/store/cart' ).getShippingRates();
		}, [] );
		var rate = selectedDalliRate( packages );
		var rateId = rate ? rate.rate_id : '';
		var ref = useRef( null );
		var validationError = useSelect( function ( select ) {
			var store = select( 'wc/store/validation' );
			return store && store.getValidationError ? store.getValidationError( VALIDATION_ID ) : null;
		}, [] );

		useEffect(
			function () {
				var node = ref.current;
				if ( ! rateId || ! node ) {
					setValidation( false );
					return undefined;
				}
				var done = false;
				fetchState( rateId, function () {
					return done;
				}, 0 )
					.then( function ( state ) {
						if ( ! state || done || ! node ) {
							return;
						}
						while ( node.firstChild ) {
							node.removeChild( node.firstChild );
						}
						if ( ! state.needsPvz && ! state.intervals ) {
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
					done = true;
					setValidation( false );
				};
			},
			[ rateId ]
		);

		if ( ! rateId ) {
			return null;
		}
		return el(
			'div',
			{ className: 'dalli-widget-slot' },
			el( 'div', { ref: ref } ),
			validationError && ! validationError.hidden ? el( 'div', { className: 'dalli-widget__error', role: 'alert' }, validationError.message ) : null
		);
	}

	registerPlugin( 'dalli-delivery', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( Slot, null, el( Extras ) );
		},
	} );
}( window ) );
