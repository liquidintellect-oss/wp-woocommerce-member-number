/* global wmnAdmin, jQuery */
( function ( $ ) {
	'use strict';

	// ── Live format preview ───────────────────────────────────────────────────

	var previewTimer = null;

	function updatePreview() {
		var $preview = $( '#wmn-format-preview' );
		var $loading = $( '#wmn-format-preview-loading' );
		if ( ! $preview.length ) return;

		$loading.show();
		clearTimeout( previewTimer );
		previewTimer = setTimeout( function () {
			$.post(
				wmnAdmin.ajaxUrl,
				{
					action:     'wmn_format_preview',
					nonce:      wmnAdmin.nonce,
					template:   $( '#wmn_number_format_template' ).val() || '{PREFIX}{SEQ}',
					prefix:     $( '#wmn_number_prefix' ).val() || 'MBR-',
					pad_length: $( '#wmn_number_pad_length' ).val() || 6,
					start:      $( '#wmn_number_start' ).val() || 1,
				},
				function ( response ) {
					$loading.hide();
					if ( response.success ) {
						$preview.text( response.data.preview );
					}
				}
			);
		}, 400 );
	}

	// Bind to format-related fields.
	$( document ).on(
		'input change',
		'#wmn_number_format_template, #wmn_number_prefix, #wmn_number_pad_length, #wmn_number_start',
		updatePreview
	);

	// ── Member number mask ───────────────────────────────────────────────────

	/**
	 * Format a sequence integer into a full member number string using the
	 * configured prefix and zero-pad length (mirrors PHP WMN_Number_Formatter).
	 *
	 * @param {string} digits Raw digit string entered by the admin.
	 * @return {string} Fully formatted member number, or empty string.
	 */
	function formatMemberNumber( digits ) {
		if ( ! digits ) return '';
		var n = parseInt( digits, 10 );
		if ( isNaN( n ) ) return '';
		var padLength = parseInt( wmnAdmin.numberPadLength, 10 ) || 6;
		var seq = String( n );
		while ( seq.length < padLength ) {
			seq = '0' + seq;
		}
		return ( wmnAdmin.numberPrefix || '' ) + seq;
	}

	/**
	 * Attach digit-only restriction and live mask preview to a member number input.
	 *
	 * @param {jQuery} $input The input element to initialise.
	 */
	function initNumberField( $input ) {
		if ( $input.data( 'wmn-mask-init' ) ) return;
		$input.data( 'wmn-mask-init', true );

		// Insert the preview element after the input (if not already present).
		var previewId = $input.attr( 'id' ) + '_preview';
		if ( ! $( '#' + previewId ).length ) {
			$input.after(
				'<p id="' + previewId + '" class="wmn-number-preview description" style="margin-top:4px;"></p>'
			);
		}
		var $preview = $( '#' + previewId );

		function updateMask() {
			var val = $input.val().replace( /\D/g, '' );
			if ( val !== $input.val() ) {
				$input.val( val );
			}
			if ( val === '' ) {
				$preview.text( '' );
			} else {
				$preview.text( formatMemberNumber( val ) );
			}
		}

		$input.on( 'input keydown paste', function () {
			setTimeout( updateMask, 0 );
		} );

		// Run once on init to handle pre-populated values.
		updateMask();
	}

	// Initialise all number fields present on page load.
	$( function () {
		if ( ! wmnAdmin.hasSeq ) return;
		$( '[data-wmn-number-field]' ).each( function () {
			initNumberField( $( this ) );
		} );
	} );

	// ── Customer search select2 ──────────────────────────────────────────────

	$( function () {
		if ( typeof $.fn.selectWoo === 'undefined' ) return;

		$( '.wmn-customer-search' ).each( function () {
			var $el = $( this );
			$el.selectWoo( {
				allowClear:         true,
				placeholder:        $el.data( 'placeholder' ) || '',
				minimumInputLength: 3,
				dropdownParent:     $( 'body' ),
				escapeMarkup:       function ( m ) { return m; },
				ajax: {
					url:      wmnAdmin.ajaxUrl,
					dataType: 'json',
					delay:    250,
					data: function ( params ) {
						return {
							term:     params.term,
							action:   'woocommerce_json_search_customers',
							security: wmnAdmin.searchCustomersNonce,
						};
					},
					processResults: function ( data ) {
						var terms = [];
						if ( data ) {
							$.each( data, function ( id, text ) {
								terms.push( { id: id, text: text } );
							} );
						}
						return { results: terms };
					},
					cache: true,
				},
			} );
		} );
	} );

	// ── Order search select2 ─────────────────────────────────────────────────

	$( function () {
		if ( typeof $.fn.selectWoo === 'undefined' ) return;

		$( '.wmn-order-search' ).each( function () {
			var $el = $( this );
			$el.selectWoo( {
				allowClear:         true,
				placeholder:        $el.data( 'placeholder' ) || '',
				minimumInputLength: 1,
				dropdownParent:     $( 'body' ),
				escapeMarkup:       function ( m ) { return m; },
				ajax: {
					url:      wmnAdmin.ajaxUrl,
					dataType: 'json',
					delay:    250,
					data: function ( params ) {
						return {
							term:   params.term,
							action: 'wmn_search_orders',
							nonce:  wmnAdmin.nonce,
						};
					},
					processResults: function ( data ) {
						var terms = [];
						if ( data ) {
							$.each( data, function ( id, text ) {
								terms.push( { id: id, text: text } );
							} );
						}
						return { results: terms };
					},
					cache: true,
				},
			} );
		} );
	} );

	// ── Product search select2 ────────────────────────────────────────────────

	$( function () {
		if ( typeof $.fn.selectWoo === 'undefined' ) return;

		$( '.wc-product-search' ).each( function () {
			var $el = $( this );
			if ( $el.data( 'select2' ) ) return; // already initialised by WC

			$el.selectWoo( {
				allowClear:  $el.data( 'allow_clear' ) || false,
				placeholder: $el.data( 'placeholder' ) || '',
				minimumInputLength: 1,
				ajax: {
					url:      wc_enhanced_select_params ? wc_enhanced_select_params.ajax_url : wmnAdmin.ajaxUrl,
					dataType: 'json',
					delay:    250,
					data: function ( params ) {
						return {
							term:     params.term,
							action:   $el.data( 'action' ) || 'woocommerce_json_search_products',
							security: wc_enhanced_select_params ? wc_enhanced_select_params.search_products_nonce : '',
						};
					},
					processResults: function ( data ) {
						var terms = [];
						if ( data ) {
							$.each( data, function ( id, text ) {
								terms.push( { id: id, text: text } );
							} );
						}
						return { results: terms };
					},
					cache: true,
				},
			} );
		} );

		// ── Bulk action confirmation ────────────────────────────────────────────
		$( '#doaction, #doaction2' ).on( 'click', function ( e ) {
			var action = $( this ).siblings( 'select' ).val();
			if ( 'revoke' === action ) {
				if ( ! window.confirm( wmnAdmin.confirmRevoke ) ) {
					e.preventDefault();
				}
			} else if ( 'suspend' === action ) {
				if ( ! window.confirm( wmnAdmin.confirmSuspend ) ) {
					e.preventDefault();
				}
			}
		} );

		// ── Row-level revoke confirmation ──────────────────────────────────────
		$( document ).on( 'click', '.wmn-confirm-revoke', function ( e ) {
			if ( ! window.confirm( wmnAdmin.confirmRevoke ) ) {
				e.preventDefault();
			}
		} );
	} );

} )( jQuery );
