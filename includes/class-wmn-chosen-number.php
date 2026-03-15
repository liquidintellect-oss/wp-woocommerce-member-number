<?php
/**
 * Checkout field, fee, and AJAX handlers for the customer-chosen number feature.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the chosen-number checkout flow: field display, fee, reservations, and AJAX.
 */
class WMN_Chosen_Number {

	/**
	 * Constructor — registers hooks only when the feature is enabled.
	 */
	public function __construct() {
		if ( 'yes' !== get_option( 'wmn_allow_chosen_number', 'no' ) ) {
			return;
		}

		add_action( 'woocommerce_before_order_notes', array( $this, 'render_checkout_field' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_chosen_number_fee' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'save_chosen_number_to_order' ), 10, 1 );

		add_action( 'wp_ajax_wmn_check_number', array( $this, 'ajax_check_availability' ) );
		add_action( 'wp_ajax_nopriv_wmn_check_number', array( $this, 'ajax_check_availability' ) );

		add_action( 'wp_ajax_wmn_release_reservation', array( $this, 'ajax_release_reservation' ) );
		add_action( 'wp_ajax_nopriv_wmn_release_reservation', array( $this, 'ajax_release_reservation' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Checkout field
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Renders the chosen-number input field on the checkout page.
	 *
	 * @param \WC_Checkout $checkout The WooCommerce checkout object (unused).
	 * @return void
	 */
	public function render_checkout_field( \WC_Checkout $checkout ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! $this->cart_has_trigger_product() ) {
			return;
		}

		$fee       = number_format( (float) get_option( 'wmn_chosen_number_fee', 25.00 ), 2 );
		$fee_label = get_option( 'wmn_chosen_number_fee_label', 'Custom {label} Selection' );
		$fee_label = str_replace( '{label}', wmn_get_label(), $fee_label );
		$label     = wmn_get_label();
		$currency  = get_woocommerce_currency_symbol();
		$nonce     = wp_create_nonce( 'wmn_check_number' );
		$example   = wmn_get_formatter()->generate( (int) get_option( 'wmn_number_min_value', 1 ) );

		?>
		<div id="wmn-chosen-number-wrap" class="wmn-chosen-number-wrap">
			<h3 class="wmn-chosen-toggle">
				<span class="wmn-toggle-arrow">&#9654;</span>
				<?php
				printf(
					/* translators: 1: label, 2: currency symbol, 3: fee */
					esc_html__( 'Choose your own %1$s (+%2$s%3$s)', 'wmn' ),
					esc_html( $label ),
					esc_html( $currency ),
					esc_html( $fee )
				);
				?>
			</h3>
			<div id="wmn-chosen-number-body" class="wmn-chosen-number-body" style="display:none;">
				<p class="form-row form-row-wide">
					<label for="wmn_chosen_input">
						<?php esc_html_e( 'Enter a number:', 'wmn' ); ?>
					</label>
					<input
						type="text"
						id="wmn_chosen_input"
						name="wmn_chosen_input"
						class="input-text"
						placeholder="<?php echo esc_attr( $example ); ?>"
						autocomplete="off"
					/>
					<button type="button" id="wmn-check-btn" class="button alt">
						<?php esc_html_e( 'Check Availability', 'wmn' ); ?>
					</button>
				</p>
				<div id="wmn-check-result" class="wmn-check-result" aria-live="polite"></div>
				<input type="hidden" id="wmn_chosen_number" name="wmn_chosen_number" value="" />
			</div>
		</div>
		<script>
		window.wmnData =
		<?php
		echo wp_json_encode(
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => $nonce,
				'feeRaw'      => (float) get_option( 'wmn_chosen_number_fee', 25.00 ),
				'feeCurrency' => $currency,
				'label'       => $label,
				'feeLabel'    => $fee_label,
			)
		);
		?>
		;
		</script>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fee injection
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Adds the chosen-number fee to the cart if a valid reservation exists.
	 *
	 * @param \WC_Cart $cart The WooCommerce cart object.
	 * @return void
	 */
	public function apply_chosen_number_fee( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$chosen = WC()->session ? WC()->session->get( 'wmn_chosen_number' ) : '';
		if ( ! $chosen ) {
			return;
		}

		if ( ! $this->cart_has_trigger_product() ) {
			WC()->session->set( 'wmn_chosen_number', '' );
			return;
		}

		// Verify reservation is still valid.
		global $wpdb;
		$token = $this->get_session_token();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$valid = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}wmn_reserved_numbers
				  WHERE member_number = %s AND session_token = %s AND expires_at > NOW() LIMIT 1",
				$chosen,
				$token
			)
		);

		if ( ! $valid ) {
			WC()->session->set( 'wmn_chosen_number', '' );
			return;
		}

		$fee_amount = (float) apply_filters(
			'wmn_chosen_number_fee_amount',
			(float) get_option( 'wmn_chosen_number_fee', 25.00 ),
			null
		);

		$fee_label = get_option( 'wmn_chosen_number_fee_label', 'Custom {label} Selection' );
		$fee_label = str_replace( '{label}', wmn_get_label(), $fee_label );

		$cart->add_fee( $fee_label, $fee_amount, true );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Save to order meta
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Persists the chosen number to order meta after checkout is processed.
	 *
	 * @param int $order_id The newly created order ID.
	 * @return void
	 */
	public function save_chosen_number_to_order( int $order_id ): void {
		$chosen = WC()->session ? WC()->session->get( 'wmn_chosen_number' ) : '';
		if ( ! $chosen ) {
			// Also check POST in case session is unavailable (some payment gateways).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$chosen = isset( $_POST['wmn_chosen_number'] ) ? sanitize_text_field( wp_unslash( $_POST['wmn_chosen_number'] ) ) : '';
		}

		if ( ! $chosen ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_wmn_chosen_number', $chosen );
			$order->save();
		}

		if ( WC()->session ) {
			WC()->session->set( 'wmn_chosen_number', '' );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX — availability check
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * AJAX handler: validates a number, reserves it, and stores it in session.
	 *
	 * @return void
	 */
	public function ajax_check_availability(): void {
		check_ajax_referer( 'wmn_check_number', 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input = isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( $_POST['number'] ) ) : '';

		$formatter = wmn_get_formatter();
		$input     = $formatter->normalize_input( $input );

		$validation = $formatter->validate_chosen( $input );
		if ( is_wp_error( $validation ) ) {
			wp_send_json_error( array( 'reason' => $validation->get_error_message() ) );
		}

		$token = $this->get_session_token();

		$manager = WMN_Plugin::instance()->manager;

		if ( ! $manager->is_number_available( $input, $token ) ) {
			wp_send_json_error(
				array(
					'reason' => sprintf(
					/* translators: %s: number */
						__( '%s is already taken or reserved. Please try a different number.', 'wmn' ),
						esc_html( $input )
					),
				)
			);
		}

		global $wpdb;

		// Release any existing reservation for this session.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'wmn_reserved_numbers',
			array( 'session_token' => $token ),
			array( '%s' )
		);

		$ttl = max( 1, (int) get_option( 'wmn_reservation_ttl_minutes', 30 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'wmn_reserved_numbers',
			array(
				'member_number' => $input,
				'session_token' => $token,
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $ttl * MINUTE_IN_SECONDS ),
			),
			array( '%s', '%s', '%s' )
		);

		if ( WC()->session ) {
			WC()->session->set( 'wmn_chosen_number', $input );
		}

		$fee       = (float) get_option( 'wmn_chosen_number_fee', 25.00 );
		$fee_label = get_option( 'wmn_chosen_number_fee_label', 'Custom {label} Selection' );
		$fee_label = str_replace( '{label}', wmn_get_label(), $fee_label );

		wp_send_json_success(
			array(
				'number'    => $input,
				'fee'       => wc_price( $fee ),
				'fee_raw'   => $fee,
				'fee_label' => $fee_label,
				'message'   => sprintf(
				/* translators: 1: number, 2: formatted fee */
					__( '%1$s is available! An additional fee of %2$s will be added.', 'wmn' ),
					esc_html( $input ),
					wc_price( $fee )
				),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX — release reservation
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * AJAX handler: releases the number reservation for the current session.
	 *
	 * @return void
	 */
	public function ajax_release_reservation(): void {
		check_ajax_referer( 'wmn_check_number', 'nonce' );

		global $wpdb;
		$token = $this->get_session_token();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'wmn_reserved_numbers',
			array( 'session_token' => $token ),
			array( '%s' )
		);

		if ( WC()->session ) {
			WC()->session->set( 'wmn_chosen_number', '' );
		}

		wp_send_json_success();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Enqueue
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Enqueues checkout CSS and JS when a trigger product is in the cart.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		if ( ! is_checkout() || ! $this->cart_has_trigger_product() ) {
			return;
		}

		wp_enqueue_style(
			'wmn-checkout',
			WMN_PLUGIN_URL . 'assets/css/wmn-checkout.css',
			array(),
			WMN_VERSION
		);

		wp_enqueue_script(
			'wmn-checkout',
			WMN_PLUGIN_URL . 'assets/js/wmn-checkout.js',
			array( 'jquery' ),
			WMN_VERSION,
			true
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Returns true if the current cart contains at least one trigger product.
	 *
	 * @return bool
	 */
	private function cart_has_trigger_product(): bool {
		if ( ! WC()->cart ) {
			return false;
		}
		$trigger_ids = wmn_get_trigger_product_ids();
		if ( empty( $trigger_ids ) ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( in_array( (int) ( $item['product_id'] ?? 0 ), $trigger_ids, true ) ) {
				return true;
			}
			if ( in_array( (int) ( $item['variation_id'] ?? 0 ), $trigger_ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns a unique token for the current WooCommerce session.
	 *
	 * @return string
	 */
	private function get_session_token(): string {
		if ( WC()->session ) {
			$token = WC()->session->get_customer_unique_id();
			if ( $token ) {
				return (string) $token;
			}
		}
		// Fallback: hash the session cookie.
		$cookie = WC()->session ? WC()->session->get_session_cookie() : false;
		if ( $cookie && ! empty( $cookie[0] ) ) {
			return hash( 'sha256', $cookie[0] );
		}
		return hash( 'sha256', uniqid( 'wmn_', true ) );
	}
}
