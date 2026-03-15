/* global wmnData, jQuery */
( function ( $ ) {
	'use strict';

	var debounceTimer = null;
	var $wrap, $toggle, $body, $input, $checkBtn, $result, $hidden;

	function init() {
		$wrap     = $( '#wmn-chosen-number-wrap' );
		$toggle   = $wrap.find( '.wmn-chosen-toggle' );
		$body     = $wrap.find( '#wmn-chosen-number-body' );
		$input    = $wrap.find( '#wmn_chosen_input' );
		$checkBtn = $wrap.find( '#wmn-check-btn' );
		$result   = $wrap.find( '#wmn-check-result' );
		$hidden   = $wrap.find( '#wmn_chosen_number' );

		if ( ! $wrap.length ) return;

		// Toggle visibility.
		$toggle.on( 'click', function () {
			$body.slideToggle( 200 );
			$toggle.find( '.wmn-toggle-arrow' ).text( $body.is( ':hidden' ) ? '\u25B6' : '\u25BC' );
		} );

		// Check on button click.
		$checkBtn.on( 'click', function () {
			checkAvailability();
		} );

		// Debounce on input.
		$input.on( 'input', function () {
			clearTimeout( debounceTimer );
			setResult( '', '' );
			debounceTimer = setTimeout( function () {
				if ( $input.val().trim().length >= 1 ) {
					checkAvailability();
				}
			}, 500 );
		} );

		// Release reservation on page unload if order not completed.
		$( window ).on( 'beforeunload', function () {
			if ( $hidden.val() ) {
				var data = new FormData();
				data.append( 'action', 'wmn_release_reservation' );
				data.append( 'nonce', wmnData.nonce );
				navigator.sendBeacon( wmnData.ajaxUrl, data );
			}
		} );

		// On checkout form submission, clear beforeunload guard.
		$( 'form.checkout' ).on( 'checkout_place_order', function () {
			$( window ).off( 'beforeunload' );
		} );

		// If WC reloads checkout fragments, restore display.
		$( document.body ).on( 'updated_checkout', function () {
			if ( $hidden.val() ) {
				setResult( 'success', wmnData.label + ' ' + $hidden.val() + ' is reserved.' );
			}
		} );
	}

	function checkAvailability() {
		var number = $input.val().trim();
		if ( ! number ) return;

		setResult( 'loading', '' );
		$checkBtn.prop( 'disabled', true );

		$.post( wmnData.ajaxUrl, {
			action: 'wmn_check_number',
			nonce:  wmnData.nonce,
			number: number,
		} )
		.done( function ( response ) {
			if ( response.success ) {
				$hidden.val( response.data.number );
				setResult( 'success', response.data.message );
				$( document.body ).trigger( 'update_checkout' );
			} else {
				$hidden.val( '' );
				setResult( 'error', response.data && response.data.reason ? response.data.reason : 'Not available.' );
				$( document.body ).trigger( 'update_checkout' );
			}
		} )
		.fail( function () {
			setResult( 'error', 'An error occurred. Please try again.' );
		} )
		.always( function () {
			$checkBtn.prop( 'disabled', false );
		} );
	}

	function setResult( type, message ) {
		$result.removeClass( 'wmn-result-success wmn-result-error wmn-result-loading' ).html( '' );
		if ( 'loading' === type ) {
			$result.addClass( 'wmn-result-loading' ).html( '<span class="spinner is-active" style="float:none;"></span>' );
		} else if ( 'success' === type ) {
			$result.addClass( 'wmn-result-success' ).html( '\u2713 ' + message );
		} else if ( 'error' === type ) {
			$result.addClass( 'wmn-result-error' ).html( '\u2717 ' + message );
		}
	}

	$( document ).ready( init );

} )( jQuery );
