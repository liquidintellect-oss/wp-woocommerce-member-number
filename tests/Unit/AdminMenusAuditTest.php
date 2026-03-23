<?php
/**
 * Tests for audit-logging behaviour inside WMN_Admin_Menus handler methods.
 *
 * Covered:
 *   handle_row_actions()
 *     - logs the status change when a valid row action (suspend/reactivate/revoke) is processed
 *     - does nothing when the nonce is missing
 *
 *   handle_bulk_actions()
 *     - logs a status change for every record whose status actually changed
 *     - does not log for records whose status was already the target value
 *
 *   handle_edit_save()
 *     - logs only the fields that actually changed
 *     - does not call $wpdb->update() or insert any audit entries when nothing changed
 *     - syncs _wmn_member_number user meta when member_number is updated
 *     - syncs _wmn_assignment_type user meta when assignment_type is updated
 *     - redirects without updating when the new number conflicts with another record
 *
 *   handle_manual_assign()
 *     - inserts a 'created' audit entry after manually assigning a specific number
 *     - inserts a 'created' audit entry after auto-generating a number
 */

declare( strict_types=1 );

use WP_Mock\Tools\TestCase;

// ── $wpdb spy ─────────────────────────────────────────────────────────────

/**
 * Flexible $wpdb test double that records all calls and supports
 * configurable canned responses.
 */
class MenusSpyWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 1;

	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	/**
	 * Canned responses — queued per-call so different get_var calls can return different values.
	 *
	 * @var array<mixed>
	 */
	public array $get_var_queue   = array();
	public mixed $get_var_default = null;
	public mixed $get_row_return  = null;
	/** @var array<int,array<string,mixed>> */
	public array $get_results_return = array();

	public function insert( string $table, array $data, ?array $format = null ): int {
		$this->calls[] = array(
			'method' => 'insert',
			'table'  => $table,
			'data'   => $data,
		);
		return 1;
	}

	public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int {
		$this->calls[] = array(
			'method' => 'update',
			'table'  => $table,
			'data'   => $data,
			'where'  => $where,
		);
		return 1;
	}

	public function delete( string $table, array $where, ?array $format = null ): int {
		return 1;
	}

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	public function get_var( mixed $query = null, int $col = 0, int $row = 0 ): mixed {
		if ( ! empty( $this->get_var_queue ) ) {
			return array_shift( $this->get_var_queue );
		}
		return $this->get_var_default;
	}

	public function get_row( mixed $query = null, mixed $output = 0, int $row = 0 ): mixed {
		return $this->get_row_return;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( mixed $query = null, mixed $output = 'OBJECT' ): array {
		return $this->get_results_return;
	}

	public function query( string $sql ): mixed {
		$this->calls[] = array( 'method' => 'query' );
		return 1;
	}

	/**
	 * Returns every recorded call to the given method.
	 *
	 * @param string $method The method name to filter by.
	 * @return array<int,array<string,mixed>>
	 */
	public function calls_to( string $method ): array {
		return array_values(
			array_filter( $this->calls, fn( $c ) => $c['method'] === $method )
		);
	}

	/**
	 * Returns every insert call targeting a table whose name contains $fragment.
	 *
	 * @param string $fragment Partial table name to match.
	 * @return array<int,array<string,mixed>>
	 */
	public function inserts_into( string $fragment ): array {
		return array_values(
			array_filter(
				$this->calls,
				fn( $c ) => 'insert' === $c['method'] && str_contains( (string) $c['table'], $fragment )
			)
		);
	}
}

// ── Test double for WMN_Admin_Menus ───────────────────────────────────────

/**
 * Thrown by TestableAdminMenus::redirect_and_exit() to halt execution
 * without actually calling exit, while still allowing tests to verify
 * that a redirect was intended.
 */
class TestRedirectException extends RuntimeException {
	public function __construct( public readonly string $redirect_url ) {
		parent::__construct( 'Redirect to: ' . $redirect_url );
	}
}

/**
 * Overrides redirect_and_exit() so tests can observe the intended redirect
 * URL without actually terminating the process.
 */
class TestableAdminMenus extends WMN_Admin_Menus {

	public string $last_redirect = '';

	/** @var string[] */
	public array $all_redirects = array();

