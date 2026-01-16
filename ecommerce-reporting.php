<?php
/**
 * Plugin Name: Ecommerce Reporting
 * Description: Lightweight WooCommerce reporting with aggregated daily metrics and basic marketing attribution.
 * Version: 0.1.0
 * Author: OpenAI
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: ecommerce-reporting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ER_PLUGIN_VERSION', '0.1.0' );
define( 'ER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ER_PLUGIN_DIR . 'includes/class-er-activator.php';
require_once ER_PLUGIN_DIR . 'includes/class-er-deactivator.php';
require_once ER_PLUGIN_DIR . 'includes/class-er-reports.php';
require_once ER_PLUGIN_DIR . 'includes/class-er-admin.php';

register_activation_hook( __FILE__, array( 'ER_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ER_Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'Ecommerce Reporting requires WooCommerce to be installed and active.', 'ecommerce-reporting' );
					echo '</p></div>';
				}
			);
			return;
		}

		ER_Reports::init();
		ER_Admin::init();
	}
);
