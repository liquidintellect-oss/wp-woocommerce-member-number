<?php
/**
 * Member number assignment, refund, and linking logic.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages all member number assignment, refund handling, and guest linking.
 */
class WMN_Member_Number_Manager {

	/**
	 * Constructor — registers all WooCommerce and WordPress hooks.
	 */
	public function __construct() {
		// Order status hooks — both statuses, idempotency handles duplicates.
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_assign_number' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_assign_number' ), 10, 1 );

		// Refund hooks.
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'handle_full_refund' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_full_refund' ), 10, 1 );

		// Guest → account linking.
		add_action( 'user_register', array( $this, 'link_guest_number_on_registration' ), 10, 1 );

		// User deletion.
		add_action( 'delete_user', array( $this, 'handle_user_deletion' ), 10, 1 );

		// Cron cleanup of expired reservations.
		add_action( 'wmn_cleanup_reservations', array( $this, 'cleanup_expired_reservations' ) );

		// Register the custom cron interval.
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Cron
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Registers a custom 15-minute cron schedule.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing cron schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules['wmn_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'wmn' ),
		);
		return $schedules;
	}

	/**
	 * Deletes expired number reservations from the database.
	 *
	 * @return void
	 */
	public function cleanup_expired_reservations(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}wmn_reserved_numbers WHERE expires_at < NOW()"
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Assignment entry point
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Attempts to assign a member number for the given order.
	 *
	 * @param int $order_id The WooCommerce order ID.
	 * @return void
	 */
	public function maybe_assign_number( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// External veto.
		if ( ! (bool) apply_filters( 'wmn_should_assign_for_order', true, $order ) ) {
			return;
		}

		// Skip subscription renewals.
		if ( $order->get_meta( '_subscription_renewal' ) ) {
			return;
		}

		// Check the configured trigger statuses.
		$trigger_statuses = (array) get_option( 'wmn_assign_on_status', array( 'processing', 'completed' ) );
		if ( ! in_array( $order->get_status(), $trigger_statuses, true ) ) {
			return;
		}

		// Check if any line item matches a trigger product.
		if ( ! $this->order_contains_trigger_product( $order ) ) {
			return;
		}

		// Idempotency — already assigned for this order?
		if ( WMN_Member_Number::get_by_order( $order_id ) ) {
			return;
		}

		// Idempotency — user already has an active number?
		// Suspended/revoked records are ignored so the user can receive a new assignment.
		$user_id         = $order->get_user_id();
		$existing_record = $user_id > 0 ? WMN_Member_Number::get_by_user( $user_id ) : null;
		if ( $existing_record && $existing_record->is_active() ) {
			// Allow the user to replace their active number with a customer-chosen one.
			$chosen_feature_on = 'yes' === get_option( 'wmn_allow_chosen_number', 'no' );
			$has_chosen        = (string) $order->get_meta( '_wmn_chosen_number' );
			if ( ! $chosen_feature_on || ! $has_chosen ) {
				return;
			}
		}

		// Branch: chosen or auto.
		$chosen = (string) $order->get_meta( '_wmn_chosen_number' );
		if ( $chosen && 'yes' === get_option( 'wmn_allow_chosen_number', 'no' ) ) {
			$this->assign_chosen_number( $order, $chosen );
		} else {
			$this->assign_auto_number( $order );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Auto assignment
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Assigns the next available auto-generated member number to an order.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 * @return void
	 */
	private function assign_auto_number( WC_Order $order ): void {
		global $wpdb;

		$formatter = wmn_get_formatter();
		$sequence  = (int) get_option( 'wmn_last_sequence', 0 );
		$number    = '';
		$attempts  = 0;

		do {
			++$sequence;
			$number = $formatter->generate( $sequence );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$wpdb->prefix}wmn_member_numbers WHERE member_number = %s LIMIT 1",
					$number
				)
			);
			++$attempts;
		} while ( $exists && $attempts < 10000 );

		if ( $exists ) {
			// Extremely unlikely — log and bail.
			$order->add_order_note( __( 'WMN: Could not generate a unique member number after 10000 attempts.', 'wmn' ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wmn_member_numbers',
			array(
				'member_number'   => $number,
				'user_id'         => ( ! empty( $order->get_user_id() ) ? $order->get_user_id() : null ),
				'order_id'        => $order->get_id(),
				'status'          => 'active',
				'assignment_type' => 'auto',
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			// UNIQUE KEY race — retry once with next sequence.
			++$sequence;
			$number = $formatter->generate( $sequence );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'wmn_member_numbers',
				array(
					'member_number'   => $number,
					'user_id'         => ( ! empty( $order->get_user_id() ) ? $order->get_user_id() : null ),
					'order_id'        => $order->get_id(),
					'status'          => 'active',
					'assignment_type' => 'auto',
				),
				array( '%s', '%d', '%d', '%s', '%s' )
			);
			if ( ! $inserted ) {
				$order->add_order_note( __( 'WMN: Failed to insert member number (race condition).', 'wmn' ) );
				return;
			}
		}

		update_option( 'wmn_last_sequence', $sequence, false );
		$this->persist_user_meta( $order, $number, 'auto' );

		/* translators: 1: label, 2: number */
		$order->add_order_note( sprintf( __( '%1$s %2$s assigned.', 'wmn' ), wmn_get_label(), $number ) );

		do_action( 'wmn_member_number_assigned', $number, $order->get_user_id(), $order->get_id(), 'auto' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Chosen assignment
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Assigns a customer-chosen number to an order, with collision handling.
	 *
	 * @param WC_Order $order  The WooCommerce order object.
	 * @param string   $chosen The number chosen by the customer.
	 * @return void
	 */
	private function assign_chosen_number( WC_Order $order, string $chosen ): void {
		global $wpdb;

		$formatter  = wmn_get_formatter();
		$validation = $formatter->validate_chosen( $chosen );

		if ( is_wp_error( $validation ) ) {
			// Fall back to auto.
			$order->add_order_note(
				sprintf(
					/* translators: 1: chosen number, 2: error message */
					__( 'WMN: Chosen number "%1$s" failed validation (%2$s). Auto-assigning.', 'wmn' ),
					esc_html( $chosen ),
					$validation->get_error_message()
				)
			);
			$this->assign_auto_number( $order );
			return;
		}

		$user_id         = $order->get_user_id() ? $order->get_user_id() : null;
		$existing_record = $user_id ? WMN_Member_Number::get_by_user( $user_id ) : null;

		if ( $existing_record ) {
			// User already has a record — replace it with the new chosen number.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->update(
				$wpdb->prefix . 'wmn_member_numbers',
				array(
					'member_number'   => $chosen,
					'order_id'        => $order->get_id(),
					'status'          => 'active',
					'assignment_type' => 'chosen',
				),
				array( 'id' => $existing_record->id ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'wmn_member_numbers',
				array(
					'member_number'   => $chosen,
					'user_id'         => $user_id,
					'order_id'        => $order->get_id(),
					'status'          => 'active',
					'assignment_type' => 'chosen',
				),
				array( '%s', '%d', '%d', '%s', '%s' )
			);
		}

		// Clean up reservation regardless of outcome.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'wmn_reserved_numbers',
			array( 'member_number' => $chosen ),
			array( '%s' )
		);

		if ( ! $inserted ) {
			// Collision — number was taken between reservation and order completion.
			$collision_behavior = get_option( 'wmn_chosen_collision_behavior', 'auto_assign' );

			if ( 'hold_for_review' === $collision_behavior ) {
				$order->update_meta_data( '_wmn_needs_review', 1 );
				$order->save();
				$order->add_order_note(
					sprintf(
						/* translators: 1: label, 2: chosen number */
						__( 'WMN: Chosen %1$s "%2$s" was already taken. Order requires manual review.', 'wmn' ),
						wmn_get_label(),
						esc_html( $chosen )
					)
				);
				$admin_email = get_option( 'wmn_notify_admin_email', get_option( 'admin_email' ) );
				wp_mail(
					$admin_email,
					/* translators: %d: order ID */
					sprintf( __( 'WMN: Order #%d needs review', 'wmn' ), $order->get_id() ),
					sprintf(
						/* translators: 1: chosen number, 2: order ID */
						__( 'Chosen number "%1$s" for order #%2$d was already taken. Please assign manually.', 'wmn' ),
						$chosen,
						$order->get_id()
					)
				);
				return;
			}

			// auto_assign fallback: refund the chosen fee via a negative fee on the order.
			$fee_amount = (float) get_option( 'wmn_chosen_number_fee', 25.00 );
			if ( $fee_amount > 0 ) {
				$refund_item = new WC_Order_Item_Fee();
				/* translators: %s: label */
				$refund_item->set_name( sprintf( __( '%s Selection Refund', 'wmn' ), wmn_get_label() ) );
				$refund_item->set_total( -$fee_amount );
				$order->add_item( $refund_item );
				$order->save();
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: label, 2: chosen number */
					__( 'WMN: Chosen %1$s "%2$s" was already taken. Auto-assigning instead.', 'wmn' ),
					wmn_get_label(),
					esc_html( $chosen )
				)
			);

			$this->assign_auto_number( $order );
			return;
		}

		$this->persist_user_meta( $order, $chosen, 'chosen' );

		/* translators: 1: label, 2: number */
		$order->add_order_note( sprintf( __( '%1$s %2$s chosen and assigned.', 'wmn' ), wmn_get_label(), $chosen ) );

		do_action( 'wmn_member_number_assigned', $chosen, $order->get_user_id(), $order->get_id(), 'chosen' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Persist user meta
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Saves the assigned number to user meta and optionally sends a customer email.
	 *
	 * @param WC_Order $order  The WooCommerce order object.
	 * @param string   $number The member number that was assigned.
	 * @param string   $type   Assignment type: 'auto' or 'chosen'.
	 * @return void
	 */
	private function persist_user_meta( WC_Order $order, string $number, string $type ): void {
		$user_id = $order->get_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, '_wmn_member_number', $number );
			update_user_meta( $user_id, '_wmn_member_number_order_id', $order->get_id() );
			update_user_meta( $user_id, '_wmn_assignment_type', $type );
		}

		// Also send the optional customer email.
		if ( 'yes' === get_option( 'wmn_send_customer_email', 'no' ) ) {
			$mailer = WC()->mailer();
			$emails = $mailer->get_emails();
			if ( ! empty( $emails['WMN_Email_Number_Assigned'] ) ) {
				$emails['WMN_Email_Number_Assigned']->trigger( $order->get_id(), $order, $number );
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Refund
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Handles a full order refund according to the configured refund behavior.
	 *
	 * @param int $order_id   The WooCommerce order ID.
	 * @param int $refund_id  The refund ID (unused, accepted for hook compatibility).
	 * @return void
	 */
	public function handle_full_refund( int $order_id, int $refund_id = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$record = WMN_Member_Number::get_by_order( $order_id );
		if ( ! $record ) {
			return;
		}

		$order    = wc_get_order( $order_id );
		$behavior = get_option( 'wmn_refund_behavior', 'do_nothing' );

		if ( 'suspend' === $behavior ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wmn_member_numbers',
				array( 'status' => 'suspended' ),
				array( 'id' => $record->id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( $record->user_id ) {
				update_user_meta( $record->user_id, '_wmn_member_number_status', 'suspended' );
			}
		} elseif ( 'notify_admin_email' === $behavior ) {
			$admin_email = get_option( 'wmn_notify_admin_email', get_option( 'admin_email' ) );
			wp_mail(
				$admin_email,
				sprintf(
					/* translators: 1: label, 2: order id */
					__( 'WMN: Refund for order #%2$d — %1$s %3$s', 'wmn' ),
					wmn_get_label(),
					$order_id,
					$record->member_number
				),
				sprintf(
					/* translators: 1: order id, 2: label, 3: number */
					__( 'Order #%1$d was fully refunded. %2$s %3$s may need to be reviewed.', 'wmn' ),
					$order_id,
					wmn_get_label(),
					$record->member_number
				)
			);
		}

		if ( $order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: label, 2: number, 3: behavior */
					__( 'WMN: Order refunded. %1$s %2$s — action: %3$s.', 'wmn' ),
					wmn_get_label(),
					$record->member_number,
					$behavior
				)
			);
		}

		do_action( 'wmn_member_number_refunded', $record->member_number, $order_id );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Guest linking
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Links a guest order's member number to a newly registered user account.
	 *
	 * @param int $user_id The ID of the newly registered user.
	 * @return void
	 */
	public function link_guest_number_on_registration( int $user_id ): void {
		global $wpdb;

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$email = $user->user_email;

		// Find a guest member number whose originating order has a matching billing email.
		// Works with both HPOS and legacy post-based orders.
		$row = null;

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS path.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT mn.id, mn.order_id
					   FROM {$wpdb->prefix}wmn_member_numbers mn
					   JOIN {$wpdb->prefix}wc_orders o ON o.id = mn.order_id
					  WHERE mn.user_id IS NULL
					    AND o.billing_email = %s
					  LIMIT 1",
					$email
				)
			);
		} else {
			// Legacy post-meta path.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT mn.id, mn.order_id
					   FROM {$wpdb->prefix}wmn_member_numbers mn
					   JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = mn.order_id
					  WHERE mn.user_id IS NULL
					    AND pm.meta_key = '_billing_email'
					    AND pm.meta_value = %s
					  LIMIT 1",
					$email
				)
			);
		}

		if ( ! $row ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'wmn_member_numbers',
			array( 'user_id' => $user_id ),
			array( 'id' => (int) $row->id ),
			array( '%d' ),
			array( '%d' )
		);

		$record = WMN_Member_Number::get_by_order( (int) $row->order_id );
		if ( $record ) {
			update_user_meta( $user_id, '_wmn_member_number', $record->member_number );
			update_user_meta( $user_id, '_wmn_member_number_order_id', $record->order_id );
			update_user_meta( $user_id, '_wmn_assignment_type', $record->assignment_type );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// User deletion
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Handles user account deletion by nulling out the user_id on the member number record.
	 *
	 * @param int $user_id The ID of the user being deleted.
	 * @return void
	 */
	public function handle_user_deletion( int $user_id ): void {
		global $wpdb;

		$record = WMN_Member_Number::get_by_user( $user_id );
		if ( ! $record ) {
			return;
		}

		$existing_notes = $record->notes ? $record->notes . "\n" : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'wmn_member_numbers',
			array(
				'user_id' => null,
				'notes'   => $existing_notes . sprintf(
					/* translators: 1: user id, 2: date */
					__( 'User account (ID %1$d) deleted on %2$s.', 'wmn' ),
					$user_id,
					current_time( 'mysql' )
				),
			),
			array( 'id' => $record->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Check if the order contains at least one configured trigger product.
	 *
	 * @param WC_Order $order The order to check.
	 * @return bool
	 */
	private function order_contains_trigger_product( WC_Order $order ): bool {
		$trigger_ids = wmn_get_trigger_product_ids();
		if ( empty( $trigger_ids ) ) {
			return false;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();

			if ( in_array( $product_id, $trigger_ids, true ) ) {
				return true;
			}
			if ( $variation_id && in_array( $variation_id, $trigger_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a number is available (not taken, not reserved).
	 *
	 * @param string      $number          The member number to check.
	 * @param string|null $exclude_session Exclude this session's own reservation.
	 * @return bool
	 */
	public function is_number_available( string $number, ?string $exclude_session = null ): bool {
		global $wpdb;

		// Check assigned numbers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$taken = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}wmn_member_numbers WHERE member_number = %s LIMIT 1",
				$number
			)
		);
		if ( $taken ) {
			return false;
		}

		// Check reservations (excluding own session).
		if ( $exclude_session ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$reserved = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$wpdb->prefix}wmn_reserved_numbers
					  WHERE member_number = %s
					    AND expires_at > NOW()
					    AND session_token != %s
					  LIMIT 1",
					$number,
					$exclude_session
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$reserved = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$wpdb->prefix}wmn_reserved_numbers
					  WHERE member_number = %s AND expires_at > NOW() LIMIT 1",
					$number
				)
			);
		}

		if ( $reserved ) {
			return false;
		}

		return (bool) apply_filters( 'wmn_number_is_available', true, $number );
	}
}
