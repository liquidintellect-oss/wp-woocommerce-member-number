<?php
/**
 * Tests for the audit-logging behaviour in WMN_Admin_User_Profile::save_section().
 *
 * Covered:
 *   - Returns early when the nonce field is absent (no DB writes)
 *   - Logs a 'member_number' change (old → empty) when the number is cleared
 *   - Logs a 'member_number' change when updating an existing record
 *   - Logs a 'created' entry and sets assignment_type to 'manual' for a brand-new record
 *   - Skips everything when the submitted value equals the existing value
 */

declare( strict_types=1 );

use WP_Mock\Tools\TestCase;

// ── $wpdb spy ─────────────────────────────────────────────────────────────

/**
 * Lightweight $wpdb spy for user-profile tests.
 */
class ProfileSpyWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 200;

	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public mixed $get_row_return = null;

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
		return null;
	}

	public function get_row( mixed $query = null, mixed $output = 0, int $row = 0 ): mixed {
		return $this->get_row_return;
	}

	/**
	 * Returns every recorded call to the given method.
	 *
	 * @param string $method Method name to filter by.
	 * @return array<int,array<string,mixed>>
	 */
	public function calls_to( string $method ): array {
		return array_values(
			array_filter( $this->calls, fn( $c ) => $c['method'] === $method )
		);
	}

	/**
	 * Returns every insert call whose table name contains $fragment.
	 *
	 * @param string $fragment Partial table name.
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

// ── Test case ─────────────────────────────────────────────────────────────

class AdminUserProfileSaveTest extends TestCase {

	private WMN_Admin_User_Profile $profile;
	private ProfileSpyWpdb $wpdb;

	// ── Fixtures ──────────────────────────────────────────────────────────

	public function setUp(): void {
		parent::setUp();

		WP_Mock::userFunction( 'add_action' );

		$this->wpdb      = new ProfileSpyWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->profile = new WMN_Admin_User_Profile();

		// Default WP stubs used by save_section().
		// Pure utility functions (sanitize_*, wp_unslash, absint, etc.) are
		// defined once in tests/bootstrap.php and do not need per-test mocks.
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 1 ) );
		WP_Mock::userFunction( 'update_user_meta' );
		WP_Mock::userFunction( 'delete_user_meta' );
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
		$_POST = array();
		parent::tearDown();
		Mockery::close();
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Builds a raw DB row array for WMN_Member_Number::get_by_user().
	 *
	 * @param string $number Current member number.
	 * @return array<string,mixed>
	 */
	private function make_member_row( string $number = 'MBR-000001' ): array {
		return array(
			'id'              => '10',
			'member_number'   => $number,
			'user_id'         => '42',
			'order_id'        => '0',
			'assigned_at'     => '2025-01-01 00:00:00',
			'status'          => 'active',
			'assignment_type' => 'auto',
			'notes'           => '',
		);
	}

	/** Sets up $_POST with a nonce field so the guard passes. */
	private function set_post( string $number ): void {
		$_POST = array(
			'wmn_user_profile_nonce' => 'valid-nonce',
			'wmn_member_number_edit' => $number,
		);
	}

	// ── Tests ─────────────────────────────────────────────────────────────

	/** @test */
	public function test_save_does_nothing_when_nonce_field_is_absent(): void {
		$_POST = array(); // No nonce field at all.

		$this->profile->save_section( 42 );

		$this->assertEmpty( $this->wpdb->calls );
	}

	/** @test */
	public function test_save_skips_all_writes_when_value_is_unchanged(): void {
		$this->wpdb->get_row_return = $this->make_member_row( 'MBR-000001' );
		$this->set_post( 'MBR-000001' ); // Same as existing.

		$this->profile->save_section( 42 );

		$this->assertEmpty( $this->wpdb->calls_to( 'update' ) );
		$this->assertEmpty( $this->wpdb->inserts_into( 'wmn_member_number_audit' ) );
	}

	/** @test */
	public function test_save_logs_member_number_change_when_updating_existing_record(): void {
		$this->wpdb->get_row_return = $this->make_member_row( 'MBR-000001' );
		$this->set_post( 'MBR-000099' ); // New number.

		$this->profile->save_section( 42 );

		// DB update written.
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'MBR-000099', $updates[0]['data']['member_number'] );

		// Audit entry written.
		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'member_number', $audit[0]['data']['field_changed'] );
		$this->assertSame( 'MBR-000001', $audit[0]['data']['old_value'] );
		$this->assertSame( 'MBR-000099', $audit[0]['data']['new_value'] );
		$this->assertSame( 10, $audit[0]['data']['member_number_id'] );
	}

	/** @test */
	public function test_save_logs_member_number_change_when_clearing_an_existing_record(): void {
		$this->wpdb->get_row_return = $this->make_member_row( 'MBR-000001' );
		$this->set_post( '' ); // Clearing the number.

		$this->profile->save_section( 42 );

		// user_id nulled out on the record.
		$updates = $this->wpdb->calls_to( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertNull( $updates[0]['data']['user_id'] );

		// Audit entry written.
		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'member_number', $audit[0]['data']['field_changed'] );
		$this->assertSame( 'MBR-000001', $audit[0]['data']['old_value'] );
		$this->assertSame( '', $audit[0]['data']['new_value'] );
	}

	/** @test */
	public function test_save_logs_created_entry_when_inserting_a_brand_new_record(): void {
		$this->wpdb->get_row_return = null; // No existing record.
		$this->wpdb->insert_id      = 200;
		$this->set_post( 'MBR-000042' );

		$this->profile->save_section( 42 );

		// Row inserted into wmn_member_numbers.
		$mn_inserts = $this->wpdb->inserts_into( 'wmn_member_numbers' );
		$this->assertCount( 1, $mn_inserts );
		$this->assertSame( 'MBR-000042', $mn_inserts[0]['data']['member_number'] );

		// Audit entry with field_changed = 'created'.
		$audit = $this->wpdb->inserts_into( 'wmn_member_number_audit' );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'created', $audit[0]['data']['field_changed'] );
		$this->assertSame( '', $audit[0]['data']['old_value'] );
		$this->assertSame( 'MBR-000042', $audit[0]['data']['new_value'] );
		$this->assertSame( 200, $audit[0]['data']['member_number_id'] );
	}

	/** @test */
	public function test_save_sets_assignment_type_to_manual_for_new_profile_record(): void {
		$this->wpdb->get_row_return = null; // No existing record.
		$this->set_post( 'MBR-000042' );

		$this->profile->save_section( 42 );

		$mn_inserts = $this->wpdb->inserts_into( 'wmn_member_numbers' );
		$this->assertCount( 1, $mn_inserts );
		$this->assertSame( 'manual', $mn_inserts[0]['data']['assignment_type'] );
	}
}
