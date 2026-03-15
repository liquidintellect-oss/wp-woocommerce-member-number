<?php
/**
 * Plugin Name:       WooCommerce Member Number
 * Plugin URI:        https://github.com/liquidintellect-oss/wp-woocommerce-member-number
 * Description:       Assigns a unique, configurable number to customers when a configured product is purchased.
 * Version:           @projectVersion@
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Liquid Intellect, Inc. - OSS
 * Text Domain:       wmn
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package WooCommerce_Member_Number
 */

defined( 'ABSPATH' ) || exit;

define( 'WMN_VERSION', '@projectVersion@' );
define( 'WMN_PLUGIN_FILE', __FILE__ );
define( 'WMN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Declare HPOS compatibility before WooCommerce initialises.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WMN_PLUGIN_FILE,
				true
			);
		}
	}
);

// Activation hook — must be registered before plugins_loaded.
register_activation_hook(
	__FILE__,
	function () {
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-install.php';
		WMN_Install::install();
	}
);

// Deactivation hook — clear cron.
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'wmn_cleanup_reservations' );
	}
);

// Boot the plugin.
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
					esc_html__( 'WooCommerce Member Number requires WooCommerce to be active.', 'wmn' ) .
					'</p></div>';
				}
			);
			return;
		}

		require_once WMN_PLUGIN_DIR . 'includes/functions.php';
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-install.php';
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-member-number.php';
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-number-formatter.php';
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-member-number-manager.php';
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-chosen-number.php';
		require_once WMN_PLUGIN_DIR . 'includes/class-wmn-plugin.php';

		WMN_Install::check_version();
		WMN_Plugin::instance();
	},
	10
);
