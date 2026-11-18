/**
 * Kurvio Courier Connector for Dalli - checkout widget.
 *
 * Renders the pickup point chooser (accessible modal with search) and the delivery date/time selects.
 * Shared by the classic checkout and the Checkout block. No dependencies. Network calls go to this site's own
 * admin-ajax.php endpoints; the optional pickup point map additionally loads map tiles from OpenStreetMap or
 * (when enabled with an API key) the Yandex Maps API.
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
				box.appendChild( el( 'span', { class: 'dalli-widget__hint', text: state.town === '' ? t.enterCity : t.noPointChosen } ) );
			}
			var button = el( 'button', { type: 'button', class: 'button dalli-widget__choose', text: state.pvz ? t.changePoint : t.choosePoint } );
			if ( state.town === '' ) {
				// The points are searched by the city, so the chooser stays inactive until the city is entered.
				button.disabled = true;
			}
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

	/* ---------- Map (OpenStreetMap via Leaflet, or Yandex Maps) ---------- */

	var assetPromises = {};

	function loadScript( url ) {
		if ( ! assetPromises[ url ] ) {
			assetPromises[ url ] = new Promise( function ( resolve, reject ) {
				var script = document.createElement( 'script' );
				script.src = url;
				script.async = true;
				script.onload = resolve;
				script.onerror = function () {
					delete assetPromises[ url ];
					reject( new Error( 'Cannot load ' + url ) );
				};
				document.head.appendChild( script );
			} );
		}
		return assetPromises[ url ];
	}

	function loadStyle( url ) {
		if ( ! assetPromises[ url ] ) {
			assetPromises[ url ] = new Promise( function ( resolve, reject ) {
				var link = document.createElement( 'link' );
				link.rel = 'stylesheet';
				link.href = url;
				link.onload = resolve;
				link.onerror = function () {
					delete assetPromises[ url ];
					reject( new Error( 'Cannot load ' + url ) );
				};
				document.head.appendChild( link );
			} );
		}
		return assetPromises[ url ];
	}

	var MAP_FALLBACK_CENTER = [ 55.75, 37.62 ];

	/**
	 * Leaflet adapter. Libraries are bundled with the plugin and loaded only when the dialog opens;
	 * the only third-party request is for the map tiles.
	 */
	function leafletAdapter( container ) {
		var mapCfg = cfg.map || {};
		var base = mapCfg.assetsUrl;
		var ver = '?ver=' + encodeURIComponent( mapCfg.version || '' );
		return Promise.all( [
			loadStyle( base + 'leaflet/leaflet.css' + ver ),
			loadStyle( base + 'markercluster/MarkerCluster.css' + ver ),
			loadStyle( base + 'markercluster/MarkerCluster.Default.css' + ver ),
			loadScript( base + 'leaflet/leaflet.js' + ver ),
		] )
			.then( function () {
				return loadScript( base + 'markercluster/leaflet.markercluster.js' + ver );
			} )
			.then( function () {
				var L = window.L;
				L.Icon.Default.prototype.options.imagePath = base + 'leaflet/images/';
				var map = L.map( container, { zoomControl: true } );
				L.tileLayer( mapCfg.tileUrl, { maxZoom: 19, attribution: mapCfg.attribution || '' } ).addTo( map );
				var cluster = L.markerClusterGroup( { showCoverageOnHover: false, maxClusterRadius: 50 } );
				map.addLayer( cluster );
				map.setView( MAP_FALLBACK_CENTER, 9 );
				var markers = {};
				var active = null;
				return {
					setPoints: function ( points, onPick ) {
						cluster.clearLayers();
						markers = {};
						var bounds = [];
						points.forEach( function ( point ) {
							if ( point.lat === null || point.lng === null || point.lat === undefined || point.lng === undefined ) {
								return;
							}
							var marker = L.marker( [ point.lat, point.lng ], { title: point.name, alt: point.name, keyboard: false } );
							marker.on( 'click', function () {
								onPick( point );
							} );
							markers[ point.code ] = marker;
							cluster.addLayer( marker );
							bounds.push( [ point.lat, point.lng ] );
						} );
						if ( bounds.length ) {
							map.fitBounds( bounds, { padding: [ 20, 20 ], maxZoom: 15 } );
						}
					},
					focus: function ( point ) {
						var marker = markers[ point.code ];
						if ( active ) {
							active.setZIndexOffset( 0 );
						}
						if ( ! marker ) {
							return;
						}
						active = marker;
						marker.setZIndexOffset( 1000 );
						cluster.zoomToShowLayer( marker, function () {
							map.setView( marker.getLatLng(), Math.max( map.getZoom(), 15 ) );
						} );
					},
					resize: function () {
						map.invalidateSize();
					},
					destroy: function () {
						map.remove();
					},
				};
			} );
	}

	/**
	 * Yandex Maps JavaScript API 2.1 adapter (needs an API key from the store settings).
	 */
	function yandexAdapter( container ) {
		var mapCfg = cfg.map || {};
		if ( ! mapCfg.yandexKey ) {
			return Promise.reject( new Error( 'No Yandex Maps key' ) );
		}
		var langs = [ 'ru_RU', 'en_US', 'uk_UA', 'tr_TR' ];
		var lang = langs.indexOf( mapCfg.lang ) !== -1 ? mapCfg.lang : 'ru_RU';
		var url = 'https://api-maps.yandex.ru/2.1/?apikey=' + encodeURIComponent( mapCfg.yandexKey ) + '&lang=' + lang;
		return loadScript( url )
			.then( function () {
				return new Promise( function ( resolve, reject ) {
					if ( ! window.ymaps || typeof window.ymaps.ready !== 'function' ) {
						reject( new Error( 'ymaps is not available' ) );
						return;
					}
					var timer = window.setTimeout( function () {
						reject( new Error( 'ymaps timeout' ) );
					}, 10000 );
					window.ymaps.ready( function () {
						window.clearTimeout( timer );
						resolve( window.ymaps );
					}, reject );
				} );
			} )
			.then( function ( ymaps ) {
				var map = new ymaps.Map( container, { center: MAP_FALLBACK_CENTER, zoom: 9, controls: [ 'zoomControl' ] }, { suppressMapOpenBlock: true } );
				var clusterer = new ymaps.Clusterer( { preset: 'islands#invertedDarkBlueClusterIcons', groupByCoordinates: false } );
				map.geoObjects.add( clusterer );
				var marks = {};
				return {
					setPoints: function ( points, onPick ) {
						clusterer.removeAll();
						marks = {};
						var list = [];
						points.forEach( function ( point ) {
							if ( point.lat === null || point.lng === null || point.lat === undefined || point.lng === undefined ) {
								return;
							}
							var mark = new ymaps.Placemark( [ point.lat, point.lng ], { hintContent: point.name }, { preset: 'islands#darkBlueDotIcon' } );
							mark.events.add( 'click', function () {
								onPick( point );
							} );
							marks[ point.code ] = mark;
							list.push( mark );
						} );
						clusterer.add( list );
						if ( list.length ) {
							var bounds = clusterer.getBounds();
							if ( bounds ) {
								map.setBounds( bounds, { checkZoomRange: true, zoomMargin: 30 } );
							}
						}
					},
					focus: function ( point ) {
						if ( ! marks[ point.code ] ) {
							return;
						}
						map.setCenter( [ point.lat, point.lng ], Math.max( map.getZoom(), 15 ), { duration: 200 } );
					},
					resize: function () {
						map.container.fitToViewport();
					},
					destroy: function () {
						map.destroy();
					},
				};
			} );
	}

	/** Create the map for the configured provider; Yandex falls back to OpenStreetMap when it cannot be loaded. */
	function createMap( container ) {
		var provider = ( cfg.map || {} ).provider;
		if ( 'yandex' === provider ) {
			return yandexAdapter( container ).catch( function () {
				while ( container.firstChild ) {
					container.removeChild( container.firstChild );
				}
				return leafletAdapter( container );
			} );
		}
		if ( 'leaflet' === provider ) {
			return leafletAdapter( container );
		}
		return Promise.reject( new Error( 'Map disabled' ) );
	}

	/* ---------- Pickup point dialog ---------- */

	Widget.prototype.openDialog = function ( opener ) {
		var self = this;
		var hasMap = !! ( cfg.map && ( 'leaflet' === cfg.map.provider || 'yandex' === cfg.map.provider ) );
		var dialog = el( 'dialog', { class: 'dalli-dialog' + ( hasMap ? ' dalli-dialog--map' : '' ), 'aria-labelledby': 'dalli-dialog-title' } );
		var title = el( 'h2', { id: 'dalli-dialog-title', class: 'dalli-dialog__title', text: t.modalTitle } );
		var close = el( 'button', { type: 'button', class: 'dalli-dialog__close', 'aria-label': t.close, text: '×' } );
		var search = el( 'input', { type: 'search', class: 'dalli-dialog__search', placeholder: t.search, 'aria-label': t.search, autocomplete: 'off' } );
		var count = el( 'p', { class: 'dalli-dialog__count', role: 'status', 'aria-live': 'polite', text: t.loading } );
		var list = el( 'ul', { class: 'dalli-dialog__list' } );
		var listPane = el( 'div', { class: 'dalli-dialog__pane dalli-dialog__pane--list' }, [ search, count, list ] );
		var body = el( 'div', { class: 'dalli-dialog__body' }, [ listPane ] );
		var mapApi = null;
		var mapBox = null;
		var mapNote = null;
		var ui = { onMapReady: null, detail: null };

		if ( hasMap ) {
			var mapEl = el( 'div', { class: 'dalli-dialog__map', 'aria-label': t.showOnMap } );
			mapNote = el( 'p', { class: 'dalli-dialog__map-note', text: t.mapLoading } );
			ui.detail = el( 'div', { class: 'dalli-dialog__detail', 'aria-live': 'polite' } );
			mapBox = el( 'div', { class: 'dalli-dialog__pane dalli-dialog__pane--map' }, [ mapEl, mapNote, ui.detail ] );
			body.appendChild( mapBox );
			ui.mapEl = mapEl;
		}

		dialog.appendChild( el( 'div', { class: 'dalli-dialog__header' }, [ title, close ] ) );
		dialog.appendChild( body );
		document.body.appendChild( dialog );

		function dispose() {
			if ( mapApi ) {
				try {
					mapApi.destroy();
				} catch ( e ) {
					// The map is already gone.
				}
				mapApi = null;
			}
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

		if ( hasMap ) {
			createMap( ui.mapEl )
				.then( function ( api ) {
					if ( ! dialog.parentNode ) {
						api.destroy();
						return;
					}
					mapApi = api;
					mapNote.hidden = true;
					api.resize();
					if ( ui.onMapReady ) {
						ui.onMapReady( api );
					}
				} )
				.catch( function () {
					// The map is optional: fall back to the plain list.
					if ( mapBox && mapBox.parentNode ) {
						mapBox.parentNode.removeChild( mapBox );
					}
					dialog.classList.remove( 'dalli-dialog--map' );
					ui.detail = null;
					ui.failed = true;
					count.textContent += ' · ' + t.mapUnavailable;
				} );
		}

		post( 'points', {} )
			.then( function ( data ) {
				title.textContent = format( t.modalTown, data.town );
				self.fillList( dialog, list, count, search, data.points, data.selected, close, ui, function () {
					return mapApi;
				} );
			} )
			.catch( function ( error ) {
				count.textContent = error.message;
			} );
	};

	Widget.prototype.fillList = function ( dialog, list, count, search, points, selected, close, ui, getMap ) {
		var self = this;
		var lastMatches = points;
		var currentItem = null;

		function haystack( point ) {
			return ( point.name + ' ' + point.address ).toLowerCase();
		}

		function choose( point, button ) {
			button.disabled = true;
			post( 'select_point', { code: point.code } )
				.then( function ( state ) {
					close.click();
					self.setState( state );
				} )
				.catch( function ( error ) {
					button.disabled = false;
					count.textContent = error.message;
				} );
		}

		function metaOf( point ) {
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
			return meta;
		}

		/** With a map the first click only shows the point (map and card); the card button confirms the choice. */
		function showPoint( point, item ) {
			if ( currentItem ) {
				currentItem.classList.remove( 'is-active' );
			}
			currentItem = item || null;
			if ( currentItem ) {
				currentItem.classList.add( 'is-active' );
			}
			var map = getMap();
			if ( map ) {
				map.focus( point );
			}
			var box = ui.detail;
			if ( ! box ) {
				return;
			}
			while ( box.firstChild ) {
				box.removeChild( box.firstChild );
			}
			var meta = metaOf( point );
			var confirm = el( 'button', { type: 'button', class: 'button dalli-dialog__confirm', text: t.chooseThisPoint } );
			confirm.addEventListener( 'click', function () {
				choose( point, confirm );
			} );
			[
				el( 'strong', { class: 'dalli-dialog__item-name', text: point.name } ),
				el( 'span', { class: 'dalli-dialog__item-address', text: point.address } ),
				meta.length ? el( 'span', { class: 'dalli-dialog__item-meta', text: meta.join( ' · ' ) } ) : null,
				point.phone ? el( 'span', { class: 'dalli-dialog__item-meta', text: point.phone } ) : null,
				point.description ? el( 'span', { class: 'dalli-dialog__item-desc', text: point.description } ) : null,
				confirm,
			].forEach( function ( node ) {
				if ( node ) {
					box.appendChild( node );
				}
			} );
		}

		function pickOnMap( point ) {
			var item = list.querySelector( '[data-code="' + String( point.code ).replace( /"/g, '' ) + '"]' );
			if ( ! item ) {
				// The point is beyond the rendered part of a long list: search for it so it appears.
				search.value = point.name;
				draw();
				item = list.querySelector( '[data-code="' + String( point.code ).replace( /"/g, '' ) + '"]' );
			}
			showPoint( point, item );
			if ( item && item.scrollIntoView ) {
				item.scrollIntoView( { block: 'nearest' } );
			}
		}

		function draw() {
			var query = search.value.trim().toLowerCase();
			var matches = points.filter( function ( point ) {
				return ! query || haystack( point ).indexOf( query ) !== -1;
			} );
			lastMatches = matches;
			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}
			currentItem = null;
			var map = getMap();
			if ( map ) {
				map.setPoints( matches, pickOnMap );
			}
			if ( ! matches.length ) {
				count.textContent = t.nothingFound;
				return;
			}
			count.textContent = format( t.found, matches.length ) + ( matches.length > RENDER_LIMIT ? ' · ' + t.showingFirst : '' );
			matches.slice( 0, RENDER_LIMIT ).forEach( function ( point ) {
				var meta = metaOf( point );
				var chooseButton = el( 'button', {
					type: 'button',
					class: 'dalli-dialog__item' + ( point.code === selected ? ' is-selected' : '' ),
					'aria-pressed': point.code === selected ? 'true' : 'false',
					'data-code': point.code,
				}, [
					el( 'span', { class: 'dalli-dialog__item-name', text: point.name } ),
					el( 'span', { class: 'dalli-dialog__item-address', text: point.address } ),
					meta.length ? el( 'span', { class: 'dalli-dialog__item-meta', text: meta.join( ' · ' ) } ) : null,
					point.description ? el( 'span', { class: 'dalli-dialog__item-desc', text: point.description } ) : null,
				] );
				chooseButton.addEventListener( 'click', function () {
					if ( ui.detail && getMap() ) {
						showPoint( point, chooseButton );
					} else {
						choose( point, chooseButton );
					}
				} );
				list.appendChild( el( 'li', {}, [ chooseButton ] ) );
			} );
		}

		// The map may finish loading before or after the list: draw the current matches once it is ready.
		ui.onMapReady = function ( map ) {
			map.setPoints( lastMatches, pickOnMap );
		};
		search.addEventListener( 'input', draw );
		draw();
		if ( ui.failed ) {
			count.textContent += ' · ' + t.mapUnavailable;
		}
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
		var required = !! ( window.dalliDeliveryCheckout && window.dalliDeliveryCheckout.requireInterval );
		if ( ! required ) {
			dateSelect.appendChild( el( 'option', { value: '', text: t.earliest } ) );
		}
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
		var autoSave = false;
		if ( required && ! ( selected && dates.indexOf( selected.date ) !== -1 ) ) {
			dateSelect.value = dates[ 0 ];
			selected = null;
			autoSave = true;
		}
		fillTimes();
		if ( autoSave ) {
			save();
		}

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
