<?php
/**
 * Plugin Name: Product Porter for WooCommerce
 * Plugin URI: https://example.com
 * Description: Export and import WooCommerce products using portable zip packages.
 * Version: 0.1.0
 * Author: Product Porter Team
 * Author URI: https://example.com
 * Text Domain: wc-product-porter
 * Domain Path: /languages
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * WC tested up to: 8.0
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WCPP_VERSION', '0.1.0' );
define( 'WCPP_PLUGIN_FILE', __FILE__ );
define( 'WCPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Register activation hook to validate environment requirements.
 *
 * @param bool $network_wide Whether activated network-wide.
 */
function wcpp_activate_plugin( $network_wide ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'Product Porter for WooCommerce requires the PHP ZipArchive extension. Please enable it and try again.', 'wc-product-porter' ) );
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'Product Porter for WooCommerce requires WooCommerce to be active.', 'wc-product-porter' ) );
	}
}
register_activation_hook( __FILE__, 'wcpp_activate_plugin' );

/**
 * Load plugin text domain and bootstrap the primary class.
 */
function wcpp_plugins_loaded() {
	load_plugin_textdomain( 'wc-product-porter', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once WCPP_PLUGIN_DIR . 'includes/class-wcpp-main.php';

	WCPP_Main::instance();
}
add_action( 'plugins_loaded', 'wcpp_plugins_loaded', 11 );

/**
 * Display admin notice when ZipArchive is not available.
 */
function wcpp_ziparchive_admin_notice() {
	if ( class_exists( 'ZipArchive' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__( 'Warning: PHP ZipArchive extension is not installed. Product Porter for WooCommerce cannot function without it.', 'wc-product-porter' ) . '</p></div>';
}
add_action( 'admin_notices', 'wcpp_ziparchive_admin_notice' );
