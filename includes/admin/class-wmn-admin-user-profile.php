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

		$record  = WMN_Member_Number::get_by_user( $user->ID );
		$number  = $record ? $record->member_number : '';
		$nonce   = wp_create_nonce( 'wmn_user_profile_' . $user->ID );
		$entries = $record ? WMN_Member_Number_Audit::get_by_record_id( $record->id ) : array();
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
			<?php if ( $record ) : ?>
			<tr>
				<th><label for="wmn_member_notes_display"><?php esc_html_e( 'Admin Notes', 'wmn' ); ?></label></th>
				<td>
					<p id="wmn_member_notes_display" class="description">
						<?php echo $record->notes ? esc_html( $record->notes ) : '<em>' . esc_html__( 'None', 'wmn' ) . '</em>'; ?>
					</p>
					<?php
					$edit_url = add_query_arg(
						array(
							'action' => 'edit_member',
							'wmn_id' => $record->id,
						),
						admin_url( 'admin.php?page=wmn-members' )
					);
					?>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
						<?php
						printf(
							/* translators: %s: label */
							esc_html__( 'Edit %s Record', 'wmn' ),
							esc_html( wmn_get_label() )
						);
						?>
					</a>
				</td>
			</tr>
			<?php endif; ?>
		</table>

		<?php if ( $entries ) : ?>
		<h3><?php esc_html_e( 'Member Number Change History', 'wmn' ); ?></h3>
		<table class="widefat striped" style="margin-bottom:2em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'wmn' ); ?></th>
					<th><?php esc_html_e( 'Admin', 'wmn' ); ?></th>
					<th><?php esc_html_e( 'Field', 'wmn' ); ?></th>
					<th><?php esc_html_e( 'From', 'wmn' ); ?></th>
					<th><?php esc_html_e( 'To', 'wmn' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry->changed_at ) ) ); ?></td>
					<td>
						<?php
						if ( $entry->admin_user_id ) {
							$admin = get_userdata( $entry->admin_user_id );
							echo $admin
								? '<a href="' . esc_url( get_edit_user_link( $admin->ID ) ) . '">' . esc_html( $admin->display_name ) . '</a>'
								: esc_html( sprintf( '#%d', $entry->admin_user_id ) );
						} else {
							echo '<em>' . esc_html__( 'System', 'wmn' ) . '</em>';
						}
						?>
					</td>
					<td><?php echo esc_html( $entry->field_changed ); ?></td>
					<td><?php echo esc_html( $entry->old_value ); ?></td>
					<td><?php echo esc_html( $entry->new_value ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
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
				WMN_Member_Number_Audit::log( $existing->id, 'member_number', $old_number, '' );
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
			WMN_Member_Number_Audit::log( $existing->id, 'member_number', $old_number, $new_number );
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
					'assignment_type' => 'manual',
				),
				array( '%s', '%d', '%d', '%s', '%s' )
			);
			$new_id = (int) $wpdb->insert_id;
			if ( $new_id ) {
				WMN_Member_Number_Audit::log( $new_id, 'created', '', $new_number );
			}
		}

		update_user_meta( $user_id, '_wmn_member_number', $new_number );
	}
}
