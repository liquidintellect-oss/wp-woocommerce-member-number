<?php
/**
 * HTML email: member number assigned.
 *
 * This template can be overridden in a child theme at:
 * woocommerce/emails/wmn-number-assigned.php
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $member_number
 * @var string   $label
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;
?>
<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php
	printf(
		/* translators: 1: customer first name */
		esc_html__( 'Hi %s,', 'wmn' ),
		esc_html( $order->get_billing_first_name() )
	);
?></p>

<p><?php
	printf(
		/* translators: 1: label */
		esc_html__( 'Congratulations! Your %s has been assigned.', 'wmn' ),
		esc_html( $label )
	);
?></p>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:20px 0;">
	<tr>
		<th style="text-align:left;padding:10px;background:#f8f8f8;">
			<?php echo esc_html( $label ); ?>
		</th>
		<td style="text-align:left;padding:10px;font-size:24px;font-weight:bold;letter-spacing:2px;">
			<?php echo esc_html( $member_number ); ?>
		</td>
	</tr>
</table>

<p><?php esc_html_e( 'Please keep this number for your records.', 'wmn' ); ?></p>

<?php do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email ); ?>
<?php do_action( 'woocommerce_email_footer', $email ); ?>
