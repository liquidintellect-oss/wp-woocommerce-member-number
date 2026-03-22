<?php
/**
 * PHPUnit bootstrap: initialises WP_Mock, defines required stubs, and loads plugin classes.
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Activate WP_Mock's function-stub environment before any plugin code is loaded.
WP_Mock::bootstrap();

// ── Constants ──────────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// ── Minimal WordPress class stubs ─────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub sufficient for validation paths in the plugin.
	 */
	class WP_Error {
		/** @var array<string, string[]> */
		protected array $errors = [];

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
			}
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );
			return (string) ( $codes[0] ?? '' );
		}

		public function get_error_message( string $code = '' ): string {
			$code = $code ?: $this->get_error_code();
			return isset( $this->errors[ $code ] ) ? (string) $this->errors[ $code ][0] : '';
		}
	}
}

// ── Minimal WooCommerce class stubs ───────────────────────────────────────

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {} // phpcs:ignore
}

if ( ! class_exists( 'WC_Order_Item_Fee' ) ) {
	class WC_Order_Item_Fee { // phpcs:ignore
		public function set_name( string $name ): void {}
		public function set_total( float $total ): void {}
	}
}

// ── Load plugin files ─────────────────────────────────────────────────────

$_includes = dirname( __DIR__ ) . '/includes/';

require_once $_includes . 'class-wmn-number-formatter.php';
require_once $_includes . 'class-wmn-member-number.php';
require_once $_includes . 'functions.php';
require_once $_includes . 'class-wmn-member-number-manager.php';

unset( $_includes );
