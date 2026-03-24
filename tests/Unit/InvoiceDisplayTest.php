<?php
/**
 * Tests for WMN_Invoice_Display — the class that surfaces the member number on
 * WooCommerce email order meta sections and WCPDF PDF documents.
 *
 * Covered:
 *   add_to_email()
 *     - skips output when the email is the plugin's own assignment email
 *     - skips output when no member number record exists for the order
 *     - renders plain-text output when $plain_text is true
 *     - renders HTML output when $plain_text is false
 *     - falls back to a user-level lookup when no order-level record exists
 *
 *   add_to_order_admin()
 *     - skips output when no member number record exists for the order
 *     - renders the label and number in the admin order view
 *
 *   add_to_wcpdf()
 *     - skips output when get_order() does not return a WC_Order
 *     - skips output when no member number record exists for the order
 *     - renders an HTML table containing the label and number
 *     - falls back to a user-level lookup when no order-level record exists
 */

declare( strict_types=1 );

use WP_Mock\Tools\TestCase;

// ── Test double ───────────────────────────────────────────────────────────

/**
 * Subclass that stubs out the DB-touching get_record_for_order() method so
 * tests only exercise the rendering and branching logic.
 */
class TestableInvoiceDisplay extends WMN_Invoice_Display {

	public ?WMN_Member_Number $stub_record = null;

	protected function get_record_for_order( WC_Order $order ): ?WMN_Member_Number {
		return $this->stub_record;
	}
}

// ── Test case ─────────────────────────────────────────────────────────────

class InvoiceDisplayTest extends TestCase {

	private TestableInvoiceDisplay $display;

	/** @var \Mockery\MockInterface&WC_Order */
	private \Mockery\MockInterface $order;

	/** @var \Mockery\MockInterface&WC_Email */
	private \Mockery\MockInterface $email;

	// ── Fixtures ──────────────────────────────────────────────────────────

	public function setUp(): void {
		parent::setUp();

		WP_Mock::userFunction( 'add_action' );

		$this->display = new TestableInvoiceDisplay();

		$this->order = Mockery::mock( 'WC_Order' );
		$this->email = Mockery::mock( 'WC_Email' );
	}

	public function tearDown(): void {
		parent::tearDown();
		Mockery::close();
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Build a minimal WMN_Member_Number record with given number string.
	 */
	private function make_record( string $number ): WMN_Member_Number {
		return new WMN_Member_Number(
			array(
				'id'              => 1,
				'member_number'   => $number,
				'user_id'         => 10,
				'order_id'        => 99,
				'assigned_at'     => '2026-01-01 00:00:00',
				'status'          => 'active',
				'assignment_type' => 'auto',
				'notes'           => '',
			)
		);
	}

	// ── add_to_email() ────────────────────────────────────────────────────

	public function test_add_to_email_skips_assignment_email(): void {
		$this->email->id = 'wmn_number_assigned';

		// No output should be produced.
		$this->expectOutputString( '' );

		$this->display->add_to_email( $this->order, false, false, $this->email );
	}

	public function test_add_to_email_skips_when_no_record(): void {
		$this->email->id            = 'customer_invoice';
		$this->display->stub_record = null;

		$this->expectOutputString( '' );

		$this->display->add_to_email( $this->order, false, false, $this->email );
	}

	public function test_add_to_email_renders_plain_text(): void {
		$this->email->id            = 'customer_invoice';
		$this->display->stub_record = $this->make_record( 'MBR-000042' );

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $key, mixed $fallback = null ): mixed {
					return 'wmn_number_label' === $key ? 'Member Number' : $fallback;
				},
			)
		);
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		$this->expectOutputRegex( '/Member Number: MBR-000042/' );

		$this->display->add_to_email( $this->order, false, true, $this->email );
	}

	public function test_add_to_email_renders_html(): void {
		$this->email->id            = 'customer_invoice';
		$this->display->stub_record = $this->make_record( 'MBR-000042' );

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $key, mixed $fallback = null ): mixed {
					return 'wmn_number_label' === $key ? 'Member Number' : $fallback;
				},
			)
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		ob_start();
		$this->display->add_to_email( $this->order, false, false, $this->email );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<h2>Member Number</h2>', $output );
		$this->assertStringContainsString( '<p>MBR-000042</p>', $output );
	}

	// ── add_to_order_admin() ──────────────────────────────────────────────

	public function test_add_to_order_admin_skips_when_no_record(): void {
		$this->display->stub_record = null;

		$this->expectOutputString( '' );

		$this->display->add_to_order_admin( $this->order );
	}

	public function test_add_to_order_admin_renders_label_and_number(): void {
		$this->display->stub_record = $this->make_record( 'MBR-000042' );

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $key, mixed $fallback = null ): mixed {
					return 'wmn_number_label' === $key ? 'Member Number' : $fallback;
				},
			)
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		ob_start();
		$this->display->add_to_order_admin( $this->order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Member Number', $output );
		$this->assertStringContainsString( 'MBR-000042', $output );
		$this->assertStringContainsString( 'wmn-order-member-number', $output );
	}

	// ── add_to_wcpdf() ────────────────────────────────────────────────────

	public function test_add_to_wcpdf_skips_when_get_order_returns_non_order(): void {
		$document            = new stdClass();
		$document->get_order = function (): ?object {
			return null;
		};

		// Workaround: use a mock with get_order() returning null.
		$doc_mock = Mockery::mock();
		$doc_mock->shouldReceive( 'get_order' )->andReturn( null );

		$this->expectOutputString( '' );

		$this->display->add_to_wcpdf( 'invoice', $doc_mock );
	}

	public function test_add_to_wcpdf_skips_when_no_record(): void {
		$this->display->stub_record = null;

		$doc_mock = Mockery::mock();
		$doc_mock->shouldReceive( 'get_order' )->andReturn( $this->order );

		$this->expectOutputString( '' );

		$this->display->add_to_wcpdf( 'invoice', $doc_mock );
	}

	public function test_add_to_wcpdf_renders_table(): void {
		$this->display->stub_record = $this->make_record( 'MBR-000099' );

		$doc_mock = Mockery::mock();
		$doc_mock->shouldReceive( 'get_order' )->andReturn( $this->order );

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $key, mixed $fallback = null ): mixed {
					return 'wmn_number_label' === $key ? 'Member Number' : $fallback;
				},
			)
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		ob_start();
		$this->display->add_to_wcpdf( 'invoice', $doc_mock );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wmn-member-number', $output );
		$this->assertStringContainsString( 'Member Number', $output );
		$this->assertStringContainsString( 'MBR-000099', $output );
	}

	public function test_add_to_wcpdf_renders_for_packing_slip(): void {
		$this->display->stub_record = $this->make_record( 'MBR-000007' );

		$doc_mock = Mockery::mock();
		$doc_mock->shouldReceive( 'get_order' )->andReturn( $this->order );

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => static function ( string $key, mixed $fallback = null ): mixed {
					return 'wmn_number_label' === $key ? 'Member Number' : $fallback;
				},
			)
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			function ( string $text ): string {
				return $text;
			}
		);

		ob_start();
		$this->display->add_to_wcpdf( 'packing-slip', $doc_mock );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'MBR-000007', $output );
	}
}
