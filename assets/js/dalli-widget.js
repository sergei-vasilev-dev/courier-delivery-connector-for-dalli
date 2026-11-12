/**
 * Courier Delivery Connector for Dalli - checkout widget.
 *
 * Renders the pickup point chooser (accessible modal with search) and the delivery date/time selects.
 * Shared by the classic checkout and the Checkout block. No dependencies, no third-party requests:
 * the only network calls go to this site's own admin-ajax.php endpoints.
 */
( function ( window, document ) {
	'use strict';

	var cfg = window.dalliDeliveryCheckout || { i18n: {} };
	var t = cfg.i18n;
	var RENDER_LIMIT = 200;

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', 'dalli_delivery_' + action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return window
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
				if ( ! json || ! json.success ) {
					var message = json && json.data && json.data.message ? json.data.message : t.error;
					throw new Error( message );
				}
				return json.data;
			} );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'class' ) {
				node.className = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function format( template, value ) {
		return String( template ).replace( '%s', value ).replace( '%d', value );
	}

	function Widget( root, state, notify ) {
		this.root = root;
		this.state = state;
		this.notify = notify || function () {};
		this.dialog = null;
		this.render();
		if ( state.intervals ) {
			this.loadIntervals();
		}
	}

	Widget.prototype.setState = function ( state ) {
		this.state = state;
		this.render();
		this.notify( state );
	};

	Widget.prototype.showStatus = function ( message ) {
		if ( this.status ) {
			this.status.textContent = message || '';
		}
	};

	Widget.prototype.render = function () {
		var self = this;
		var state = this.state;
		var root = this.root;
		while ( root.firstChild ) {
			root.removeChild( root.firstChild );
		}
		root.classList.add( 'dalli-widget' );

		if ( state.needsPvz ) {
			var box = el( 'div', { class: 'dalli-widget__pvz' } );
			if ( state.pvz ) {
				box.appendChild( el( 'strong', { text: t.pickupPoint + ': ' } ) );
				box.appendChild( el( 'span', { text: state.pvz.name } ) );
				box.appendChild( el( 'br' ) );
				box.appendChild( el( 'small', { text: state.pvz.address + ( state.pvz.schedule ? ' · ' + state.pvz.schedule : '' ) } ) );
			} else {
				box.appendChild( el( 'span', { class: 'dalli-widget__hint', text: t.noPointChosen } ) );
			}
			var button = el( 'button', { type: 'button', class: 'button dalli-widget__choose', text: state.pvz ? t.changePoint : t.choosePoint } );
			button.addEventListener( 'click', function () {
				self.openDialog( button );
			} );
			box.appendChild( el( 'div', { class: 'dalli-widget__actions' }, [ button ] ) );
			root.appendChild( box );
		}

		this.intervalBox = null;
		if ( state.intervals ) {
			this.intervalBox = el( 'div', { class: 'dalli-widget__intervals' } );
			root.appendChild( this.intervalBox );
			if ( this.intervalData ) {
				this.renderIntervals();
			}
		}

		this.status = el( 'div', { class: 'dalli-widget__status', role: 'status', 'aria-live': 'polite' } );
		root.appendChild( this.status );
	};

	/* ---------- Pickup point dialog ---------- */

	Widget.prototype.openDialog = function ( opener ) {
		var self = this;
		var dialog = el( 'dialog', { class: 'dalli-dialog', 'aria-labelledby': 'dalli-dialog-title' } );
		var title = el( 'h2', { id: 'dalli-dialog-title', class: 'dalli-dialog__title', text: t.modalTitle } );
		var close = el( 'button', { type: 'button', class: 'dalli-dialog__close', 'aria-label': t.close, text: '×' } );
		var search = el( 'input', { type: 'search', class: 'dalli-dialog__search', placeholder: t.search, 'aria-label': t.search, autocomplete: 'off' } );
		var count = el( 'p', { class: 'dalli-dialog__count', role: 'status', 'aria-live': 'polite', text: t.loading } );
		var list = el( 'ul', { class: 'dalli-dialog__list' } );
		dialog.appendChild( el( 'div', { class: 'dalli-dialog__header' }, [ title, close ] ) );
		dialog.appendChild( search );
		dialog.appendChild( count );
		dialog.appendChild( list );
		document.body.appendChild( dialog );

		function dispose() {
			if ( dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
			}
			if ( opener && document.body.contains( opener ) ) {
				opener.focus();
			}
		}
		close.addEventListener( 'click', function () {
			if ( typeof dialog.close === 'function' ) {
				dialog.close();
			} else {
				dispose();
			}
		} );
		dialog.addEventListener( 'close', dispose );
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				close.click();
			}
		} );

		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', 'open' );
		}
		search.focus();

		post( 'points', {} )
			.then( function ( data ) {
				title.textContent = format( t.modalTown, data.town );
				self.fillList( dialog, list, count, search, data.points, data.selected, close );
			} )
			.catch( function ( error ) {
				count.textContent = error.message;
			} );
	};

	Widget.prototype.fillList = function ( dialog, list, count, search, points, selected, close ) {
		var self = this;

		function haystack( point ) {
			return ( point.name + ' ' + point.address ).toLowerCase();
		}

		function draw() {
			var query = search.value.trim().toLowerCase();
			var matches = points.filter( function ( point ) {
				return ! query || haystack( point ).indexOf( query ) !== -1;
			} );
			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}
			if ( ! matches.length ) {
				count.textContent = t.nothingFound;
				return;
			}
			count.textContent = format( t.found, matches.length ) + ( matches.length > RENDER_LIMIT ? ' · ' + t.showingFirst : '' );
			matches.slice( 0, RENDER_LIMIT ).forEach( function ( point ) {
				var meta = [];
				if ( point.schedule ) {
					meta.push( point.schedule );
				}
				if ( point.locker ) {
					meta.push( t.locker );
				}
				if ( point.prepaidOnly ) {
					meta.push( t.prepaidOnly );
				}
				var chooseButton = el( 'button', {
					type: 'button',
					class: 'dalli-dialog__item' + ( point.code === selected ? ' is-selected' : '' ),
					'aria-pressed': point.code === selected ? 'true' : 'false',
				}, [
					el( 'span', { class: 'dalli-dialog__item-name', text: point.name } ),
					el( 'span', { class: 'dalli-dialog__item-address', text: point.address } ),
					meta.length ? el( 'span', { class: 'dalli-dialog__item-meta', text: meta.join( ' · ' ) } ) : null,
					point.description ? el( 'span', { class: 'dalli-dialog__item-desc', text: point.description } ) : null,
				] );
				chooseButton.addEventListener( 'click', function () {
					chooseButton.disabled = true;
					post( 'select_point', { code: point.code } )
						.then( function ( state ) {
							close.click();
							self.setState( state );
						} )
						.catch( function ( error ) {
							chooseButton.disabled = false;
							count.textContent = error.message;
						} );
				} );
				list.appendChild( el( 'li', {}, [ chooseButton ] ) );
			} );
		}

		search.addEventListener( 'input', draw );
		draw();
	};

	/* ---------- Delivery date / interval ---------- */

	Widget.prototype.loadIntervals = function () {
		var self = this;
		post( 'intervals', {} )
			.then( function ( data ) {
				self.intervalData = data;
				self.renderIntervals();
			} )
			.catch( function () {
				// Intervals are optional: without them the default interval is used.
				self.intervalData = { dates: {}, selected: null };
				self.renderIntervals();
			} );
	};

	Widget.prototype.renderIntervals = function () {
		var self = this;
		var box = this.intervalBox;
		if ( ! box ) {
			return;
		}
		while ( box.firstChild ) {
			box.removeChild( box.firstChild );
		}
		var dates = Object.keys( this.intervalData.dates || {} ).sort();
		if ( ! dates.length ) {
			return;
		}
		var selected = this.intervalData.selected || this.state.interval;

		var dateSelect = el( 'select', { id: 'dalli-date', class: 'dalli-widget__select' } );
		dateSelect.appendChild( el( 'option', { value: '', text: t.earliest } ) );
		dates.forEach( function ( date ) {
			var option = el( 'option', { value: date, text: self.formatDate( date ) } );
			if ( selected && selected.date === date ) {
				option.selected = true;
			}
			dateSelect.appendChild( option );
		} );

		var timeSelect = el( 'select', { id: 'dalli-time', class: 'dalli-widget__select' } );

		function fillTimes() {
			while ( timeSelect.firstChild ) {
				timeSelect.removeChild( timeSelect.firstChild );
			}
			var date = dateSelect.value;
			timeSelect.disabled = ! date;
			if ( ! date ) {
				return;
			}
			( self.intervalData.dates[ date ] || [] ).forEach( function ( interval ) {
				var value = interval.min + '|' + interval.max;
				var option = el( 'option', { value: value, text: interval.min + ' – ' + interval.max } );
				if ( selected && selected.date === date && selected.min === interval.min && selected.max === interval.max ) {
					option.selected = true;
				}
				timeSelect.appendChild( option );
			} );
		}

		function save() {
			var date = dateSelect.value;
			var parts = ( timeSelect.value || '|' ).split( '|' );
			self.showStatus( '' );
			post( 'select_interval', { date: date, min: date ? parts[ 0 ] : '', max: date ? parts[ 1 ] : '' } ).catch( function ( error ) {
				self.showStatus( error.message );
			} );
		}

		dateSelect.addEventListener( 'change', function () {
			selected = null;
			fillTimes();
			save();
		} );
		timeSelect.addEventListener( 'change', save );
		fillTimes();

		box.appendChild( el( 'label', { for: 'dalli-date', text: t.deliveryDate } ) );
		box.appendChild( dateSelect );
		box.appendChild( el( 'label', { for: 'dalli-time', text: t.deliveryInterval } ) );
		box.appendChild( timeSelect );
	};

	Widget.prototype.formatDate = function ( value ) {
		var parts = value.split( '-' );
		var date = new Date( parseInt( parts[ 0 ], 10 ), parseInt( parts[ 1 ], 10 ) - 1, parseInt( parts[ 2 ], 10 ) );
		try {
			return date.toLocaleDateString( document.documentElement.lang || undefined, { weekday: 'short', day: 'numeric', month: 'long' } );
		} catch ( e ) {
			return value;
		}
	};

	window.DalliWidget = {
		/**
		 * Mount a widget into a container.
		 *
		 * @param {Element}  root   Container.
		 * @param {Object}   state  Initial state from the server.
		 * @param {Function} notify Called with the new state after a selection.
		 * @return {Widget}
		 */
		mount: function ( root, state, notify ) {
			return new Widget( root, state, notify );
		},
		/**
		 * Mount widgets on all server-rendered containers inside a scope.
		 *
		 * @param {Element} scope Scope.
		 */
		init: function ( scope ) {
			var nodes = ( scope || document ).querySelectorAll( '[data-dalli-widget]:not([data-dalli-ready])' );
			Array.prototype.forEach.call( nodes, function ( node ) {
				var state;
				try {
					state = JSON.parse( node.getAttribute( 'data-state' ) );
				} catch ( e ) {
					return;
				}
				node.setAttribute( 'data-dalli-ready', '1' );
				new Widget( node, state );
			} );
		},
		post: post,
		state: function () {
			return post( 'state', {} );
		},
	};
}( window, document ) );
