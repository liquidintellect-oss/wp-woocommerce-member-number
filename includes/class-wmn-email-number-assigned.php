<?php
/**
 * WooCommerce email sent when a member number is assigned.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email notification sent to the customer when a member number is assigned.
 */
class WMN_Email_Number_Assigned extends WC_Email {

	/**
	 * The assigned member number.
	 *
	 * @var string
	 */
	public string $member_number = '';

	/**
	 * Constructor — sets up email metadata and default subject/heading.
	 */
	public function __construct() {
		$this->id             = 'wmn_number_assigned';
		$this->customer_email = true;
		/* translators: %s: member number label */
		$this->title       = sprintf( __( '%s Assigned', 'wmn' ), wmn_get_label() );
		$this->description = sprintf(
			/* translators: %s: member number label */
			__( 'Sent to the customer when a %s is assigned to their account.', 'wmn' ),
			wmn_get_label()
		);
		$this->template_html  = 'emails/wmn-number-assigned.php';
		$this->template_plain = 'emails/plain/wmn-number-assigned.php';
		$this->template_base  = WMN_PLUGIN_DIR . 'templates/';

		$this->subject = sprintf(
			/* translators: %s: label */
			__( 'Your %s for {site_title}', 'wmn' ),
			wmn_get_label()
		);
		$this->heading = sprintf(
			/* translators: %s: label */
			__( 'Your %s', 'wmn' ),
			wmn_get_label()
		);

		parent::__construct();
	}

	/**
	 * Trigger the email.
	 *
	 * @param int      $order_id      The order ID.
	 * @param WC_Order $order         The order object.
	 * @param string   $member_number The assigned member number.
	 */
	public function trigger( int $order_id, WC_Order $order, string $member_number ): void {
		$this->setup_locale();
		$this->object        = $order;
		$this->member_number = $member_number;
		$this->recipient     = $order->get_billing_email();

		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content(),
				$this->get_headers(),
				$this->get_attachments()
			);
		}

		$this->restore_locale();
	}

	/**
	 * Returns the HTML email content.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'member_number' => $this->member_number,
				'label'         => wmn_get_label(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Returns the plain-text email content.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'member_number' => $this->member_number,
				'label'         => wmn_get_label(),
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}
}
