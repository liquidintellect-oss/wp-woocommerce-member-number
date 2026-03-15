<?php
/**
 * Adds member-number trigger controls to the WooCommerce product edit page.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves a "Triggers member number" checkbox inside the product data panel.
 */
class WMN_Admin_Product {

	/**
	 * Constructor — registers product-page hooks.
	 */
	public function __construct() {
		// Render the checkbox in the General tab of the product data panel.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_checkbox' ) );

		// Save when the product is saved.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_checkbox' ) );

		// Show a badge in the product list table column.
		add_filter( 'woocommerce_admin_product_row_actions', array( $this, 'maybe_add_row_badge' ), 10, 2 );
	}

	/**
	 * Renders the "Assigns [label]" checkbox on the product edit screen.
	 *
	 * @return void
	 */
	public function render_checkbox(): void {
		global $post;

		$trigger_ids = wmn_get_trigger_product_ids();
		$is_trigger  = in_array( (int) $post->ID, $trigger_ids, true );

		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_wmn_is_trigger',
				'label'       => esc_html(
					sprintf(
						/* translators: %s: configured label e.g. "Member Number" */
						__( 'Assigns a %s', 'wmn' ),
						wmn_get_label()
					)
				),
				'description' => esc_html(
					sprintf(
						/* translators: %s: configured label e.g. "Member Number" */
						__( 'When purchased, assign a unique %s to the customer.', 'wmn' ),
						wmn_get_label()
					)
				),
				'value'       => wc_bool_to_string( $is_trigger ),
				'cbvalue'     => 'yes',
			)
		);

		echo '</div>';
	}

	/**
	 * Saves the trigger checkbox when a product is saved.
	 *
	 * @param int $post_id The product post ID.
	 * @return void
	 */
	public function save_checkbox( int $post_id ): void {
		$is_trigger  = isset( $_POST['_wmn_is_trigger'] ) && 'yes' === sanitize_key( $_POST['_wmn_is_trigger'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$trigger_ids = wmn_get_trigger_product_ids();

		if ( $is_trigger ) {
			if ( ! in_array( $post_id, $trigger_ids, true ) ) {
				$trigger_ids[] = $post_id;
				update_option( 'wmn_trigger_product_ids', array_values( array_unique( $trigger_ids ) ) );
			}
		} else {
			$updated = array_values( array_filter( $trigger_ids, fn( $id ) => $id !== $post_id ) );
			if ( count( $updated ) !== count( $trigger_ids ) ) {
				update_option( 'wmn_trigger_product_ids', $updated );
			}
		}
	}

	/**
	 * Adds a small indicator to the product list table for trigger products.
	 *
	 * @param array<string,string> $actions  Existing row actions.
	 * @param WC_Product           $product  The product object.
	 * @return array<string,string>
	 */
	public function maybe_add_row_badge( array $actions, WC_Product $product ): array {
		if ( in_array( $product->get_id(), wmn_get_trigger_product_ids(), true ) ) {
			// Prepend a non-link indicator so it shows first.
			$actions = array_merge(
				array(
					'wmn_trigger' => '<span class="wmn-product-trigger-badge">' .
						esc_html(
							sprintf(
								/* translators: %s: configured label e.g. "Member Number" */
								__( 'Assigns %s', 'wmn' ),
								wmn_get_label()
							)
						) .
						'</span>',
				),
				$actions
			);
		}
		return $actions;
	}
}
