<?php
/**
 * Plain-text email: member number assigned.
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $member_number
 * @var string   $label
 */
defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";

printf(
	/* translators: 1: first name */
	esc_html__( 'Hi %s,', 'wmn' ),
	esc_html( $order->get_billing_first_name() )
);
echo "\n\n";

printf(
	/* translators: 1: label */
	esc_html__( 'Congratulations! Your %s has been assigned.', 'wmn' ),
	esc_html( $label )
);
echo "\n\n";

printf(
	/* translators: 1: label, 2: number */
	esc_html__( '%1$s: %2$s', 'wmn' ),
	esc_html( $label ),
	esc_html( $member_number )
);
echo "\n\n";
echo esc_html__( 'Please keep this number for your records.', 'wmn' );
echo "\n\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
