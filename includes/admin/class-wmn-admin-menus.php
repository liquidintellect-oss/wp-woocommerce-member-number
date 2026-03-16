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

		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-member-list-table.php';
		$table = new WMN_Member_List_Table();
		$table->prepare_items();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$show_assign_form = ! empty( $_GET['action'] ) && 'manual_assign' === $_GET['action'];
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
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="wmn-members" />
				<?php $table->search_box( esc_html__( 'Search', 'wmn' ), 'wmn_search' ); ?>
				<?php $table->display(); ?>
			</form>
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
						<th><label for="wmn_order_id"><?php esc_html_e( 'Order ID', 'wmn' ); ?></label></th>
						<td><input type="number" name="wmn_order_id" id="wmn_order_id" class="small-text" min="1" /></td>
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
	 * Handles single-row status-change actions (suspend/reactivate/revoke).
	 *
	 * @return void
	 */
	private function handle_row_actions(): void {
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'wmn_member_numbers',
				array( 'status' => $map[ $action ] ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			wp_safe_redirect(
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
			exit;
		}
	}

	/**
	 * Handles bulk status-change actions submitted from the list table.
	 *
	 * @return void
	 */
	private function handle_bulk_actions(): void {
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
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$wpdb->prefix}wmn_member_numbers SET status = %s WHERE id IN ($placeholders)",
					array_merge( array( $map[ $action ] ), $ids )
				)
			);
			wp_safe_redirect(
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
			exit;
		}
	}

	/**
	 * Handles the manual number assignment form submission.
	 *
	 * @return void
	 */
	private function handle_manual_assign(): void {
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

		if ( ! $order_id ) {
			return;
		}

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
				wp_safe_redirect(
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
				exit;
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

		if ( $user_id ) {
			update_user_meta( $user_id, '_wmn_member_number', $number );
			update_user_meta( $user_id, '_wmn_member_number_order_id', $order_id );
		}

		wp_safe_redirect(
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
		exit;
	}
}
