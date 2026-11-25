/*!
This file is part of Live Carts for WooCommerce. For copyright and licensing information, please see ../../license/license.txt
*/

( function () {
	'use strict';

	var config = window.phplugins_live_carts_email_tracking;
	if ( ! config || ! config.selector || ! config.ajaxUrl ) {
		return;
	}

	var lastSentCartEmail = null, cartEmail = null, cartEmailSendInProgress = false;

	window.live_carts_update_cart_email = function( email ) {
		if ( email === lastSentCartEmail ) {
			return;
		}
		
		cartEmail = email;
		
		if ( cartEmailSendInProgress ) {
			return;
		}
		
		cartEmailSendInProgress = true;

		var body = 'action=' + encodeURIComponent( config.action || 'phplugins_carts_save_visitor_email' ) +
			'&nonce=' + encodeURIComponent( config.nonce || '' ) +
			'&email=' + encodeURIComponent( email );

		try {
			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			} ).then( function(emailSaveResponse) {
				return emailSaveResponse.ok ? emailSaveResponse.json() : Promise.reject(null);
			} ).then(
				function(emailSaveResponse) {
					lastSentCartEmail = emailSaveResponse.success ? email : null;
				},
				function() {
					lastSentCartEmail = null;
				}
			).finally( function() {
				cartEmailSendInProgress = false;
				
				if ( email !== cartEmail ) {
					live_carts_update_cart_email(cartEmail);
				}
			} );
			
		} catch ( e ) {
			cartEmailSendInProgress = false;
		}
		
	}

	function matches( el, selector ) {
		if ( ! el || el.nodeType !== 1 ) {
			return false;
		}
		var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
		return fn ? fn.call( el, selector ) : false;
	}

	function onFieldEvent( event ) {
		if ( ! matches( event.target, config.selector ) ) {
			return;
		}
		live_carts_update_cart_email( event.target.value || '' );
	}

	// Capture phase so this also catches "blur", which doesn't bubble.
	document.addEventListener( 'change', onFieldEvent, true );
	document.addEventListener( 'blur', onFieldEvent, true );
} )();
