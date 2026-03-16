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
