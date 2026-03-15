<?php
/**
 * Value object for a single membership number record.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Value object representing a single membership number record.
 */
class WMN_Member_Number {

	/**
	 * Record ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * The member number string.
	 *
	 * @var string
	 */
	public string $member_number = '';

	/**
	 * Associated user ID, or null for guests.
	 *
	 * @var int|null
	 */
	public ?int $user_id = null;

	/**
	 * Associated order ID.
	 *
	 * @var int
	 */
	public int $order_id = 0;

	/**
	 * ISO datetime the number was assigned.
	 *
	 * @var string
	 */
	public string $assigned_at = '';

	/**
	 * Current status: active, suspended, or revoked.
	 *
	 * @var string
	 */
	public string $status = 'active';

	/**
	 * How the number was assigned: auto or chosen.
	 *
	 * @var string
	 */
	public string $assignment_type = 'auto';

	/**
	 * Optional admin notes.
	 *
	 * @var string
	 */
	public string $notes = '';

	/**
	 * Constructor — hydrates from a database row array.
	 *
	 * @param array<string,mixed> $data Row data keyed by column name.
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				// Cast to the correct type.
				$type = gettype( $this->$key );
				if ( 'integer' === $type ) {
					$this->$key = (int) $value;
				} elseif ( 'NULL' === $type || 'integer' === gettype( $value ) ) {
					// Nullable int (user_id).
					$this->$key = null === $value ? null : (int) $value;
				} else {
					$this->$key = (string) $value;
				}
			}
		}
	}

	/**
	 * Fetch a record by member_number. Returns null if not found.
	 *
	 * @param string $number The member number to look up.
	 * @return self|null
	 */
	public static function get_by_number( string $number ): ?self {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}wmn_member_numbers WHERE member_number = %s LIMIT 1",
				$number
			),
			ARRAY_A
		);
		return $row ? new self( $row ) : null;
	}

	/**
	 * Fetch a record by user_id. Returns null if not found.
	 *
	 * @param int $user_id The user ID to look up.
	 * @return self|null
	 */
	public static function get_by_user( int $user_id ): ?self {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}wmn_member_numbers WHERE user_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		return $row ? new self( $row ) : null;
	}

	/**
	 * Fetch a record by order_id. Returns null if not found.
	 *
	 * @param int $order_id The order ID to look up.
	 * @return self|null
	 */
	public static function get_by_order( int $order_id ): ?self {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}wmn_member_numbers WHERE order_id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);
		return $row ? new self( $row ) : null;
	}

	/**
	 * Returns true when the number is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status; }

	/**
	 * Returns true when the number is suspended.
	 *
	 * @return bool
	 */
	public function is_suspended(): bool {
		return 'suspended' === $this->status; }

	/**
	 * Returns true when the number has been revoked.
	 *
	 * @return bool
	 */
	public function is_revoked(): bool {
		return 'revoked' === $this->status; }

	/**
	 * Returns true when the number was customer-chosen.
	 *
	 * @return bool
	 */
	public function is_chosen(): bool {
		return 'chosen' === $this->assignment_type; }
}
