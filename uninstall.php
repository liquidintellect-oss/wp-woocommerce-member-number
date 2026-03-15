<?php
/**
 * Uninstall: remove all plugin data.
 * Only runs when "Delete data on uninstall" is enabled in settings.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'wmn_delete_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

// Drop tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wmn_member_numbers" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wmn_reserved_numbers" );

// Delete all plugin options.
$option_keys = [
	'wmn_version',
	'wmn_number_label',
	'wmn_number_label_plural',
	'wmn_number_format_template',
	'wmn_number_prefix',
	'wmn_number_start',
	'wmn_number_pad_length',
	'wmn_number_min_value',
	'wmn_number_max_value',
	'wmn_trigger_product_ids',
	'wmn_assign_on_status',
	'wmn_refund_behavior',
	'wmn_notify_admin_email',
	'wmn_send_customer_email',
	'wmn_allow_chosen_number',
	'wmn_chosen_number_fee',
	'wmn_chosen_number_fee_label',
	'wmn_reservation_ttl_minutes',
	'wmn_chosen_collision_behavior',
	'wmn_delete_data_on_uninstall',
	'wmn_last_sequence',
];
foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// Remove all user meta.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_wmn_%'" );

// Clear cron.
wp_clear_scheduled_hook( 'wmn_cleanup_reservations' );
