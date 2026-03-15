<?php
/**
 * Member number section on the WordPress user profile / edit-user screen.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a member number management section to the user profile admin screen.
 */
class WMN_Admin_User_Profile {

	/**
	 * Constructor — hooks into the user profile display and save actions.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_section' ) );
		add_action( 'personal_options_update', array( $this, 'save_section' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_section' ) );
	}

	/**
	 * Renders the member number section on the user profile screen.
	 *
	 * @param WP_User $user The user object being displayed.
	 * @return void
	 */
	public function render_section( WP_User $user ): void {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$record = WMN_Member_Number::get_by_user( $user->ID );
		$number = $record ? $record->member_number : '';
		$nonce  = wp_create_nonce( 'wmn_user_profile_' . $user->ID );
		?>
		<h2><?php echo esc_html( wmn_get_label() ); ?></h2>
		<table class="form-table" id="wmn-user-profile-section">
			<tr>
				<th>
					<label for="wmn_member_number_edit">
						<?php echo esc_html( wmn_get_label() ); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						name="wmn_member_number_edit"
						id="wmn_member_number_edit"
						value="<?php echo esc_attr( $number ); ?>"
						class="regular-text"
					/>
					<input type="hidden" name="wmn_user_profile_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<?php if ( $record ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: type, 2: status, 3: order link, 4: date */
								esc_html__( 'Type: %1$s · Status: %2$s · Order: %3$s · Assigned: %4$s', 'wmn' ),
								esc_html( ucfirst( $record->assignment_type ) ),
								esc_html( ucfirst( $record->status ) ),
								$record->order_id
									? '<a href="' . esc_url( get_edit_post_link( $record->order_id ) ) . '">#' . absint( $record->order_id ) . '</a>'
									: '—',
								esc_html( date_i18n( get_option( 'date_format' ), strtotime( $record->assigned_at ) ) )
							);
							?>
						</p>
					<?php else : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: label */
								esc_html__( 'No %s assigned yet. Enter a number above to assign one manually.', 'wmn' ),
								esc_html( wmn_get_label() )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves the member number from the user profile screen.
	 *
	 * @param int $user_id The ID of the user being saved.
	 * @return void
	 */
	public function save_section( int $user_id ): void {
		if ( ! isset( $_POST['wmn_user_profile_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['wmn_user_profile_nonce'] ), 'wmn_user_profile_' . $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$new_number = sanitize_text_field( wp_unslash( $_POST['wmn_member_number_edit'] ?? '' ) );
		$existing   = WMN_Member_Number::get_by_user( $user_id );
		$old_number = $existing ? $existing->member_number : '';

		if ( $new_number === $old_number ) {
			return; // No change.
		}

		global $wpdb;

		if ( '' === $new_number ) {
			// Clearing the number — null out user_id on the record.
			if ( $existing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'wmn_member_numbers',
					array( 'user_id' => null ),
					array( 'id' => $existing->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
			delete_user_meta( $user_id, '_wmn_member_number' );
			delete_user_meta( $user_id, '_wmn_member_number_order_id' );
			delete_user_meta( $user_id, '_wmn_assignment_type' );
			return;
		}

		// Uniqueness check.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$conflict = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}wmn_member_numbers WHERE member_number = %s AND (user_id != %d OR user_id IS NULL) LIMIT 1",
				$new_number,
				$user_id
			)
		);
		if ( $conflict ) {
			// Cannot assign — already taken. Silently bail (or add admin notice).
			add_action(
				'user_profile_update_errors',
				function ( WP_Error $errors ) use ( $new_number ) {
					$errors->add(
						'wmn_number_taken',
						sprintf(
						/* translators: 1: label, 2: number */
							__( '%1$s %2$s is already assigned to another user.', 'wmn' ),
							wmn_get_label(),
							$new_number
						)
					);
				}
			);
			return;
		}

		if ( $existing ) {
			// Update existing record.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wmn_member_numbers',
				array(
					'member_number' => $new_number,
					'user_id'       => $user_id,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		} else {
			// Create a new record with no order.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'wmn_member_numbers',
				array(
					'member_number'   => $new_number,
					'user_id'         => $user_id,
					'order_id'        => 0,
					'status'          => 'active',
					'assignment_type' => 'auto',
				),
				array( '%s', '%d', '%d', '%s', '%s' )
			);
		}

		update_user_meta( $user_id, '_wmn_member_number', $new_number );
	}
}
