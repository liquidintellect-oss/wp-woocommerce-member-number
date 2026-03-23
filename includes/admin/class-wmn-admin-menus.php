<?php
/**
 * Admin menu and page-action handlers for the member list page.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers WooCommerce admin sub-menus and handles list-page actions.
 */
class WMN_Admin_Menus {

	/**
	 * Constructor — hooks into admin_menu and WooCommerce settings.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'woocommerce_get_settings_pages', array( $this, 'register_settings_page' ) );
	}

	/**
	 * Registers the Members sub-menu under the WooCommerce top-level menu.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		add_submenu_page(
			'woocommerce',
			wmn_get_label( true ),
			wmn_get_label( true ),
			// phpcs:ignore WordPress.WP.Capabilities.Unknown
			'manage_woocommerce',
			'wmn-members',
			array( $this, 'render_list_page' )
		);
	}

	/**
	 * Registers the WMN settings page with WooCommerce.
	 *
	 * @param array<int,WC_Settings_Page> $pages Existing settings pages.
	 * @return array<int,WC_Settings_Page>
	 */
	public function register_settings_page( array $pages ): array {
		// Require here (not at plugins_loaded) so WC_Settings_Page is already defined.
		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-settings-page.php';
		$pages[] = new WMN_Settings_Page();
		return $pages;
	}

