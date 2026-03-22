<?php
/**
 * Tests for the idempotency / status-aware assignment logic in
 * WMN_Member_Number_Manager::maybe_assign_number().
 *
 * These tests cover the GH-6 changes:
 *   - Active number + no chosen input  → skip (idempotent)
 *   - Active number + chosen + feature off → skip
 *   - Active number + chosen + feature on  → replace (assign_chosen_number called)
 *   - Suspended number                     → treat as new (assign_auto_number called)
 *   - Revoked number                       → treat as new (assign_auto_number called)
 *   - No existing number                   → normal auto-assign
 */

declare( strict_types=1 );

use WP_Mock\Tools\TestCase;

// ── Test double ───────────────────────────────────────────────────────────

/**
 * Subclass of the manager that stubs out all DB-touching helpers so tests
 * only exercise the branching logic inside maybe_assign_number().
 */
class TestableMaybeAssignManager extends WMN_Member_Number_Manager {

	public ?WMN_Member_Number $stub_record_for_order = null;
	public ?WMN_Member_Number $stub_record_for_user  = null;

	public bool   $auto_assign_called   = false;
	public bool   $chosen_assign_called = false;
	public string $chosen_assign_arg    = '';

	protected function get_record_for_order( int $order_id ): ?WMN_Member_Number {
		return $this->stub_record_for_order;
	}

	protected function get_record_for_user( int $user_id ): ?WMN_Member_Number {
		return $this->stub_record_for_user;
	}

	protected function order_contains_trigger_product( WC_Order $order ): bool {
		return true;
	}

	protected function assign_auto_number( WC_Order $order ): void {
		$this->auto_assign_called = true;
	}

	protected function assign_chosen_number( WC_Order $order, string $chosen ): void {
		$this->chosen_assign_called = true;
		$this->chosen_assign_arg    = $chosen;
	}
}

// ── Test case ─────────────────────────────────────────────────────────────

class MaybeAssignNumberTest extends TestCase {

	private const ORDER_ID = 42;

	private TestableMaybeAssignManager $manager;

	/** @var \Mockery\MockInterface&WC_Order */
	private \Mockery\MockInterface $order;

	// ── Fixtures ──────────────────────────────────────────────────────────

	public function setUp(): void {
		parent::setUp();

		// Stub the hook-registration calls made in the manager constructor.
		WP_Mock::userFunction( 'add_action' );
		WP_Mock::userFunction( 'add_filter' );

		$this->manager = new TestableMaybeAssignManager();

		// Build a minimal WC_Order mock that satisfies all pre-condition checks
		// in maybe_assign_number() before the idempotency guard is reached.
		$this->order = Mockery::mock( 'WC_Order' );
		$this->order->shouldReceive( 'get_status' )->andReturn( 'processing' )->byDefault();
		$this->order->shouldReceive( 'get_meta' )
			->with( '_subscription_renewal' )->andReturn( '' )->byDefault();

		WP_Mock::userFunction(
			'wc_get_order',
			[
				'args'   => [ self::ORDER_ID ],
				'return' => $this->order,
			]
		);

		// apply_filters( 'wmn_should_assign_for_order', ... ) must return true.
		WP_Mock::userFunction( 'apply_filters', [ 'return' => true ] );
	}

	public function tearDown(): void {
		parent::tearDown();
		Mockery::close();
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Configures the get_option mock for a given test scenario.
	 *
	 * @param bool   $feature_on     Whether wmn_allow_chosen_number is 'yes'.
	 * @param string $chosen_number  Value of the _wmn_chosen_number order meta.
	 */
	private function setup_mocks( bool $feature_on, string $chosen_number, int $user_id = 123 ): void {
		WP_Mock::userFunction(
			'get_option',
			[
				'return' => static function ( string $key, mixed $default = null ) use ( $feature_on ): mixed {
					return match ( $key ) {
						'wmn_assign_on_status'    => [ 'processing' ],
						'wmn_allow_chosen_number' => $feature_on ? 'yes' : 'no',
						default                   => $default,
					};
				},
			]
		);

		$this->order->shouldReceive( 'get_user_id' )->andReturn( $user_id )->byDefault();
		$this->order->shouldReceive( 'get_meta' )
			->with( '_wmn_chosen_number' )->andReturn( $chosen_number )->byDefault();
	}

	/**
	 * Creates a WMN_Member_Number value object with the given status.
	 */
	private function make_record( string $status ): WMN_Member_Number {
		return new WMN_Member_Number(
			[
				'id'              => 1,
				'member_number'   => 'MBR-000001',
				'user_id'         => 123,
				'order_id'        => 100,
				'assigned_at'     => '2024-01-01 00:00:00',
				'status'          => $status,
				'assignment_type' => 'manual',
				'notes'           => '',
			]
		);
	}

	// ── Tests ─────────────────────────────────────────────────────────────

	/** @test */
	public function test_skips_when_user_has_active_number_and_no_chosen_input(): void {
		$this->setup_mocks( feature_on: true, chosen_number: '' );
		$this->manager->stub_record_for_user = $this->make_record( 'active' );

		$this->manager->maybe_assign_number( self::ORDER_ID );

		$this->assertFalse( $this->manager->auto_assign_called );
		$this->assertFalse( $this->manager->chosen_assign_called );
	}

	/** @test */
	public function test_skips_when_user_has_active_number_and_chosen_feature_is_disabled(): void {
		$this->setup_mocks( feature_on: false, chosen_number: 'MBR-000099' );
		$this->manager->stub_record_for_user = $this->make_record( 'active' );

		$this->manager->maybe_assign_number( self::ORDER_ID );

		$this->assertFalse( $this->manager->auto_assign_called );
		$this->assertFalse( $this->manager->chosen_assign_called );
	}

	/** @test */
	public function test_calls_chosen_assignment_when_user_has_active_number_and_chosen_is_provided(): void {
		$this->setup_mocks( feature_on: true, chosen_number: 'MBR-000099' );
		$this->manager->stub_record_for_user = $this->make_record( 'active' );

		$this->manager->maybe_assign_number( self::ORDER_ID );

		$this->assertFalse( $this->manager->auto_assign_called );
		$this->assertTrue( $this->manager->chosen_assign_called );
		$this->assertSame( 'MBR-000099', $this->manager->chosen_assign_arg );
	}

	/** @test */
	public function test_auto_assigns_when_user_has_suspended_number(): void {
		$this->setup_mocks( feature_on: true, chosen_number: '' );
		$this->manager->stub_record_for_user = $this->make_record( 'suspended' );

		$this->manager->maybe_assign_number( self::ORDER_ID );

		$this->assertTrue( $this->manager->auto_assign_called );
		$this->assertFalse( $this->manager->chosen_assign_called );
	}

	/** @test */
	public function test_auto_assigns_when_user_has_revoked_number(): void {
		$this->setup_mocks( feature_on: true, chosen_number: '' );
		$this->manager->stub_record_for_user = $this->make_record( 'revoked' );

		$this->manager->maybe_assign_number( self::ORDER_ID );

		$this->assertTrue( $this->manager->auto_assign_called );
		$this->assertFalse( $this->manager->chosen_assign_called );
	}

	/** @test */
	public function test_auto_assigns_when_user_has_no_existing_number(): void {
		$this->setup_mocks( feature_on: true, chosen_number: '' );
		$this->manager->stub_record_for_user = null;

		$this->manager->maybe_assign_number( self::ORDER_ID );

		$this->assertTrue( $this->manager->auto_assign_called );
		$this->assertFalse( $this->manager->chosen_assign_called );
	}
}
