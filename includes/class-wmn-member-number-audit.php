<?php
/**
 * Audit log value object and static helpers for member number changes.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single audit-log entry recording a field change on a member number record.
 */
class WMN_Member_Number_Audit {

	/**
	 * Audit record ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * ID of the member number record that was changed.
	 *
	 * @var int
	 */
	public int $member_number_id = 0;

	/**
	 * WP user ID of the admin who made the change, or null for system changes.
	 *
	 * @var int|null
	 */
	public ?int $admin_user_id = null;

	/**
	 * ISO datetime the change was recorded.
	 *
	 * @var string
	 */
	public string $changed_at = '';

	/**
	 * The name of the field that was changed (e.g. 'status', 'assignment_type', 'member_number', 'notes').
	 *
	 * @var string
	 */
	public string $field_changed = '';

	/**
	 * The value before the change.
	 *
	 * @var string
	 */
	public string $old_value = '';

	/**
	 * The value after the change.
	 *
	 * @var string
	 */
	public string $new_value = '';

	/**
	 * Constructor — hydrates from a database row array.
	 *
	 * @param array<string,mixed> $data Row data keyed by column name.
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				continue;
			}
			$type = gettype( $this->$key );
			if ( 'integer' === $type ) {
				$this->$key = (int) $value;
			} elseif ( 'NULL' === $type ) {
				$this->$key = null === $value ? null : (int) $value;
			} else {
				$this->$key = (string) $value;
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Static helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Inserts a single audit log entry.
	 *
	 * @param int      $member_number_id The member number record that changed.
	 * @param string   $field_changed    Name of the field that changed.
	 * @param string   $old_value        Previous value.
	 * @param string   $new_value        New value.
	 * @param int|null $admin_user_id    WP user ID of the actor (null = current user).
	 * @return void
	 */
	public static function log(
		int $member_number_id,
		string $field_changed,
		string $old_value,
		string $new_value,
		?int $admin_user_id = null
	): void {
		global $wpdb;

		if ( null === $admin_user_id ) {
			$uid           = get_current_user_id();
			$admin_user_id = $uid ? $uid : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'wmn_member_number_audit',
			array(
				'member_number_id' => $member_number_id,
				'admin_user_id'    => $admin_user_id,
				'field_changed'    => $field_changed,
				'old_value'        => $old_value,
				'new_value'        => $new_value,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns all audit entries for a given member number record, newest first.
	 *
	 * @param int $member_number_id The member number record ID.
	 * @return self[]
	 */
	public static function get_by_record_id( int $member_number_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}wmn_member_number_audit
				  WHERE member_number_id = %d
				  ORDER BY changed_at DESC, id DESC",
				$member_number_id
			),
			ARRAY_A
		);

		$rows = $rows ? $rows : array();
		return array_map( fn( $row ) => new self( $row ), $rows );
	}

	/**
	 * Returns all audit entries associated with a user's member number record, newest first.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return self[]
	 */
	public static function get_by_user_id( int $user_id ): array {
		global $wpdb;

		$record = WMN_Member_Number::get_by_user( $user_id );
		if ( ! $record ) {
			return array();
		}

		return self::get_by_record_id( $record->id );
	}
}