	/**
	 * Renders the member list admin page, including notices and the list table.
	 *
	 * @return void
	 */
	public function render_list_page(): void {
		// Handle single-row actions.
		$this->handle_row_actions();

		// Handle bulk actions.
		$this->handle_bulk_actions();

		// Handle manual assignment form.
		$this->handle_manual_assign();

		// Handle edit form save.
		$this->handle_edit_save();

		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-member-list-table.php';
		$table = new WMN_Member_List_Table();
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( $_GET['action'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id          = absint( $_GET['wmn_id'] ?? 0 );
		$show_assign_form = 'manual_assign' === $action;
		$show_edit_form   = 'edit_member' === $action && $edit_id > 0;
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html( wmn_get_label( true ) ); ?>
			</h1>
			<a href="<?php echo esc_url( add_query_arg( 'action', 'manual_assign' ) ); ?>" class="page-title-action">
				<?php
				printf(
					/* translators: %s: label */
					esc_html__( 'Assign %s', 'wmn' ),
					esc_html( wmn_get_label() )
				);
				?>
			</a>
			<hr class="wp-header-end">

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['wmn_message'] ) ) :
				?>
				<div class="notice notice-success is-dismissible">
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$wmn_message = sanitize_text_field( wp_unslash( $_GET['wmn_message'] ) );
					?>
					<p><?php echo esc_html( urldecode( $wmn_message ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $show_assign_form ) : ?>
				<?php $this->render_manual_assign_form(); ?>
			<?php elseif ( $show_edit_form ) : ?>
				<?php $this->render_edit_form( $edit_id ); ?>
			<?php endif; ?>

			<?php if ( ! $show_edit_form ) : ?>
			<form method="get">
				<input type="hidden" name="page" value="wmn-members" />
				<?php $table->search_box( esc_html__( 'Search', 'wmn' ), 'wmn_search' ); ?>
				<?php $table->display(); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the manual number assignment form.
	 *
	 * @return void
	 */
	private function render_manual_assign_form(): void {
		$nonce = wp_create_nonce( 'wmn_manual_assign' );
		?>
		<div class="wmn-manual-assign-form">
			<h2>
			<?php
			printf(
				/* translators: %s: label */
				esc_html__( 'Manually Assign a %s', 'wmn' ),
				esc_html( wmn_get_label() )
			);
			?>
			</h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'wmn_manual_assign', 'wmn_manual_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="wmn_user_id"><?php esc_html_e( 'User', 'wmn' ); ?></label></th>
						<td>
							<select name="wmn_user_id" id="wmn_user_id"
								class="wmn-customer-search"
								data-placeholder="<?php esc_attr_e( 'Search for a customer…', 'wmn' ); ?>"
								data-allow_clear="true"
								style="width:300px">
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wmn_order_id"><?php esc_html_e( 'Order (optional)', 'wmn' ); ?></label></th>
						<td>
							<select name="wmn_order_id" id="wmn_order_id"
								class="wmn-order-search"
								data-placeholder="<?php esc_attr_e( 'Search for an order…', 'wmn' ); ?>"
								data-allow_clear="true"
								style="width:300px">
							</select>
						</td>
					</tr>
					<tr>
						<th>
							<label for="wmn_manual_number">
							<?php
							printf(
								/* translators: %s: label */
								esc_html__( '%s (leave blank for auto)', 'wmn' ),
								esc_html( wmn_get_label() )
							);
							?>
							</label>
						</th>
						<td><input type="text" name="wmn_manual_number" id="wmn_manual_number" class="regular-text" /></td>
					</tr>
				</table>
				<?php
				submit_button(
					sprintf(
						/* translators: %s: label */
						__( 'Assign %s', 'wmn' ),
						wmn_get_label()
					),
					'primary',
					'wmn_do_manual_assign'
				);
				?>
				<a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'wmn' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the edit form for a single member number record.
	 *
	 * @param int $id The record ID to edit.
	 * @return void
	 */
	private function render_edit_form( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$record = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}wmn_member_numbers WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( ! $record ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Record not found.', 'wmn' ) . '</p></div>';
			return;
		}

		$nonce   = wp_create_nonce( 'wmn_edit_member_' . $id );
		$back    = remove_query_arg( array( 'action', 'wmn_id' ) );
		$entries = WMN_Member_Number_Audit::get_by_record_id( $id );
		?>
		<div class="wmn-edit-member-form">
			<h2>
				<?php
				printf(
					/* translators: %s: member number */
					esc_html__( 'Edit %s', 'wmn' ),
					esc_html( wmn_get_label() )
				);
				echo ' &mdash; <strong>' . esc_html( $record['member_number'] ) . '</strong>';
				?>
			</h2>
			<form method="post" action="">
				<input type="hidden" name="wmn_edit_id" value="<?php echo absint( $id ); ?>" />
				<?php wp_nonce_field( 'wmn_edit_member_' . $id, 'wmn_edit_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th>
							<label for="wmn_edit_member_number">
								<?php echo esc_html( wmn_get_label() ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								name="wmn_edit_member_number"
								id="wmn_edit_member_number"
								value="<?php echo esc_attr( $record['member_number'] ); ?>"
								class="regular-text"
							/>
						</td>
					</tr>
					<tr>
						<th><label for="wmn_edit_status"><?php esc_html_e( 'Status', 'wmn' ); ?></label></th>
						<td>
							<select name="wmn_edit_status" id="wmn_edit_status">
								<?php
								foreach ( array( 'active', 'suspended', 'revoked' ) as $s ) :
									?>
									<option value="<?php echo esc_attr( $s ); ?>"
										<?php selected( $record['status'], $s ); ?>>
										<?php echo esc_html( ucfirst( $s ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wmn_edit_assignment_type"><?php esc_html_e( 'Assignment Type', 'wmn' ); ?></label></th>
						<td>
							<select name="wmn_edit_assignment_type" id="wmn_edit_assignment_type">
								<?php
								foreach ( array( 'auto', 'chosen', 'manual' ) as $t ) :
									?>
									<option value="<?php echo esc_attr( $t ); ?>"
										<?php selected( $record['assignment_type'], $t ); ?>>
										<?php echo esc_html( ucfirst( $t ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Correct the type if it was set incorrectly by an earlier version of the plugin.', 'wmn' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="wmn_edit_notes"><?php esc_html_e( 'Notes', 'wmn' ); ?></label></th>
						<td>
							<textarea
								name="wmn_edit_notes"
								id="wmn_edit_notes"
								rows="4"
								class="large-text"
							><?php echo esc_textarea( $record['notes'] ?? '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Internal admin notes about this member number.', 'wmn' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php
				submit_button( __( 'Save Changes', 'wmn' ), 'primary', 'wmn_do_edit_save' );
				?>
				<a href="<?php echo esc_url( $back ); ?>" class="button"><?php esc_html_e( 'Cancel', 'wmn' ); ?></a>
			</form>

			<?php if ( $entries ) : ?>
			<h3><?php esc_html_e( 'Change History', 'wmn' ); ?></h3>
			<table class="widefat striped" style="margin-top:1em;">
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
		</div>
		<?php
	}

	/**
	 * Handles saving changes from the member number edit form.
	 *
	 * @return void
	 */
	protected function handle_edit_save(): void {
		if ( empty( $_POST['wmn_do_edit_save'] ) ) {
			return;
		}

		$id = absint( $_POST['wmn_edit_id'] ?? 0 );
		if ( ! $id ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['wmn_edit_nonce'] ?? '' ), 'wmn_edit_member_' . $id ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$wpdb->prefix}wmn_member_numbers WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( ! $existing ) {
			return;
		}

		$new_number = sanitize_text_field( wp_unslash( $_POST['wmn_edit_member_number'] ?? '' ) );
		$new_status = sanitize_key( $_POST['wmn_edit_status'] ?? '' );
		$new_type   = sanitize_key( $_POST['wmn_edit_assignment_type'] ?? '' );
		$new_notes  = sanitize_textarea_field( wp_unslash( $_POST['wmn_edit_notes'] ?? '' ) );

		// Validate status and type.
		$valid_statuses = array( 'active', 'suspended', 'revoked' );
		$valid_types    = array( 'auto', 'chosen', 'manual' );
		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			$new_status = $existing['status'];
		}
		if ( ! in_array( $new_type, $valid_types, true ) ) {
			$new_type = $existing['assignment_type'];
		}

		// Uniqueness check if number changed.
		if ( $new_number !== $existing['member_number'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$conflict = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$wpdb->prefix}wmn_member_numbers WHERE member_number = %s AND id != %d LIMIT 1",
					$new_number,
					$id
				)
			);
			if ( $conflict ) {
				$this->redirect_and_exit(
					add_query_arg(
						'wmn_message',
						rawurlencode(
							sprintf(
								/* translators: %s: member number */
								__( 'Number %s is already assigned to another record.', 'wmn' ),
								$new_number
							)
						),
						add_query_arg(
							array(
								'action' => 'edit_member',
								'wmn_id' => $id,
							),
							admin_url( 'admin.php?page=wmn-members' )
						)
					)
				);
			}
		}

		// Build update data and collect changes for audit.
		$update_data   = array();
		$update_format = array();
		$changes       = array();

		if ( $new_number !== $existing['member_number'] ) {
			$update_data['member_number'] = $new_number;
			$update_format[]              = '%s';
			$changes['member_number']     = array( $existing['member_number'], $new_number );
		}
		if ( $new_status !== $existing['status'] ) {
			$update_data['status'] = $new_status;
			$update_format[]       = '%s';
			$changes['status']     = array( $existing['status'], $new_status );
		}
		if ( $new_type !== $existing['assignment_type'] ) {
			$update_data['assignment_type'] = $new_type;
			$update_format[]                = '%s';
			$changes['assignment_type']     = array( $existing['assignment_type'], $new_type );
		}
		if ( ( $existing['notes'] ?? '' ) !== $new_notes ) {
			$update_data['notes'] = $new_notes;
			$update_format[]      = '%s';
			$changes['notes']     = array( (string) ( $existing['notes'] ?? '' ), $new_notes );
		}

		if ( ! empty( $update_data ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wmn_member_numbers',
				$update_data,
				array( 'id' => $id ),
				$update_format,
				array( '%d' )
			);

			// Log each changed field.
			foreach ( $changes as $field => list( $old, $new ) ) {
				WMN_Member_Number_Audit::log( $id, $field, $old, $new );
			}

			// If member_number changed, sync user meta.
			if ( isset( $changes['member_number'] ) && ! empty( $existing['user_id'] ) ) {
				update_user_meta( (int) $existing['user_id'], '_wmn_member_number', $new_number );
			}
			if ( isset( $changes['assignment_type'] ) && ! empty( $existing['user_id'] ) ) {
				update_user_meta( (int) $existing['user_id'], '_wmn_assignment_type', $new_type );
			}
		}

		$this->redirect_and_exit(
			add_query_arg(
				'wmn_message',
				rawurlencode( __( 'Changes saved.', 'wmn' ) ),
				add_query_arg(
					array(
						'action' => 'edit_member',
						'wmn_id' => $id,
					),
					admin_url( 'admin.php?page=wmn-members' )
				)
			)
		);
	}

	/**
	 * Handles single-row status-change actions (suspend/reactivate/revoke).
	 *
	 * @return void
	 */
	protected function handle_row_actions(): void {
		if ( empty( $_GET['wmn_action'] ) || empty( $_GET['wmn_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_GET['wmn_nonce'] ), 'wmn_row_action' ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = absint( $_GET['wmn_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( $_GET['wmn_action'] );

		$map = array(
			'suspend'    => 'suspended',
			'reactivate' => 'active',
			'revoke'     => 'revoked',
		);

		if ( isset( $map[ $action ] ) && $id ) {
			// Fetch the current status for the audit log.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$old_status = (string) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT status FROM {$wpdb->prefix}wmn_member_numbers WHERE id = %d LIMIT 1",
					$id
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wmn_member_numbers',
				array( 'status' => $map[ $action ] ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			WMN_Member_Number_Audit::log( $id, 'status', $old_status, $map[ $action ] );
			$this->redirect_and_exit(
				add_query_arg(
					'wmn_message',
					rawurlencode(
						sprintf(
							/* translators: %s: new status value */
							__( 'Status updated to %s.', 'wmn' ),
							$map[ $action ]
						)
					),
					remove_query_arg( array( 'wmn_action', 'wmn_nonce', 'wmn_id' ) )
				)
			);
		}
	}

	/**
	 * Handles bulk status-change actions submitted from the list table.
	 *
	 * @return void
	 */
	protected function handle_bulk_actions(): void {
		if ( empty( $_POST['wmn_bulk_action'] ) || empty( $_POST['wmn_bulk_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['wmn_bulk_nonce'] ), 'wmn_bulk_action' ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = sanitize_key( $_POST['wmn_bulk_action'] );
		$ids    = array_map( 'absint', (array) ( $_POST['wmn_ids'] ?? array() ) );

		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$map = array(
			'suspend'    => 'suspended',
			'reactivate' => 'active',
			'revoke'     => 'revoked',
		);

		if ( isset( $map[ $action ] ) ) {
			$new_status = $map[ $action ];

			// Fetch old statuses for audit before updating.
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$old_rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					"SELECT id, status FROM {$wpdb->prefix}wmn_member_numbers WHERE id IN ($placeholders)",
					$ids
				),
				ARRAY_A
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$wpdb->prefix}wmn_member_numbers SET status = %s WHERE id IN ($placeholders)",
					array_merge( array( $new_status ), $ids )
				)
			);
			foreach ( $old_rows as $row ) {
				if ( $row['status'] !== $new_status ) {
					WMN_Member_Number_Audit::log( (int) $row['id'], 'status', $row['status'], $new_status );
				}
			}
			$this->redirect_and_exit(
				add_query_arg(
					'wmn_message',
					rawurlencode(
						sprintf(
							/* translators: %d: number of updated records */
							_n( '%d number updated.', '%d numbers updated.', count( $ids ), 'wmn' ),
							count( $ids )
						)
					),
					remove_query_arg( array( 'wmn_bulk_action', 'wmn_bulk_nonce', 'wmn_ids' ) )
				)
			);
		}
	}

	/**
	 * Handles the manual number assignment form submission.
	 *
	 * @return void
	 */
	protected function handle_manual_assign(): void {
		if ( empty( $_POST['wmn_do_manual_assign'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['wmn_manual_nonce'] ?? '' ), 'wmn_manual_assign' ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;

		$user_id  = absint( $_POST['wmn_user_id'] ?? 0 );
		$order_id = absint( $_POST['wmn_order_id'] ?? 0 );
		$number   = sanitize_text_field( wp_unslash( $_POST['wmn_manual_number'] ?? '' ) );

		$formatter       = wmn_get_formatter();
		$assignment_type = 'manual';

		if ( '' === $number ) {
			// Auto-generate.
			$assignment_type = 'auto';
			$sequence        = (int) get_option( 'wmn_last_sequence', 0 );
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
			} while ( $exists );
			update_option( 'wmn_last_sequence', $sequence, false );
		} else {
			// Format the number: if purely numeric treat as a sequence value so it
			// gets prefix and zero-padding; otherwise use the entered value as-is.
			if ( ctype_digit( $number ) ) {
				$number = $formatter->generate( (int) $number );
			}

			// Validate uniqueness.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$wpdb->prefix}wmn_member_numbers WHERE member_number = %s LIMIT 1",
					$number
				)
			);
			if ( $exists ) {
				$this->redirect_and_exit(
					add_query_arg(
						'wmn_message',
						rawurlencode(
							sprintf(
								/* translators: %s: member number */
								__( 'Number %s is already taken.', 'wmn' ),
								$number
							)
						)
					)
				);
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'wmn_member_numbers',
			array(
				'member_number'   => $number,
				'user_id'         => ( ! empty( $user_id ) ? $user_id : null ),
				'order_id'        => $order_id,
				'status'          => 'active',
				'assignment_type' => $assignment_type,
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);

		$new_id = (int) $wpdb->insert_id;
		if ( $new_id ) {
			WMN_Member_Number_Audit::log( $new_id, 'created', '', $number );
		}

		if ( $user_id ) {
			update_user_meta( $user_id, '_wmn_member_number', $number );
			update_user_meta( $user_id, '_wmn_member_number_order_id', $order_id );
		}

		$this->redirect_and_exit(
			add_query_arg(
				'wmn_message',
				rawurlencode(
					sprintf(
						/* translators: 1: label, 2: number */
						__( '%1$s %2$s assigned.', 'wmn' ),
						wmn_get_label(),
						$number
					)
				),
				remove_query_arg( 'action' )
			)
		);
	}

	/**
	 * Redirects to the given URL and terminates the request.
	 *
	 * Extracted as a protected method so test doubles can override it without
	 * actually calling exit.
	 *
	 * @param string $url The URL to redirect to.
	 * @return void
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
