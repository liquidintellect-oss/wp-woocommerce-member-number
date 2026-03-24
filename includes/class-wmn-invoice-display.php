<?php
/**
 * Displays the member number on WooCommerce invoices and packing slips.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into WooCommerce email order meta and the WooCommerce PDF Invoices &
 * Packing Slips (WCPDF) plugin to surface the assigned member number on both
 * transactional emails and PDF documents.
 */
class WMN_Invoice_Display {

	/**
	 * Registers hooks on construction.
	 */
	public function __construct() {
		add_action( 'woocommerce_email_order_meta', array( $this, 'add_to_email' ), 10, 4 );
		add_action( 'wpo_wcpdf_after_order_data', array( $this, 'add_to_wcpdf' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'add_to_order_admin' ), 10, 1 );
	}

	/**
	 * Looks up the member number record for an order, falling back to the
	 * customer's user account if no order-level record exists.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return WMN_Member_Number|null
	 */
	protected function get_record_for_order( WC_Order $order ): ?WMN_Member_Number {
		$record = WMN_Member_Number::get_by_order( $order->get_id() );
		if ( ! $record ) {
			$user_id = $order->get_customer_id();
			if ( $user_id ) {
				$record = WMN_Member_Number::get_by_user( (int) $user_id );
			}
		}
		return $record;
	}

	/**
	 * Appends the member number to WooCommerce email order meta sections.
	 *
	 * Skips the plugin's own assignment email, which already displays the
	 * number prominently.
	 *
	 * @param WC_Order $order         The order.
	 * @param bool     $sent_to_admin Whether the email is sent to the admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @param WC_Email $email         The email object.
	 * @return void
	 */
	public function add_to_email( WC_Order $order, bool $sent_to_admin, bool $plain_text, WC_Email $email ): void {
		// The assignment email already shows the number front-and-centre.
		if ( 'wmn_number_assigned' === $email->id ) {
			return;
		}

		$record = $this->get_record_for_order( $order );
		if ( ! $record ) {
			return;
		}

		$label  = wmn_get_label();
		$number = $record->member_number;

		if ( $plain_text ) {
			printf(
				/* translators: 1: member number label, 2: member number */
				esc_html__( '%1$s: %2$s', 'wmn' ),
				esc_html( $label ),
				esc_html( $number )
			);
			echo "\n\n";
			return;
		}

		echo '<h2>' . esc_html( $label ) . '</h2>';
		echo '<p>' . esc_html( $number ) . '</p>';
	}

	/**
	 * Displays the member number on the WooCommerce admin order edit screen.
	 *
	 * Fires via woocommerce_admin_order_data_after_billing_address, which
	 * receives a WC_Order in both legacy (post-based) and HPOS order table modes.
	 *
	 * @param WC_Order $order The order being viewed.
	 * @return void
	 */
	public function add_to_order_admin( WC_Order $order ): void {
		$record = $this->get_record_for_order( $order );
		if ( ! $record ) {
			return;
		}

		$label  = wmn_get_label();
		$number = $record->member_number;
		?>
		<div class="wmn-order-member-number" style="margin-top:12px;">
			<p>
				<strong><?php echo esc_html( $label ); ?>:</strong>
				<?php echo esc_html( $number ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Appends the member number to WooCommerce PDF Invoices & Packing Slips
	 * (WCPDF) documents.
	 *
	 * @param string $document_type The WCPDF document type, e.g. 'invoice' or 'packing-slip'.
	 * @param object $document      The WCPDF document object (exposes get_order()).
	 * @return void
	 */
	public function add_to_wcpdf( string $document_type, object $document ): void {
		$order = $document->get_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$record = $this->get_record_for_order( $order );
		if ( ! $record ) {
			return;
		}

		$label  = wmn_get_label();
		$number = $record->member_number;
		?>
		<table class="wmn-member-number">
			<tr>
				<th><?php echo esc_html( $label ); ?></th>
				<td><?php echo esc_html( $number ); ?></td>
			</tr>
		</table>
		<?php
	}
}
