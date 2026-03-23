<?php
/**
 * Tests for the INSERT-vs-UPDATE decision inside
 * WMN_Member_Number_Manager::assign_chosen_number().
 *
 * When a user already has a member number record in the DB, the method must
 * UPDATE that record (replacing the number) rather than INSERT a new one,
 * because the wmn_member_numbers table has a UNIQUE constraint on user_id.
 */

declare( strict_types=1 );

use WP_Mock\Tools\TestCase;

// ── $wpdb tracking stub ───────────────────────────────────────────────────

/**
 * Lightweight $wpdb substitute that records which DML method was called.
 */
class TrackingWpdb {

	public string $prefix       = 'wp_';
	public bool $insert_called  = false;
	public bool $update_called  = false;
	public ?array $insert_data  = null;
	public ?array $update_data  = null;
	public ?array $update_where = null;

	public function insert( string $table, array $data, ?array $format = null ): int {
		$this->insert_called = true;
		$this->insert_data   = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int {
		$this->update_called = true;
		$this->update_data   = $data;
		$this->update_where  = $where;
		return 1;
	}

	public function delete( string $table, array $where, ?array $where_format = null ): int {
		return 1;
	}

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	public function get_var( mixed $query = null, int $col = 0, int $row = 0 ): mixed {
		return null;
	}

	public function get_row( mixed $query = null, int $output = 0, int $row = 0 ): mixed {
		return null;
	}
}

// ── Test double ───────────────────────────────────────────────────────────

/**
 * Subclass that:
 *   - stubs get_record_for_user()  so no real DB lookup occurs
 *   - stubs persist_user_meta()    so no real user-meta writes occur
 *   - exposes a public call_assign_chosen() wrapper for the protected method
 */
class TestableAssignChosenManager extends WMN_Member_Number_Manager {

	public ?WMN_Member_Number $stub_record_for_user = null;

	/** Public wrapper so test code can invoke the protected method directly. */
	public function call_assign_chosen( WC_Order $order, string $chosen ): void {
		$this->assign_chosen_number( $order, $chosen );
	}

	protected function get_record_for_user( int $user_id ): ?WMN_Member_Number {
		return $this->stub_record_for_user;
	}

	protected function persist_user_meta( WC_Order $order, string $number, string $type ): void {
		// Intentional no-op: avoids user-meta writes in unit tests.
	}
}

// ── Test case ─────────────────────────────────────────────────────────────

class AssignChosenNumberTest extends TestCase {

	private TestableAssignChosenManager $manager;
	private TrackingWpdb $wpdb;

	/** @var \Mockery\MockInterface&WC_Order */
	private \Mockery\MockInterface $order;

	// ── Fixtures ──────────────────────────────────────────────────────────

	public function setUp(): void {
		parent::setUp();

		// Stub hooks registered in manager constructor.
		WP_Mock::userFunction( 'add_action' );
		WP_Mock::userFunction( 'add_filter' );

		// Install the tracking wpdb as the global.
		$this->wpdb      = new TrackingWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->manager = new TestableAssignChosenManager();

		// Minimal WC_Order mock.
		$this->order = Mockery::mock( 'WC_Order' );
		$this->order->shouldReceive( 'get_id' )->andReturn( 999 )->byDefault();
		$this->order->shouldReceive( 'get_user_id' )->andReturn( 123 )->byDefault();
		$this->order->shouldReceive( 'add_order_note' )->byDefault();

		// WP functions consumed by assign_chosen_number() and validate_chosen().
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

		WP_Mock::userFunction( 'is_wp_error', array( 'return' => false ) );
		WP_Mock::userFunction( 'do_action' );
	}

	public function tearDown(): void {
		parent::tearDown();
		Mockery::close();
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function make_existing_record( int $id = 5 ): WMN_Member_Number {
		return new WMN_Member_Number(
			array(
				'id'              => $id,
				'member_number'   => 'MBR-000001',
				'user_id'         => 123,
				'order_id'        => 100,
				'assigned_at'     => '2024-01-01 00:00:00',
				'status'          => 'active',
				'assignment_type' => 'manual',
				'notes'           => '',
			)
		);
	}

	// ── Tests ─────────────────────────────────────────────────────────────

	/** @test */
	public function test_inserts_new_record_when_user_has_no_existing_number(): void {
		$this->manager->stub_record_for_user = null;

		$this->manager->call_assign_chosen( $this->order, 'MBR-000042' );

		$this->assertTrue( $this->wpdb->insert_called, 'Expected $wpdb->insert() to be called' );
		$this->assertFalse( $this->wpdb->update_called, 'Expected $wpdb->update() NOT to be called' );

		$this->assertSame( 'MBR-000042', $this->wpdb->insert_data['member_number'] );
		$this->assertSame( 'chosen', $this->wpdb->insert_data['assignment_type'] );
		$this->assertSame( 'active', $this->wpdb->insert_data['status'] );
	}

	/** @test */
	public function test_updates_existing_record_when_user_already_has_a_number(): void {
		$this->manager->stub_record_for_user = $this->make_existing_record( id: 5 );

		$this->manager->call_assign_chosen( $this->order, 'MBR-000042' );

		$this->assertFalse( $this->wpdb->insert_called, 'Expected $wpdb->insert() NOT to be called' );
		$this->assertTrue( $this->wpdb->update_called, 'Expected $wpdb->update() to be called' );

		// The WHERE clause must target the existing record by its primary key.
		$this->assertSame( array( 'id' => 5 ), $this->wpdb->update_where );

		// The updated row must carry the new number, correct type, and active status.
		$this->assertSame( 'MBR-000042', $this->wpdb->update_data['member_number'] );
		$this->assertSame( 'chosen', $this->wpdb->update_data['assignment_type'] );
		$this->assertSame( 'active', $this->wpdb->update_data['status'] );
	}
}
