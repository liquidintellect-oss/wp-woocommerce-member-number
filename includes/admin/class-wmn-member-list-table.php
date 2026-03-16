<?php
/**
 * WP_List_Table subclass for the members admin list page.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the paginated, sortable, searchable member number list table.
 */
class WMN_Member_List_Table extends WP_List_Table {

	/**
	 * Constructor — passes configuration to the parent WP_List_Table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wmn_member',
				'plural'   => 'wmn_members',
				'ajax'     => false,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Columns
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Returns the list of columns for this table.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'              => '<input type="checkbox" />',
			'member_number'   => wmn_get_label(),
			'assignment_type' => __( 'Type', 'wmn' ),
			'user'            => __( 'User', 'wmn' ),
			'order'           => __( 'Order', 'wmn' ),
			'status'          => __( 'Status', 'wmn' ),
			'assigned_at'     => __( 'Assigned', 'wmn' ),
		);
	}

	/**
	 * Returns the list of sortable columns.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function get_sortable_columns(): array {
		return array(
			'member_number' => array( 'member_number', false ),
			'assigned_at'   => array( 'assigned_at', true ),
			'status'        => array( 'status', false ),
		);
	}

	/**
	 * Returns the list of bulk actions available on this table.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'suspend'    => __( 'Suspend', 'wmn' ),
			'reactivate' => __( 'Reactivate', 'wmn' ),
			'revoke'     => __( 'Revoke', 'wmn' ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Column renderers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Renders the checkbox column for bulk actions.
	 *
	 * @param array<string,mixed> $item The current row data.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="wmn_ids[]" value="' . absint( $item['id'] ) . '" />';
	}

	/**
	 * Renders the member number column with inline row actions.
	 *
	 * @param array<string,mixed> $item The current row data.
	 * @return string
	 */
	protected function column_member_number( array $item ): string {
		$nonce = wp_create_nonce( 'wmn_row_action' );
		$base  = add_query_arg(
			array(
				'wmn_nonce' => $nonce,
				'wmn_id'    => $item['id'],
			)
		);

		$actions = array();

		if ( 'active' === $item['status'] || 'revoked' === $item['status'] ) {
			$actions['suspend'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'wmn_action', 'suspend', $base ) ),
				esc_html__( 'Suspend', 'wmn' )
			);
		}
		if ( 'suspended' === $item['status'] ) {
			$actions['reactivate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( 'wmn_action', 'reactivate', $base ) ),
				esc_html__( 'Reactivate', 'wmn' )
			);
		}
		if ( 'revoked' !== $item['status'] ) {
			$actions['revoke'] = sprintf(
				'<a href="%s" class="wmn-confirm-revoke" style="color:#a00;">%s</a>',
				esc_url( add_query_arg( 'wmn_action', 'revoke', $base ) ),
				esc_html__( 'Revoke', 'wmn' )
			);
		}

		return '<strong>' . esc_html( $item['member_number'] ) . '</strong>' .
				$this->row_actions( $actions );
	}

	/**
	 * Renders the assignment type column as a badge.
	 *
	 * @param array<string,mixed> $item The current row data.
	 * @return string
	 */
	protected function column_assignment_type( array $item ): string {
		switch ( $item['assignment_type'] ) {
			case 'chosen':
				return '<span class="wmn-badge wmn-badge-chosen">' . esc_html__( 'Chosen', 'wmn' ) . '</span>';
			case 'manual':
				return '<span class="wmn-badge wmn-badge-manual">' . esc_html__( 'Manual', 'wmn' ) . '</span>';
			default:
				return '<span class="wmn-badge wmn-badge-auto">' . esc_html__( 'Auto', 'wmn' ) . '</span>';
		}
	}

	/**
	 * Renders the user column with a link to the edit-user screen.
	 *
	 * @param array<string,mixed> $item The current row data.
	 * @return string
	 */
	protected function column_user( array $item ): string {
		if ( empty( $item['user_id'] ) ) {
			// Try to get billing email from order.
			$order = wc_get_order( $item['order_id'] );
			$email = $order ? $order->get_billing_email() : '';
			return '<em>' . esc_html(
				sprintf(
					/* translators: %s: guest billing email in parentheses, or empty string */
					__( 'Guest%s', 'wmn' ),
					$email ? " ($email)" : ''
				)
			) . '</em>';
		}
		$user = get_userdata( (int) $item['user_id'] );
		if ( ! $user ) {
			return '<em>' . esc_html__( 'Deleted user', 'wmn' ) . '</em>';
		}
		$edit_url = get_edit_user_link( $user->ID );
		return '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $user->display_name ) . '</a>';
	}

	/**
	 * Renders the order column with a link to the edit-order screen.
	 *
	 * @param array<string,mixed> $item The current row data.
	 * @return string
	 */
	protected function column_order( array $item ): string {
		if ( empty( $item['order_id'] ) ) {
			return '—';
		}
		$order = wc_get_order( $item['order_id'] );
		if ( ! $order ) {
			return '#' . absint( $item['order_id'] );
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' .
				esc_html( $order->get_order_number() ) . '</a>';
	}

	/**
	 * Renders the status column as a coloured badge.
	 *
	 * @param array<string,mixed> $item The current row data.
	 * @return string
	 */
	protected function column_status( array $item ): string {
		$class_map = array(
			'active'    => 'wmn-badge-active',
			'suspended' => 'wmn-badge-suspended',
			'revoked'   => 'wmn-badge-revoked',
		);
		$class     = $class_map[ $item['status'] ] ?? 'wmn-badge-active';
		return '<span class="wmn-badge ' . esc_attr( $class ) . '">' . esc_html( ucfirst( $item['status'] ) ) . '</span>';
	}

	/**
	 * Renders the assigned-at column as a localised date/time string.
	 *
	 * @param array<string,mixed> $item The current row data.
	 * @return string
	 */
	protected function column_assigned_at( array $item ): string {
		return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item['assigned_at'] ) ) );
	}

	/**
	 * Default column renderer — outputs the raw escaped cell value.
	 *
	 * @param array<string,mixed> $item        The current row data.
	 * @param string              $column_name The column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( $item[ $column_name ] ?? '' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Data
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Fetches data from the database and sets pagination/column-header properties.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		global $wpdb;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter = isset( $_REQUEST['wmn_status'] ) ? sanitize_key( $_REQUEST['wmn_status'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter = isset( $_REQUEST['wmn_type'] ) ? sanitize_key( $_REQUEST['wmn_type'] ) : '';

		$where  = array();
		$join   = '';
		$params = array();

		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(mn.member_number LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
			$join    = "LEFT JOIN {$wpdb->users} u ON u.ID = mn.user_id";
			$params  = array_merge( $params, array( $like, $like, $like ) );
		}

		$valid_statuses = array( 'active', 'suspended', 'revoked' );
		if ( $status_filter && in_array( $status_filter, $valid_statuses, true ) ) {
			$where[]  = 'mn.status = %s';
			$params[] = $status_filter;
		}

		if ( 'chosen' === $type_filter ) {
			$where[] = "mn.assignment_type = 'chosen'";
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$orderby_map = array(
			'member_number' => 'mn.member_number',
			'assigned_at'   => 'mn.assigned_at',
			'status'        => 'mn.status',
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby_key = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ?? '' ) );
		$orderby     = $orderby_map[ $orderby_key ] ?? 'mn.assigned_at';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_raw = sanitize_text_field( wp_unslash( $_REQUEST['order'] ?? '' ) );
		$order     = 'ASC' === $order_raw ? 'ASC' : 'DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wmn_member_numbers mn $join $where_sql";
		$total     = $params
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) )
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $total_sql );

		$offset = ( $current_page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data_sql    = "SELECT mn.* FROM {$wpdb->prefix}wmn_member_numbers mn $join $where_sql
		             ORDER BY $orderby $order LIMIT %d OFFSET %d";
		$data_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ), ARRAY_A );

		$this->items = ( ! empty( $items ) ? $items : array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Filter tabs
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Builds the status/type filter tab links shown above the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}wmn_member_numbers GROUP BY status",
			OBJECT_K
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$chosen_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wmn_member_numbers WHERE assignment_type = 'chosen'"
		);

		$base = admin_url( 'admin.php?page=wmn-members' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = sanitize_key( $_REQUEST['wmn_status'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_type = sanitize_key( $_REQUEST['wmn_type'] ?? '' );

		$total = array_sum( array_column( (array) $counts, 'cnt' ) );

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $base ),
				( '' === $current && '' === $current_type ) ? ' class="current"' : '',
				esc_html__( 'All', 'wmn' ),
				$total
			),
		);

		foreach ( array( 'active', 'suspended', 'revoked' ) as $status ) {
			$count            = isset( $counts[ $status ] ) ? (int) $counts[ $status ]->cnt : 0;
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'wmn_status', $status, $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( ucfirst( $status ) ),
				$count
			);
		}

		if ( $chosen_count ) {
			$views['chosen'] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'wmn_type', 'chosen', $base ) ),
				'chosen' === $current_type ? ' class="current"' : '',
				esc_html__( 'Chosen', 'wmn' ),
				$chosen_count
			);
		}

		return $views;
	}

	/**
	 * Renders the table navigation area, injecting bulk-action hidden fields at the top.
	 *
	 * @param string $which Position: 'top' or 'bottom'.
	 * @return void
	 */
	protected function display_tablenav( $which ): void {
		if ( 'top' === $which ) {
			echo '<input type="hidden" name="wmn_bulk_nonce" value="' . esc_attr( wp_create_nonce( 'wmn_bulk_action' ) ) . '" />';
			echo '<input type="hidden" name="wmn_bulk_action" id="wmn-bulk-action" value="" />';
		}
		parent::display_tablenav( $which );
	}
}
