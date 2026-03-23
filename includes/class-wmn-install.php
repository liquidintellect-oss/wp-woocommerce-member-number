<?php
/**
 * Plugin installation and upgrade routines.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation, version checks, and database table creation.
 */
class WMN_Install {

	/** Run on plugin activation. */
	public static function install(): void {
		self::create_tables();
		self::seed_options();
		update_option( 'wmn_version', WMN_VERSION );
		self::schedule_cron();
	}

	/** Called on plugins_loaded — runs dbDelta if version changed. */
	public static function check_version(): void {
		if ( get_option( 'wmn_version' ) !== WMN_VERSION ) {
			self::install();
		}
	}

	/** Create / upgrade both plugin tables. */
	public static function create_tables(): void {
		global $wpdb;
		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		$sql = "
CREATE TABLE {$wpdb->prefix}wmn_member_numbers (
  id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_number    varchar(32)         NOT NULL,
  user_id          bigint(20) unsigned NULL,
  order_id         bigint(20) unsigned NOT NULL,
  assigned_at      datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status           varchar(20)         NOT NULL DEFAULT 'active',
  assignment_type  varchar(20)         NOT NULL DEFAULT 'auto',
  notes            text                NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY   member_number (member_number),
  UNIQUE KEY   user_id (user_id),
  KEY          order_id (order_id),
  KEY          status (status),
  KEY          assignment_type (assignment_type)
) {$collate};

CREATE TABLE {$wpdb->prefix}wmn_reserved_numbers (
  id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_number  varchar(32)         NOT NULL,
  session_token  varchar(64)         NOT NULL,
  reserved_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at     datetime            NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY member_number (member_number),
  KEY expires_at (expires_at)
) {$collate};

CREATE TABLE {$wpdb->prefix}wmn_member_number_audit (
  id               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_number_id bigint(20) unsigned NOT NULL,
  admin_user_id    bigint(20) unsigned NULL,
  changed_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  field_changed    varchar(64)         NOT NULL,
  old_value        text                NULL,
  new_value        text                NULL,
  PRIMARY KEY  (id),
  KEY member_number_id (member_number_id),
  KEY admin_user_id (admin_user_id),
  KEY changed_at (changed_at)
) {$collate};
";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Seed default options (only if not already set). */
	private static function seed_options(): void {
		$defaults = array(
			'wmn_number_label'              => __( 'Member Number', 'wmn' ),
			'wmn_number_label_plural'       => __( 'Member Numbers', 'wmn' ),
			'wmn_number_format_template'    => '{PREFIX}{SEQ}',
			'wmn_number_prefix'             => 'MBR-',
			'wmn_number_start'              => 1,
			'wmn_number_pad_length'         => 6,
			'wmn_number_min_value'          => 1,
			'wmn_number_max_value'          => 999999,
			'wmn_assign_on_status'          => array( 'processing', 'completed' ),
			'wmn_refund_behavior'           => 'do_nothing',
			'wmn_notify_admin_email'        => get_option( 'admin_email' ),
			'wmn_send_customer_email'       => 'no',
			'wmn_allow_chosen_number'       => 'no',
			'wmn_chosen_number_fee'         => '25.00',
			'wmn_chosen_number_fee_label'   => 'Custom {label} Selection',
			'wmn_reservation_ttl_minutes'   => 30,
			'wmn_chosen_collision_behavior' => 'auto_assign',
			'wmn_delete_data_on_uninstall'  => 'no',
			'wmn_last_sequence'             => 0,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value, false );
			}
		}
	}

	/** Schedule cron event for reservation cleanup. */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'wmn_cleanup_reservations' ) ) {
			wp_schedule_event( time(), 'wmn_15min', 'wmn_cleanup_reservations' );
		}
	}
}
