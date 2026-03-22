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
if ( ! defined( 'WMN_PLUGIN_DIR' ) ) {
	define( 'WMN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// ── Minimal WordPress class stubs ─────────────────────────────────────────

// ── WP utility functions not provided by WP_Mock ──────────────────────────
// These are pure, side-effect-free WP helpers. Defining them here once avoids
// the "cannot mock a previously eval()-defined function" problem with WP_Mock.

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $maybeint ): int {
		return abs( intval( $maybeint ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return implode( "\n", array_map( 'sanitize_text_field', explode( "\n", $str ) ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
if ( ! function_exists( 'esc_like' ) ) {
	function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( mixed ...$args ): string {
		return 'http://example.com/?' . http_build_query( is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] ) );
	}
}
if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg( mixed $key, string $query = '' ): string {
		return 'http://example.com/';
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal WP_User stub sufficient for admin-profile tests.
	 */
	class WP_User { // phpcs:ignore
		public int $ID            = 0;
		public string $user_email = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub sufficient for validation paths in the plugin.
	 */
	class WP_Error {
		/** @var array<string, string[]> */
		protected array $errors = array();

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
			$code = $code ? $code : $this->get_error_code();
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
require_once $_includes . 'class-wmn-member-number-audit.php';
require_once $_includes . 'functions.php';
require_once $_includes . 'class-wmn-member-number-manager.php';
require_once $_includes . 'admin/class-wmn-admin-menus.php';
require_once $_includes . 'admin/class-wmn-admin-user-profile.php';

unset( $_includes );
