/* global TackQuotes, jQuery */
( function ( $ ) {
	'use strict';

	var modal = null;
	var STORAGE_KEY = 'tack_quote_list';

	// ─── Quote list (browser-side, separate from the WooCommerce cart) ───────

	function getList() {
		try {
			var raw = localStorage.getItem( STORAGE_KEY );
			return raw ? JSON.parse( raw ) : [];
		} catch ( e ) {
			return [];
		}
	}

	function saveList( list ) {
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( list ) );
		} catch ( e ) {
			// Ignore — worst case the list doesn't persist across reloads.
		}
		renderList( list );
	}

	function addToList( item ) {
		var list = getList();
		var existing = null;
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].productId === item.productId ) {
				existing = list[ i ];
				break;
			}
		}
		if ( existing ) {
			existing.quantity += item.quantity;
		} else {
			list.push( item );
		}
		saveList( list );
	}

	function removeFromList( productId ) {
		var list = getList().filter( function ( row ) {
			return row.productId !== productId;
		} );
		saveList( list );
	}

	function clearList() {
		saveList( [] );
	}

	// ─── Floating quote-list widget (button + drawer) ────────────────────────

	function renderList( list ) {
		var $widget = $( '#tack-quote-list-widget' );
		if ( ! $widget.length ) {
			return;
		}

		$widget.prop( 'hidden', list.length === 0 );
		$( '#tack-quote-list-count' ).text( list.length );

		var $items = $( '#tack-quote-list-items' ).empty();
		list.forEach( function ( row ) {
			var $li = $( '<li class="tack-quote-list-item"></li>' );
			$li.append( $( '<span class="tack-quote-list-item-name"></span>' ).text( row.name ) );
			$li.append( $( '<span class="tack-quote-list-item-qty"></span>' ).text( '×' + row.quantity ) );
			var $remove = $( '<button type="button" class="tack-quote-list-item-remove" aria-label="' + escapeHtml( TackQuotes.i18n.remove ) + '">&times;</button>' );
			$remove.on( 'click', function () {
				removeFromList( row.productId );
			} );
			$li.append( $remove );
			$items.append( $li );
		} );

		$( '#tack-quote-list-checkout' ).prop( 'disabled', list.length === 0 );
	}

	function escapeHtml( str ) {
		return String( str == null ? '' : str ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// ─── Request-a-quote modal (single product or the whole quote list) ──────

	function buildModal() {
		var i18n = TackQuotes.i18n;

		var overlay = document.createElement( 'div' );
		overlay.className = 'tack-quote-modal-overlay';
		overlay.setAttribute( 'hidden', 'hidden' );

		overlay.innerHTML =
			'<div class="tack-quote-modal" role="dialog" aria-modal="true" aria-labelledby="tack-quote-modal-title">' +
			'<button type="button" class="tack-quote-modal-close" aria-label="' + escapeHtml( i18n.close ) + '">&times;</button>' +
			'<h2 id="tack-quote-modal-title">' + escapeHtml( i18n.modalTitle ) + '</h2>' +
			'<form class="tack-quote-modal-form" novalidate>' +
			'<div class="tack-quote-field">' +
			'<label for="tack-quote-email">' + escapeHtml( i18n.emailLabel ) + '</label>' +
			'<input type="email" id="tack-quote-email" name="email" placeholder="' + escapeHtml( i18n.emailPlaceholder ) + '" required />' +
			'</div>' +
			'<div class="tack-quote-field">' +
			'<label for="tack-quote-note">' + escapeHtml( i18n.noteLabel ) + '</label>' +
			'<textarea id="tack-quote-note" name="note" rows="3" placeholder="' + escapeHtml( i18n.notePlaceholder ) + '"></textarea>' +
			'</div>' +
			'<p class="tack-quote-modal-error" hidden></p>' +
			'<p class="tack-quote-modal-success" hidden></p>' +
			'<div class="tack-quote-modal-actions">' +
			'<button type="button" class="tack-quote-modal-cancel">' + escapeHtml( i18n.cancel ) + '</button>' +
			'<button type="submit" class="tack-quote-modal-submit">' + escapeHtml( i18n.submit ) + '</button>' +
			'</div>' +
			'</form>' +
			'</div>';

		document.body.appendChild( overlay );
		return overlay;
	}

	function openModal( context ) {
		if ( ! modal ) {
			modal = buildModal();
		}

		var $overlay = $( modal );
		var $form = $overlay.find( 'form' );
		var $email = $overlay.find( '#tack-quote-email' );
		var $note = $overlay.find( '#tack-quote-note' );
		var $error = $overlay.find( '.tack-quote-modal-error' );
		var $success = $overlay.find( '.tack-quote-modal-success' );
		var $submit = $overlay.find( '.tack-quote-modal-submit' );

		$form[ 0 ].reset();
		$email.val( TackQuotes.customerEmail || '' );
		$note.val( '' );
		$error.hide().text( '' );
		$success.hide().text( '' );
		$submit.prop( 'disabled', false ).text( TackQuotes.i18n.submit );
		$form.show();

		modal.removeAttribute( 'hidden' );
		document.body.classList.add( 'tack-quote-modal-open' );
		( $email.val() ? $overlay.find( '.tack-quote-modal-submit' ) : $email ).trigger( 'focus' );

		$form.off( 'submit' ).on( 'submit', function ( e ) {
			e.preventDefault();
			submitRequest( context, $overlay, $email.val().trim(), $note.val().trim() );
		} );
	}

	function closeModal() {
		if ( ! modal ) {
			return;
		}
		modal.setAttribute( 'hidden', 'hidden' );
		document.body.classList.remove( 'tack-quote-modal-open' );
	}

	function submitRequest( context, $overlay, email, note ) {
		var $error = $overlay.find( '.tack-quote-modal-error' );
		var $success = $overlay.find( '.tack-quote-modal-success' );
		var $submit = $overlay.find( '.tack-quote-modal-submit' );
		var $form = $overlay.find( 'form' );

		$error.hide().text( '' );

		var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		if ( ! email || ! emailPattern.test( email ) ) {
			$error.text( TackQuotes.i18n.emailRequired ).show();
			return;
		}

		var payload = {
			action: 'tack_request_quote',
			nonce: TackQuotes.nonce,
			email: email,
			note: note
		};

		if ( context.items ) {
			// "Checkout as Quote" from the quote-list drawer — the server
			// re-derives name/SKU/price from each product_id; quantity is the
			// only other value trusted from the client.
			payload.items = JSON.stringify( context.items.map( function ( row ) {
				return { product_id: row.productId, quantity: row.quantity };
			} ) );
		} else {
			// "Request a Quote" — a single product, submitted immediately.
			payload.product_id = context.productId || 0;
			payload.quantity = $( 'input.qty' ).val() || 1;
		}

		$submit.prop( 'disabled', true ).text( TackQuotes.i18n.sending );

		$.post( TackQuotes.ajaxUrl, payload )
			.done( function ( res ) {
				if ( res && res.success ) {
					$form.hide();
					$success.text( TackQuotes.i18n.success ).show();
					if ( context.items ) {
						clearList();
					}
					var portalUrl = res.data && res.data.portalUrl;
					if ( portalUrl ) {
						window.setTimeout( function () {
							window.location.href = portalUrl;
						}, 900 );
					}
				} else {
					$error.text( ( res && res.data && res.data.message ) || TackQuotes.i18n.error ).show();
					$submit.prop( 'disabled', false ).text( TackQuotes.i18n.submit );
				}
			} )
			.fail( function ( xhr ) {
				var msg = TackQuotes.i18n.error;
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
					msg = xhr.responseJSON.data.message;
				}
				$error.text( msg ).show();
				$submit.prop( 'disabled', false ).text( TackQuotes.i18n.submit );
			} );
	}

	// ─── Event wiring ──────────────────────────────────────────────────────────

	// "Request a Quote" (product page) — single product, immediate.
	$( document ).on( 'click', '.tack-quote-btn', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		openModal( { productId: $btn.data( 'product-id' ) || 0 } );
	} );

	// "Add to Quote" (product page) — adds to the browser-side quote list.
	// Never touches the WooCommerce cart, so it can't affect stock, cart
	// totals, or normal checkout.
	$( document ).on( 'click', '.tack-add-to-quote-btn', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var quantity = Number( $( 'input.qty' ).val() ) || 1;

		addToList( {
			productId: $btn.data( 'product-id' ) || 0,
			name: $btn.data( 'product-name' ) || '',
			sku: $btn.data( 'product-sku' ) || '',
			price: Number( $btn.data( 'product-price' ) ) || 0,
			quantity: quantity
		} );

		var original = $btn.text();
		$btn.text( TackQuotes.i18n.added );
		window.setTimeout( function () {
			$btn.text( original );
		}, 1200 );
	} );

	// Floating quote-list widget.
	$( document ).on( 'click', '#tack-quote-list-toggle', function () {
		$( '#tack-quote-list-drawer' ).prop( 'hidden', false );
	} );

	$( document ).on( 'click', '#tack-quote-list-close', function () {
		$( '#tack-quote-list-drawer' ).prop( 'hidden', true );
	} );

	// "Checkout as Quote" — submits the whole quote list.
	$( document ).on( 'click', '#tack-quote-list-checkout', function () {
		var list = getList();
		if ( ! list.length ) {
			return;
		}
		openModal( { items: list } );
	} );

	$( document ).on( 'click', '.tack-quote-modal-overlay', function ( e ) {
		if ( e.target === this ) {
			closeModal();
		}
	} );

	$( document ).on( 'click', '.tack-quote-modal-close, .tack-quote-modal-cancel', function ( e ) {
		e.preventDefault();
		closeModal();
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && modal && ! modal.hasAttribute( 'hidden' ) ) {
			closeModal();
		}
	} );

	// Initial render on page load (in case the list was populated earlier).
	$( function () {
		renderList( getList() );
	} );
}( jQuery ) );