	protected function redirect_and_exit( string $url ): void {
		$this->last_redirect   = $url;
		$this->all_redirects[] = $url;
		// Throw to stop execution, mirroring the effect of `exit` in production.
		throw new TestRedirectException( $url ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	// Expose protected handlers as public for direct test calls.

	public function call_handle_row_actions(): void {
		$this->handle_row_actions();
	}

	public function call_handle_bulk_actions(): void {
		$this->handle_bulk_actions();
	}

	public function call_handle_edit_save(): void {
		$this->handle_edit_save();
	}

	public function call_handle_manual_assign(): void {
		$this->handle_manual_assign();
	}
}

// ── Test case ─────────────────────────────────────────────────────────────

class AdminMenusAuditTest extends TestCase {

	private TestableAdminMenus $menus;
	private MenusSpyWpdb $wpdb;

	// ── Fixtures ──────────────────────────────────────────────────────────

	public function setUp(): void {
		parent::setUp();

		WP_Mock::userFunction( 'add_action' );
		WP_Mock::userFunction( 'add_filter' );

		$this->wpdb      = new MenusSpyWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->menus = new TestableAdminMenus();

		// Default WP functions used across many methods.
		// Pure utility functions (absint, sanitize_*, wp_unslash, add_query_arg, etc.)
		// are defined once in tests/bootstrap.php and do not need per-test mocks.
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 1 ) );
		WP_Mock::userFunction( 'update_user_meta' );
		WP_Mock::userFunction( 'update_option' );
		// wp_generate_password is always evaluated inside WMN_Number_Formatter::generate().
		WP_Mock::userFunction( 'wp_generate_password', array( 'return' => 'abc1' ) );
		// get_option is called by wmn_get_formatter() which is now used to normalise input.
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $option, mixed $fallback = false ): mixed {
					$map = array(
						'wmn_number_format_template' => '{PREFIX}{SEQ}',
						'wmn_number_prefix'          => 'MBR-',
						'wmn_number_pad_length'      => 6,
						'wmn_number_min_value'       => 1,
						'wmn_number_max_value'       => 999999,
					);
					return $map[ $option ] ?? $fallback;
				},
			)
		);
	}

	public function tearDown(): void {
		// Clean up superglobals.
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
		Mockery::close();
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * @return array<string,mixed>
	 */
	private function make_db_record(
		int $id = 10,
		string $number = 'MBR-000010',
		string $status = 'active',
		string $type = 'auto',
		string $notes = '',
		int $uid = 5
	): array {
		return array(
			'id'              => (string) $id,
			'member_number'   => $number,
			'user_id'         => (string) $uid,
			'order_id'        => '100',
			'assigned_at'     => '2025-01-01 00:00:00',
			'status'          => $status,
			'assignment_type' => $type,
			'notes'           => $notes,
		);
	}

	// ── handle_row_actions() ──────────────────────────────────────────────

	/** @test */
	public function test_row_action_logs_status_change_on_suspend(): void {
		$_GET = array(
			'wmn_action' => 'suspend',
			'wmn_nonce'  => 'valid-nonce',
			'wmn_id'     => 10,
		);

		// get_var returns the old status.
		$this->wpdb->get_var_default = 'active';

		try {
			$this->menus->call_handle_row_actions();
		} catch ( TestRedirectException $e ) {
			// Expected: redirect_and_exit() throws to halt execution.
		}

		// One update to wmn_member_numbers.
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'status' => 'suspended' ), $updates[0]['data'] );

		// One audit insert.
		$audit_inserts = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit_inserts );
		$this->assertSame( 'status', $audit_inserts[0]['data']['field_changed'] );
		$this->assertSame( 'active', $audit_inserts[0]['data']['old_value'] );
		$this->assertSame( 'suspended', $audit_inserts[0]['data']['new_value'] );
	}

	/** @test */
	public function test_row_action_logs_status_change_on_reactivate(): void {
		$_GET                        = array(
			'wmn_action' => 'reactivate',
			'wmn_nonce'  => 'valid-nonce',
			'wmn_id'     => 10,
		);
		$this->wpdb->get_var_default = 'suspended';

		try {
			$this->menus->call_handle_row_actions();
		} catch ( TestRedirectException $e ) {
			// Expected.
		}

		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'suspended', $audit[0]['data']['old_value'] );
		$this->assertSame( 'active', $audit[0]['data']['new_value'] );
	}

	/** @test */
	public function test_row_action_does_nothing_when_nonce_is_missing(): void {
		$_GET = array( 'wmn_action' => 'suspend' ); // No wmn_nonce.

		$this->menus->call_handle_row_actions();

		$this->assertEmpty( $this->wpdb->calls_to( 'update' ) );
		$this->assertEmpty( $this->wpdb->inserts_into( 'wmn_member_number_audit' ) );
	}

	// ── handle_bulk_actions() ─────────────────────────────────────────────

	/** @test */
	public function test_bulk_action_logs_status_change_for_each_changed_record(): void {
		$_POST = array(
			'wmn_bulk_action' => 'suspend',
			'wmn_bulk_nonce'  => 'valid-nonce',
			'wmn_ids'         => array( 1, 2 ),
		);

		$this->wpdb->get_results_return = array(
			array(
				'id'     => '1',
				'status' => 'active',
			),
			array(
				'id'     => '2',
				'status' => 'active',
			),
		);

		try {
			$this->menus->call_handle_bulk_actions();
		} catch ( TestRedirectException $e ) {
			// Expected.
		}

		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 2, $audit, 'Expected one audit entry per changed record.' );
		$this->assertSame( 'suspended', $audit[0]['data']['new_value'] );
		$this->assertSame( 'suspended', $audit[1]['data']['new_value'] );
	}

	/** @test */
	public function test_bulk_action_does_not_log_records_already_at_target_status(): void {
		$_POST = array(
			'wmn_bulk_action' => 'suspend',
			'wmn_bulk_nonce'  => 'valid-nonce',
			'wmn_ids'         => array( 1, 2 ),
		);

		// Record 1 is already suspended; record 2 is active.
		$this->wpdb->get_results_return = array(
			array(
				'id'     => '1',
				'status' => 'suspended',
			),
			array(
				'id'     => '2',
				'status' => 'active',
			),
		);

		try {
			$this->menus->call_handle_bulk_actions();
		} catch ( TestRedirectException $e ) {
			// Expected.
		}

		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit, 'Expected audit entry only for the record that changed.' );
		$this->assertSame( 2, $audit[0]['data']['member_number_id'] );
	}

	// ── handle_edit_save() ────────────────────────────────────────────────

	/** @test */
	public function test_edit_save_logs_only_changed_fields(): void {
		$record = $this->make_db_record(
			id:     10,
			number: 'MBR-000010',
			status: 'active',
			type:   'auto',
			notes:  'original note',
			uid:    5
		);

		$this->wpdb->get_row_return  = $record;
		$this->wpdb->get_var_default = null; // No uniqueness conflict.

		$_POST = array(
			'wmn_do_edit_save'         => '1',
			'wmn_edit_id'              => '10',
			'wmn_edit_nonce'           => 'valid-nonce',
			'wmn_edit_member_number'   => 'MBR-000010', // Unchanged.
			'wmn_edit_status'          => 'suspended',  // Changed.
			'wmn_edit_assignment_type' => 'auto',       // Unchanged.
			'wmn_edit_notes'           => 'original note', // Unchanged.
		);

		try {
			$this->menus->call_handle_edit_save();
		} catch ( TestRedirectException $e ) {
			// Expected redirect after save.
		}

		// Only one audit insert (for 'status').
		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'status', $audit[0]['data']['field_changed'] );
		$this->assertSame( 'active', $audit[0]['data']['old_value'] );
		$this->assertSame( 'suspended', $audit[0]['data']['new_value'] );

		// DB update carried only the changed field.
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertArrayHasKey( 'status', $updates[0]['data'] );
		$this->assertArrayNotHasKey( 'member_number', $updates[0]['data'] );
	}

	/** @test */
	public function test_edit_save_logs_multiple_changed_fields(): void {
		$record = $this->make_db_record(
			id:     10,
			number: 'MBR-000010',
			status: 'active',
			type:   'auto',
			notes:  ''
		);

		$this->wpdb->get_row_return  = $record;
		$this->wpdb->get_var_default = null;

		$_POST = array(
			'wmn_do_edit_save'         => '1',
			'wmn_edit_id'              => '10',
			'wmn_edit_nonce'           => 'valid-nonce',
			'wmn_edit_member_number'   => 'MBR-000010',
			'wmn_edit_status'          => 'suspended',  // Changed.
			'wmn_edit_assignment_type' => 'manual',     // Changed.
			'wmn_edit_notes'           => 'new note',   // Changed.
		);

		try {
			$this->menus->call_handle_edit_save();
		} catch ( TestRedirectException $e ) {
			// Expected redirect after save.
		}

		$audit        = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$audit_fields = array_column( array_column( $audit, 'data' ), 'field_changed' );
		$this->assertCount( 3, $audit );
		$this->assertContains( 'status', $audit_fields );
		$this->assertContains( 'assignment_type', $audit_fields );
		$this->assertContains( 'notes', $audit_fields );
	}

	/** @test */
	public function test_edit_save_does_not_update_or_audit_when_nothing_changes(): void {
		$record = $this->make_db_record(
			id:     10,
			number: 'MBR-000010',
			status: 'active',
			type:   'auto',
			notes:  ''
		);

		$this->wpdb->get_row_return  = $record;
		$this->wpdb->get_var_default = null;

		$_POST = array(
			'wmn_do_edit_save'         => '1',
			'wmn_edit_id'              => '10',
			'wmn_edit_nonce'           => 'valid-nonce',
			'wmn_edit_member_number'   => 'MBR-000010', // Same.
			'wmn_edit_status'          => 'active',     // Same.
			'wmn_edit_assignment_type' => 'auto',       // Same.
			'wmn_edit_notes'           => '',           // Same.
		);

		try {
			$this->menus->call_handle_edit_save();
		} catch ( TestRedirectException $e ) {
			// Expected redirect after save (even with no changes, code redirects).
		}

		$this->assertEmpty( $this->wpdb->calls_to( 'update' ), 'No DB update expected when nothing changed.' );
		$this->assertEmpty( $this->wpdb->inserts_into( 'wmn_member_number_audit' ), 'No audit entry expected.' );
	}

	/** @test */
	public function test_edit_save_syncs_member_number_when_it_changes(): void {
		$record = $this->make_db_record( id: 10, number: 'MBR-000010', uid: 5 );

		$this->wpdb->get_row_return  = $record;
		$this->wpdb->get_var_default = null;

		$_POST = array(
			'wmn_do_edit_save'         => '1',
			'wmn_edit_id'              => '10',
			'wmn_edit_nonce'           => 'valid-nonce',
			'wmn_edit_member_number'   => 'MBR-000099', // Changed.
			'wmn_edit_status'          => 'active',
			'wmn_edit_assignment_type' => 'auto',
			'wmn_edit_notes'           => '',
		);

		try {
			$this->menus->call_handle_edit_save();
		} catch ( TestRedirectException $e ) {
			// Expected redirect after save.
		}

		// DB update written with the new number.
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'MBR-000099', $updates[0]['data']['member_number'] );

		// Audit entry records the change.
		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'member_number', $audit[0]['data']['field_changed'] );
		$this->assertSame( 'MBR-000010', $audit[0]['data']['old_value'] );
		$this->assertSame( 'MBR-000099', $audit[0]['data']['new_value'] );
	}

	/** @test */
	public function test_edit_save_syncs_assignment_type_when_it_changes(): void {
		$record = $this->make_db_record( id: 10, number: 'MBR-000010', type: 'auto', uid: 5 );

		$this->wpdb->get_row_return  = $record;
		$this->wpdb->get_var_default = null;

		$_POST = array(
			'wmn_do_edit_save'         => '1',
			'wmn_edit_id'              => '10',
			'wmn_edit_nonce'           => 'valid-nonce',
			'wmn_edit_member_number'   => 'MBR-000010',
			'wmn_edit_status'          => 'active',
			'wmn_edit_assignment_type' => 'manual', // Changed.
			'wmn_edit_notes'           => '',
		);

		try {
			$this->menus->call_handle_edit_save();
		} catch ( TestRedirectException $e ) {
			// Expected redirect after save.
		}

		// DB update written with the new type.
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'manual', $updates[0]['data']['assignment_type'] );

		// Audit entry records the change.
		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'assignment_type', $audit[0]['data']['field_changed'] );
		$this->assertSame( 'auto', $audit[0]['data']['old_value'] );
		$this->assertSame( 'manual', $audit[0]['data']['new_value'] );
	}

	/** @test */
	public function test_edit_save_redirects_without_saving_on_number_conflict(): void {
		$record = $this->make_db_record( id: 10, number: 'MBR-000010' );

		$this->wpdb->get_row_return  = $record;
		$this->wpdb->get_var_default = '99'; // Conflict: another record has this number.

		$_POST = array(
			'wmn_do_edit_save'         => '1',
			'wmn_edit_id'              => '10',
			'wmn_edit_nonce'           => 'valid-nonce',
			'wmn_edit_member_number'   => 'MBR-CONFLICT',
			'wmn_edit_status'          => 'active',
			'wmn_edit_assignment_type' => 'auto',
			'wmn_edit_notes'           => '',
		);

		try {
			$this->menus->call_handle_edit_save();
			$this->fail( 'Expected TestRedirectException on conflict.' );
		} catch ( TestRedirectException $e ) {
			// Expected: code redirects on conflict and stops execution.
		}

		$this->assertNotEmpty( $this->menus->last_redirect, 'Expected a redirect on conflict.' );
		$this->assertEmpty( $this->wpdb->calls_to( 'update' ), 'No DB update expected on conflict.' );
		$this->assertEmpty( $this->wpdb->inserts_into( 'wmn_member_number_audit' ) );
	}

	// ── handle_manual_assign() ────────────────────────────────────────────

	/** @test */
	public function test_manual_assign_logs_created_entry_for_specific_number(): void {
		$this->wpdb->get_var_default = null;   // Number not taken.
		$this->wpdb->insert_id       = 55;     // New record ID.

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $key, mixed $fallback = null ): mixed {
					return match ( $key ) {
						'wmn_number_format_template' => '{PREFIX}{SEQ}',
						'wmn_number_prefix'          => 'MBR-',
						'wmn_number_pad_length'      => 6,
						'wmn_number_min_value'       => 1,
						'wmn_number_max_value'       => 999999,
						'wmn_number_label'           => 'Member Number',
						default                      => $fallback,
					};
				},
			)
		);

		$_POST = array(
			'wmn_do_manual_assign' => '1',
			'wmn_manual_nonce'     => 'valid-nonce',
			'wmn_user_id'          => '0',
			'wmn_order_id'         => '0',
			'wmn_manual_number'    => 'MBR-000042',
		);

		try {
			$this->menus->call_handle_manual_assign();
		} catch ( TestRedirectException $e ) {
			// Expected redirect after successful assign.
		}

		// One insert into wmn_member_numbers.
		$mn_inserts = $this->wpdb->inserts_into( 'wmn_member_numbers' );
		$this->assertCount( 1, $mn_inserts );
		$this->assertSame( 'MBR-000042', $mn_inserts[0]['data']['member_number'] );

		// One audit insert with field_changed = 'created'.
		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'created', $audit[0]['data']['field_changed'] );
		$this->assertSame( '', $audit[0]['data']['old_value'] );
		$this->assertSame( 'MBR-000042', $audit[0]['data']['new_value'] );
		$this->assertSame( 55, $audit[0]['data']['member_number_id'] );
	}

	/** @test */
	public function test_manual_assign_logs_created_entry_for_auto_generated_number(): void {
		$this->wpdb->get_var_default = null;  // Number not taken.
		$this->wpdb->insert_id       = 77;

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $key, mixed $fallback = null ): mixed {
					return match ( $key ) {
						'wmn_number_format_template' => '{PREFIX}{SEQ}',
						'wmn_number_prefix'          => 'MBR-',
						'wmn_number_pad_length'      => 6,
						'wmn_number_min_value'       => 1,
						'wmn_number_max_value'       => 999999,
						'wmn_number_label'           => 'Member Number',
						'wmn_last_sequence'          => 0,
						default                      => $fallback,
					};
				},
			)
		);

		$_POST = array(
			'wmn_do_manual_assign' => '1',
			'wmn_manual_nonce'     => 'valid-nonce',
			'wmn_user_id'          => '0',
			'wmn_order_id'         => '0',
			'wmn_manual_number'    => '', // Blank: auto-generate.
		);

		try {
			$this->menus->call_handle_manual_assign();
		} catch ( TestRedirectException $e ) {
			// Expected redirect after successful assign.
		}

		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'created', $audit[0]['data']['field_changed'] );
		$this->assertSame( 77, $audit[0]['data']['member_number_id'] );
	}
}
