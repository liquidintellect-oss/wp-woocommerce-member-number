<?php
/**
 * Main plugin bootstrap class.
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton that wires up all components.
 */
final class WMN_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WMN_Plugin|null
	 */
	private static ?WMN_Plugin $instance = null;

	/**
	 * The number manager component.
	 *
	 * @var WMN_Member_Number_Manager
	 */
	public WMN_Member_Number_Manager $manager;

	/**
	 * The chosen-number checkout component.
	 *
	 * @var WMN_Chosen_Number
	 */
	public WMN_Chosen_Number $chosen;

	/**
	 * Returns (or creates) the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers all hooks and initialises components.
	 */
	private function __construct() {
		$this->manager = new WMN_Member_Number_Manager();
		$this->chosen  = new WMN_Chosen_Number();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'my_account_display' ) );

		if ( is_admin() ) {
			$this->load_admin();
		}
	}

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wmn', false, dirname( plugin_basename( WMN_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the member number assignment email class with WooCommerce.
	 *
	 * @param array<string,WC_Email> $emails Existing WooCommerce email classes.
	 * @return array<string,WC_Email>
	 */
	public function register_emails( array $emails ): array {
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-email-number-assigned.php';
		$emails['WMN_Email_Number_Assigned'] = new WMN_Email_Number_Assigned();
		return $emails;
	}

	/**
	 * Outputs the member number on the My Account dashboard.
	 *
	 * @return void
	 */
	public function my_account_display(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$number = get_user_meta( $user_id, '_wmn_member_number', true );
		if ( ! $number ) {
			return;
		}
		echo '<p class="wmn-my-account-number"><strong>' .
			esc_html(
				sprintf(
					/* translators: %s: member number label */
					__( 'Your %s:', 'wmn' ),
					wmn_get_label()
				)
			) .
			'</strong> ' . esc_html( $number ) . '</p>';
	}

	/**
	 * Loads admin-only classes and instantiates admin objects.
	 *
	 * @return void
	 */
	private function load_admin(): void {
		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-admin.php';
		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-admin-menus.php';
		// class-wmn-settings-page.php extends WC_Settings_Page, which is not
		// available at plugins_loaded. It is required lazily inside
		// WMN_Admin_Menus::register_settings_page() via woocommerce_get_settings_pages.
		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-member-list-table.php';
		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-admin-user-profile.php';
		require_once WMN_PLUGIN_DIR . 'includes/admin/class-wmn-admin-product.php';

		new WMN_Admin();
		new WMN_Admin_Menus();
		new WMN_Admin_User_Profile();
		new WMN_Admin_Product();
	}
}
