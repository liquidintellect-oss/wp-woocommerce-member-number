<?php
/**
 * WooCommerce Settings API page for the WMN plugin.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the WMN settings tab inside WooCommerce > Settings.
 */
class WMN_Settings_Page extends WC_Settings_Page {

	/**
	 * Constructor — sets the tab ID and label.
	 */
	public function __construct() {
		$this->id    = 'wmn';
		$this->label = wmn_get_label( true );
		parent::__construct();

		// WC fires do_action("woocommerce_admin_field_{type}") for unknown types.
		add_action( 'woocommerce_admin_field_wmn_product_search', array( $this, 'output_product_search_field' ) );
		add_action( 'woocommerce_admin_field_wmn_format_preview', array( $this, 'output_format_preview_field' ) );
	}

	/**
	 * Returns settings for the default (only) section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings_for_default_section(): array {
		return $this->get_all_settings();
	}

	/**
	 * Builds and returns the full settings array.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_all_settings(): array {
		$settings = array();

		// ── Labels ───────────────────────────────────────────────────────────
		$settings[] = array(
			'title' => __( 'Labels', 'wmn' ),
			'type'  => 'title',
			'id'    => 'wmn_labels_section',
		);
		$settings[] = array(
			'title'   => __( 'Singular label', 'wmn' ),
			'desc'    => __( 'e.g. "Member Number", "Super Club Number"', 'wmn' ),
			'id'      => 'wmn_number_label',
			'type'    => 'text',
			'default' => __( 'Member Number', 'wmn' ),
			'css'     => 'min-width:250px',
		);
		$settings[] = array(
			'title'   => __( 'Plural label', 'wmn' ),
			'desc'    => __( 'e.g. "Member Numbers", "Super Club Numbers"', 'wmn' ),
			'id'      => 'wmn_number_label_plural',
			'type'    => 'text',
			'default' => __( 'Member Numbers', 'wmn' ),
			'css'     => 'min-width:250px',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wmn_labels_section',
		);

		// ── Trigger Products ─────────────────────────────────────────────────
		$settings[] = array(
			'title' => __( 'Trigger Products', 'wmn' ),
			'type'  => 'title',
			'desc'  => __( 'Purchasing any of these products will trigger number assignment.', 'wmn' ),
			'id'    => 'wmn_trigger_section',
		);
		$settings[] = array(
			'title' => __( 'Products', 'wmn' ),
			'type'  => 'wmn_product_search',
			'id'    => 'wmn_trigger_product_ids',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wmn_trigger_section',
		);

		// ── Number Format ─────────────────────────────────────────────────────
		$settings[] = array(
			'title' => __( 'Number Format', 'wmn' ),
			'type'  => 'title',
			'desc'  => wp_kses_post(
				__( 'Available tokens: <code>{PREFIX}</code> <code>{SEQ}</code> <code>{YEAR}</code> <code>{MONTH}</code> <code>{RAND4}</code> <code>{RAND6}</code>', 'wmn' )
			),
			'id'    => 'wmn_format_section',
		);
		$settings[] = array(
			'title'    => __( 'Format template', 'wmn' ),
			'desc'     => __( 'e.g. <code>{PREFIX}{SEQ}</code> → MBR-000042', 'wmn' ),
			'id'       => 'wmn_number_format_template',
			'type'     => 'text',
			'default'  => '{PREFIX}{SEQ}',
			'css'      => 'min-width:250px',
			'desc_tip' => true,
		);
		$settings[] = array(
			'title'    => __( 'Prefix', 'wmn' ),
			'desc'     => __( 'Value used for {PREFIX} token.', 'wmn' ),
			'id'       => 'wmn_number_prefix',
			'type'     => 'text',
			'default'  => 'MBR-',
			'desc_tip' => true,
		);
		$settings[] = array(
			'title'             => __( 'Starting sequence', 'wmn' ),
			'desc'              => __( 'First value used for {SEQ} (only applies before any numbers are assigned).', 'wmn' ),
			'id'                => 'wmn_number_start',
			'type'              => 'number',
			'default'           => 1,
			'custom_attributes' => array( 'min' => 1 ),
			'desc_tip'          => true,
		);
		$settings[] = array(
			'title'             => __( 'Sequence pad length', 'wmn' ),
			'desc'              => __( 'Minimum digit width for {SEQ}, zero-padded. e.g. 6 → 000042.', 'wmn' ),
			'id'                => 'wmn_number_pad_length',
			'type'              => 'number',
			'default'           => 6,
			'custom_attributes' => array(
				'min' => 1,
				'max' => 20,
			),
			'desc_tip'          => true,
		);
		$settings[] = array(
			'title'             => __( 'Minimum value', 'wmn' ),
			'desc'              => __( 'Minimum allowed sequence value for chosen numbers.', 'wmn' ),
			'id'                => 'wmn_number_min_value',
			'type'              => 'number',
			'default'           => 1,
			'custom_attributes' => array( 'min' => 1 ),
			'desc_tip'          => true,
		);
		$settings[] = array(
			'title'             => __( 'Maximum value', 'wmn' ),
			'desc'              => __( 'Maximum allowed sequence value for chosen numbers.', 'wmn' ),
			'id'                => 'wmn_number_max_value',
			'type'              => 'number',
			'default'           => 999999,
			'custom_attributes' => array( 'min' => 1 ),
			'desc_tip'          => true,
		);
		// Live preview row (custom type).
		$settings[] = array(
			'title' => __( 'Preview', 'wmn' ),
			'type'  => 'wmn_format_preview',
			'id'    => 'wmn_format_preview',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wmn_format_section',
		);

		// ── Behaviour ─────────────────────────────────────────────────────────
		$settings[] = array(
			'title' => __( 'Behaviour', 'wmn' ),
			'type'  => 'title',
			'id'    => 'wmn_behaviour_section',
		);
		$settings[] = array(
			'title'   => __( 'Assign on status', 'wmn' ),
			'desc'    => __( 'Processing', 'wmn' ),
			'id'      => 'wmn_assign_on_status[processing]',
			'type'    => 'checkbox',
			'default' => 'yes',
		);
		$settings[] = array(
			'desc'    => __( 'Completed', 'wmn' ),
			'id'      => 'wmn_assign_on_status[completed]',
			'type'    => 'checkbox',
			'default' => 'yes',
		);
		$settings[] = array(
			'title'   => __( 'On full refund', 'wmn' ),
			'id'      => 'wmn_refund_behavior',
			'type'    => 'select',
			'default' => 'do_nothing',
			'options' => array(
				'do_nothing'         => __( 'Do nothing (numbers are permanent)', 'wmn' ),
				'suspend'            => __( 'Suspend the number', 'wmn' ),
				'notify_admin_email' => __( 'Notify admin by email', 'wmn' ),
			),
		);
		$settings[] = array(
			'title'    => __( 'Admin notification email', 'wmn' ),
			'id'       => 'wmn_notify_admin_email',
			'type'     => 'email',
			'default'  => get_option( 'admin_email' ),
			'desc_tip' => __( 'Used when "Notify admin by email" is selected above.', 'wmn' ),
		);
		$settings[] = array(
			'title'   => __( 'Customer email', 'wmn' ),
			'desc'    => sprintf(
				/* translators: %s: member number label */
				__( 'Send a confirmation email to the customer when a %s is assigned.', 'wmn' ),
				wmn_get_label()
			),
			'id'      => 'wmn_send_customer_email',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wmn_behaviour_section',
		);

		// ── Chosen Number ─────────────────────────────────────────────────────
		$settings[] = array(
			'title' => __( 'Chosen Number', 'wmn' ),
			'type'  => 'title',
			'desc'  => sprintf(
				/* translators: %s: member number label */
				__( 'Allow customers to choose their own %s for an additional fee.', 'wmn' ),
				wmn_get_label()
			),
			'id'    => 'wmn_chosen_section',
		);
		$settings[] = array(
			'title'   => __( 'Enable', 'wmn' ),
			'desc'    => sprintf(
				/* translators: %s: member number label */
				__( 'Allow customers to choose their own %s', 'wmn' ),
				wmn_get_label()
			),
			'id'      => 'wmn_allow_chosen_number',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'title'             => __( 'Additional fee', 'wmn' ),
			'desc'              => sprintf(
				/* translators: %s: currency name */
				__( 'Extra charge (in %s) for choosing a number.', 'wmn' ),
				get_woocommerce_currency()
			),
			'id'                => 'wmn_chosen_number_fee',
			'type'              => 'text',
			'default'           => '25.00',
			'desc_tip'          => true,
			'custom_attributes' => array( 'inputmode' => 'decimal' ),
		);
		$settings[] = array(
			'title'    => __( 'Fee label', 'wmn' ),
			'desc'     => __( 'Shown in cart and order. Use {label} for the configured number label.', 'wmn' ),
			'id'       => 'wmn_chosen_number_fee_label',
			'type'     => 'text',
			'default'  => 'Custom {label} Selection',
			'desc_tip' => true,
		);
		$settings[] = array(
			'title'             => __( 'Reservation TTL (minutes)', 'wmn' ),
			'desc'              => __( 'How long a chosen number is locked during checkout.', 'wmn' ),
			'id'                => 'wmn_reservation_ttl_minutes',
			'type'              => 'number',
			'default'           => 30,
			'custom_attributes' => array( 'min' => 5 ),
			'desc_tip'          => true,
		);
		$settings[] = array(
			'title'   => __( 'If chosen number is taken', 'wmn' ),
			'desc'    => __( 'What to do if the chosen number is taken between reservation and order completion.', 'wmn' ),
			'id'      => 'wmn_chosen_collision_behavior',
			'type'    => 'select',
			'default' => 'auto_assign',
			'options' => array(
				'auto_assign'     => __( 'Auto-assign next available number and refund the fee', 'wmn' ),
				'hold_for_review' => __( 'Hold order for admin review', 'wmn' ),
			),
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wmn_chosen_section',
		);

		// ── Data ──────────────────────────────────────────────────────────────
		$settings[] = array(
			'title' => __( 'Data', 'wmn' ),
			'type'  => 'title',
			'id'    => 'wmn_data_section',
		);
		$settings[] = array(
			'title'   => __( 'Delete data on uninstall', 'wmn' ),
			'desc'    => __( 'Remove all plugin tables, options, and user data when the plugin is deleted.', 'wmn' ),
			'id'      => 'wmn_delete_data_on_uninstall',
			'type'    => 'checkbox',
			'default' => 'no',
		);
		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wmn_data_section',
		);

		return apply_filters( 'wmn_settings', $settings );
	}

	/**
	 * Outputs the product multi-select search field.
	 *
	 * @param array<string,mixed> $field The field definition array.
	 * @return void
	 */
	public function output_product_search_field( array $field ): void {
		$option_value = (array) get_option( $field['id'], array() );
		$selected     = array();
		foreach ( $option_value as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$selected[ $product_id ] = $product->get_formatted_name();
			}
		}
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field['id'] ); ?>">
					<?php echo esc_html( $field['title'] ); ?>
				</label>
			</th>
			<td class="forminp">
				<select
					id="<?php echo esc_attr( $field['id'] ); ?>"
					name="<?php echo esc_attr( $field['id'] ); ?>[]"
					class="wc-product-search"
					multiple="multiple"
					style="width:350px"
					data-placeholder="<?php esc_attr_e( 'Search for products…', 'wmn' ); ?>"
					data-action="woocommerce_json_search_products_and_variations"
					data-allow_clear="true"
				>
					<?php foreach ( $selected as $id => $name ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" selected="selected">
							<?php echo esc_html( $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Outputs the live number format preview field.
	 *
	 * @param array<string,mixed> $_field The field definition array (unused).
	 * @return void
	 */
	public function output_format_preview_field( array $_field = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		?>
		<tr valign="top" id="wmn-format-preview-row">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Preview', 'wmn' ); ?></th>
			<td class="forminp">
				<code id="wmn-format-preview" class="wmn-format-preview">
					<?php echo esc_html( wmn_get_formatter()->generate( max( 1, (int) get_option( 'wmn_number_start', 1 ) ) ) ); ?>
				</code>
				<span id="wmn-format-preview-loading" style="display:none;">
					<span class="spinner is-active" style="float:none;"></span>
				</span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves all settings fields and the custom product search field.
	 *
	 * @return void
	 */
	public function save(): void {
		global $current_section;

		$settings = $this->get_all_settings();
		WC_Admin_Settings::save_fields( $settings );

		// Save the custom product search field.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product_ids = isset( $_POST['wmn_trigger_product_ids'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( 'absint', (array) wp_unslash( $_POST['wmn_trigger_product_ids'] ) )
			: array();
		update_option( 'wmn_trigger_product_ids', $product_ids );

		// Save assign_on_status as a clean array.
		$statuses = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['wmn_assign_on_status']['processing'] ) ) {
			$statuses[] = 'processing';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['wmn_assign_on_status']['completed'] ) ) {
			$statuses[] = 'completed';
		}
		update_option( 'wmn_assign_on_status', $statuses );

		// Sync wmn_number_start into wmn_last_sequence only if no numbers have been assigned yet.
		if ( 0 === (int) get_option( 'wmn_last_sequence', 0 ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$start = absint( $_POST['wmn_number_start'] ?? 1 );
			update_option( 'wmn_last_sequence', max( 0, $start - 1 ), false );
		}
	}
}
