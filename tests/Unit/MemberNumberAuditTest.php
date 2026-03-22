<?php
/**
 * Tests for WMN_Member_Number_Audit — the value object and static DB helpers.
 *
 * Covered:
 *   - Constructor property hydration
 *   - log() inserts a row into the audit table with the right data
 *   - log() resolves admin_user_id from the current session when not supplied
 *   - get_by_record_id() returns an array of WMN_Member_Number_Audit instances
 *   - get_by_record_id() returns an empty array when no rows exist
 *   - get_by_user_id() returns an empty array when the user has no member number
 *   - get_by_user_id() delegates to get_by_record_id() using the record's primary key
 */

declare( strict_types=1 );

use WP_Mock\Tools\TestCase;

// ── $wpdb spy ──────────────────────────────────────────────────────────────

/**
 * Records every DML call so assertions can inspect what was written.
 */
class AuditSpyWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 99;

	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public mixed $get_var_return     = null;
	public mixed $get_row_return     = null;
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
		return $this->get_var_return;
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
}

// ── Test case ─────────────────────────────────────────────────────────────

class MemberNumberAuditTest extends TestCase {

	private AuditSpyWpdb $wpdb;

	// ── Fixtures ──────────────────────────────────────────────────────────

	public function setUp(): void {
		parent::setUp();
		$this->wpdb      = new AuditSpyWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	public function tearDown(): void {
		parent::tearDown();
		Mockery::close();
	}

	// ── Constructor tests ─────────────────────────────────────────────────

	/** @test */
	public function test_constructor_defaults_are_correct(): void {
		$entry = new WMN_Member_Number_Audit();

		$this->assertSame( 0, $entry->id );
		$this->assertSame( 0, $entry->member_number_id );
		$this->assertNull( $entry->admin_user_id );
		$this->assertSame( '', $entry->changed_at );
		$this->assertSame( '', $entry->field_changed );
		$this->assertSame( '', $entry->old_value );
		$this->assertSame( '', $entry->new_value );
	}

	/** @test */
	public function test_constructor_hydrates_all_properties_from_row_array(): void {
		$entry = new WMN_Member_Number_Audit(
			array(
				'id'               => '7',
				'member_number_id' => '42',
				'admin_user_id'    => '3',
				'changed_at'       => '2025-01-15 10:00:00',
				'field_changed'    => 'status',
				'old_value'        => 'active',
				'new_value'        => 'suspended',
			)
		);

		$this->assertSame( 7, $entry->id );
		$this->assertSame( 42, $entry->member_number_id );
		$this->assertSame( 3, $entry->admin_user_id );
		$this->assertSame( '2025-01-15 10:00:00', $entry->changed_at );
		$this->assertSame( 'status', $entry->field_changed );
		$this->assertSame( 'active', $entry->old_value );
		$this->assertSame( 'suspended', $entry->new_value );
	}

	/** @test */
	public function test_constructor_hydrates_nullable_admin_user_id_as_null(): void {
		$entry = new WMN_Member_Number_Audit(
			array(
				'id'               => '1',
				'member_number_id' => '10',
				'admin_user_id'    => null,
				'changed_at'       => '2025-01-01 00:00:00',
				'field_changed'    => 'created',
				'old_value'        => '',
				'new_value'        => 'MBR-000001',
			)
		);

		$this->assertNull( $entry->admin_user_id );
	}

	// ── log() tests ───────────────────────────────────────────────────────

	/** @test */
	public function test_log_inserts_into_audit_table_with_provided_admin_user_id(): void {
		WMN_Member_Number_Audit::log( 5, 'status', 'active', 'suspended', 99 );

		$inserts = $this->wpdb->calls_to( 'insert' );
		$this->assertCount( 1, $inserts );

		$data = $inserts[0]['data'];
		$this->assertSame( 5, $data['member_number_id'] );
		$this->assertSame( 99, $data['admin_user_id'] );
		$this->assertSame( 'status', $data['field_changed'] );
		$this->assertSame( 'active', $data['old_value'] );
		$this->assertSame( 'suspended', $data['new_value'] );
		$this->assertStringContainsString( 'wmn_member_number_audit', $inserts[0]['table'] );
	}

	/** @test */
	public function test_log_resolves_admin_user_id_from_current_session(): void {
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 7 ) );

