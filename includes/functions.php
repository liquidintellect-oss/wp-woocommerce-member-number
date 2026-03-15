<?php
/**
 * Global helper functions for the WooCommerce Member Number plugin.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the configured singular or plural label for the number type.
 *
 * @param bool $plural Whether to return the plural label.
 * @return string
 */
function wmn_get_label( bool $plural = false ): string {
	if ( $plural ) {
		return (string) get_option( 'wmn_number_label_plural', __( 'Member Numbers', 'wmn' ) );
	}
	return (string) get_option( 'wmn_number_label', __( 'Member Number', 'wmn' ) );
}

/**
 * Return a formatter instance built from current options.
 *
 * @return WMN_Number_Formatter
 */
function wmn_get_formatter(): WMN_Number_Formatter {
	return new WMN_Number_Formatter(
		(string) get_option( 'wmn_number_format_template', '{PREFIX}{SEQ}' ),
		(string) get_option( 'wmn_number_prefix', 'MBR-' ),
		(int) get_option( 'wmn_number_pad_length', 6 ),
		(int) get_option( 'wmn_number_min_value', 1 ),
		(int) get_option( 'wmn_number_max_value', 999999 )
	);
}

/**
 * Return the list of trigger product IDs, passing through the filter.
 *
 * @return int[]
 */
function wmn_get_trigger_product_ids(): array {
	$ids = get_option( 'wmn_trigger_product_ids', array() );
	if ( ! is_array( $ids ) ) {
		$ids = array();
	}
	$ids = array_map( 'absint', $ids );
	return (array) apply_filters( 'wmn_trigger_product_ids', $ids );
}
