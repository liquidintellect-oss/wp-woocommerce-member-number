<?php
/**
 * Admin asset enqueueing and AJAX handlers.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-side asset registration and the format-preview AJAX endpoint.
 */
class WMN_Admin {

	/**
	 * Constructor — registers admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'wp_ajax_wmn_format_preview', array( $this, 'ajax_format_preview' ) );
		add_action( 'wp_ajax_wmn_search_orders', array( $this, 'ajax_search_orders' ) );
	}

	/**
	 * Enqueues admin CSS and conditionally the admin JS on WMN/WC pages.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		$screen = get_current_screen();

		wp_enqueue_style(
			'wmn-admin',
			WMN_PLUGIN_URL . 'assets/css/wmn-admin.css',
			array( 'woocommerce_admin_styles' ),
			WMN_VERSION
		);

		// Only load the JS on our own pages and WC settings.
		$wmn_pages = array( 'woocommerce_page_wmn-members', 'woocommerce_page_wc-settings' );
		if ( ! in_array( $hook, $wmn_pages, true ) && ( ! $screen || 'user-edit' !== $screen->id ) ) {
			return;
		}

		wp_enqueue_script(
			'wmn-admin',
			WMN_PLUGIN_URL . 'assets/js/wmn-admin.js',
			array( 'jquery', 'selectWoo' ),
			WMN_VERSION,
			true
		);

		wp_localize_script(
			'wmn-admin',
			'wmnAdmin',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'wmn_admin' ),
				'searchCustomersNonce' => wp_create_nonce( 'search-customers' ),
				'label'                => wmn_get_label(),
				'labelPlural'          => wmn_get_label( true ),
				'confirmSuspend'       => __( 'Suspend selected numbers?', 'wmn' ),
				'confirmRevoke'        => __( 'Permanently revoke selected numbers? This cannot be undone.', 'wmn' ),
			)
		);
	}

	/**
	 * Returns matching orders for the manual-assign order search field.
	 *
	 * @return void
	 */
	public function ajax_search_orders(): void {
		check_ajax_referer( 'wmn_admin', 'nonce' );

		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( array() );
		}

		$term = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );
		if ( '' === $term ) {
			wp_send_json( array() );
		}

		$results = array();

		// Numeric input: try exact order ID first.
		if ( ctype_digit( $term ) ) {
			$order = wc_get_order( absint( $term ) );
			if ( $order ) {
				$name                        = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$results[ $order->get_id() ] = sprintf(
					/* translators: 1: order number, 2: billing name */
					__( '#%1$s — %2$s', 'wmn' ),
					$order->get_order_number(),
					$name ? $name : __( 'Guest', 'wmn' )
				);
			}
		}

		// Text or numeric: also search by billing name / email.
		if ( empty( $results ) ) {
			$orders = wc_get_orders(
				array(
					'limit'  => 20,
					'search' => $term,
				)
			);
			foreach ( $orders as $order ) {
				$name                        = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$results[ $order->get_id() ] = sprintf(
					/* translators: 1: order number, 2: billing name */
					__( '#%1$s — %2$s', 'wmn' ),
					$order->get_order_number(),
					$name ? $name : __( 'Guest', 'wmn' )
				);
			}
		}

		wp_send_json( $results );
	}

	/**
	 * Return a rendered example number for the live settings preview.
	 *
	 * @return void
	 */
	public function ajax_format_preview(): void {
		check_ajax_referer( 'wmn_admin', 'nonce' );

		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wmn' ) ) );
		}

		$template   = sanitize_text_field( wp_unslash( $_POST['template'] ?? '{PREFIX}{SEQ}' ) );
		$prefix     = sanitize_text_field( wp_unslash( $_POST['prefix'] ?? 'MBR-' ) );
		$pad_length = absint( $_POST['pad_length'] ?? 6 );
		$start      = absint( $_POST['start'] ?? 1 );

		$formatter = new WMN_Number_Formatter( $template, $prefix, $pad_length );
		$preview   = $formatter->generate( max( 1, $start ) );

		wp_send_json_success( array( 'preview' => $preview ) );
	}
}