		WMN_Member_Number_Audit::log( 3, 'notes', 'old note', 'new note' );

		$data = $this->wpdb->calls_to( 'insert' )[0]['data'];
		$this->assertSame( 7, $data['admin_user_id'] );
	}

	/** @test */
	public function test_log_stores_null_admin_user_when_not_logged_in(): void {
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 0 ) );

		WMN_Member_Number_Audit::log( 3, 'notes', 'old', 'new' );

		$data = $this->wpdb->calls_to( 'insert' )[0]['data'];
		$this->assertNull( $data['admin_user_id'] );
	}

	// ── get_by_record_id() tests ──────────────────────────────────────────

	/** @test */
	public function test_get_by_record_id_returns_empty_array_when_no_rows(): void {
		$this->wpdb->get_results_return = array();

		$result = WMN_Member_Number_Audit::get_by_record_id( 1 );

		$this->assertSame( array(), $result );
	}

	/** @test */
	public function test_get_by_record_id_returns_array_of_audit_instances(): void {
		$this->wpdb->get_results_return = array(
			array(
				'id'               => '10',
				'member_number_id' => '5',
				'admin_user_id'    => '1',
				'changed_at'       => '2025-06-01 09:00:00',
				'field_changed'    => 'assignment_type',
				'old_value'        => 'auto',
				'new_value'        => 'manual',
			),
			array(
				'id'               => '9',
				'member_number_id' => '5',
				'admin_user_id'    => null,
				'changed_at'       => '2025-05-01 08:00:00',
				'field_changed'    => 'created',
				'old_value'        => '',
				'new_value'        => 'MBR-000005',
			),
		);

		$result = WMN_Member_Number_Audit::get_by_record_id( 5 );

		$this->assertCount( 2, $result );
		$this->assertContainsOnlyInstancesOf( WMN_Member_Number_Audit::class, $result );
		$this->assertSame( 10, $result[0]->id );
		$this->assertSame( 'assignment_type', $result[0]->field_changed );
		$this->assertSame( 9, $result[1]->id );
		$this->assertNull( $result[1]->admin_user_id );
	}

	// ── get_by_user_id() tests ────────────────────────────────────────────

	/** @test */
	public function test_get_by_user_id_returns_empty_array_when_user_has_no_member_number(): void {
		// get_row returns null → no record for user.
		$this->wpdb->get_row_return = null;

		$result = WMN_Member_Number_Audit::get_by_user_id( 999 );

		$this->assertSame( array(), $result );
	}

	/** @test */
	public function test_get_by_user_id_returns_audit_entries_for_the_users_record(): void {
		// get_row returns a member number record with id=7.
		$this->wpdb->get_row_return = array(
			'id'              => '7',
			'member_number'   => 'MBR-000007',
			'user_id'         => '42',
			'order_id'        => '0',
			'assigned_at'     => '2025-01-01 00:00:00',
			'status'          => 'active',
			'assignment_type' => 'manual',
			'notes'           => '',
		);

		$this->wpdb->get_results_return = array(
			array(
				'id'               => '20',
				'member_number_id' => '7',
				'admin_user_id'    => '1',
				'changed_at'       => '2025-06-01 10:00:00',
				'field_changed'    => 'member_number',
				'old_value'        => 'MBR-000003',
				'new_value'        => 'MBR-000007',
			),
		);

		$result = WMN_Member_Number_Audit::get_by_user_id( 42 );

		$this->assertCount( 1, $result );
		$this->assertSame( 20, $result[0]->id );
		$this->assertSame( 'member_number', $result[0]->field_changed );
	}
}
