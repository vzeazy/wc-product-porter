<?php
/**
 * Main plugin bootstrap class.
 *
 * @package ProductPorter
 */

defined( 'ABSPATH' ) || exit;

class WCPP_Main {
	/**
	 * Singleton instance.
	 *
	 * @var WCPP_Main|null
	 */
	protected static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var WCPP_Settings
	 */
	protected $settings;

	/**
	 * Admin handler.
	 *
	 * @var WCPP_Admin
	 */
	protected $admin;

	/**
	 * Export handler.
	 *
	 * @var WCPP_Export
	 */
	protected $export;

	/**
	 * Import handler.
	 *
	 * @var WCPP_Import
	 */
	protected $import;

	/**
	 * AJAX handler.
	 *
	 * @var WCPP_Ajax
	 */
	protected $ajax;

	/**
	 * Return singleton instance.
	 *
	 * @return WCPP_Main
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->includes();
		$this->init_components();
	}

	/**
	 * Load required class files.
	 */
	protected function includes() {
		require_once WCPP_PLUGIN_DIR . 'includes/class-wcpp-settings.php';
		require_once WCPP_PLUGIN_DIR . 'includes/class-wcpp-admin.php';
		require_once WCPP_PLUGIN_DIR . 'includes/class-wcpp-export.php';
		require_once WCPP_PLUGIN_DIR . 'includes/class-wcpp-import.php';
		require_once WCPP_PLUGIN_DIR . 'includes/class-wcpp-ajax.php';
	}

	/**
	 * Instantiate core components.
	 */
	protected function init_components() {
		$this->settings = new WCPP_Settings();
		$this->export   = new WCPP_Export( $this );
		$this->import   = new WCPP_Import( $this );
		$this->ajax     = new WCPP_Ajax( $this );

		if ( is_admin() ) {
			$this->admin = new WCPP_Admin( $this );
		}
	}

	/**
	 * Retrieve plugin settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->settings->get_settings();
	}

	/**
	 * Return option value with default.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Return import handler instance.
	 *
	 * @return WCPP_Import
	 */
	public function get_importer() {
		return $this->import;
	}
}

/**
 * Helper to fetch plugin settings.
 *
 * @param string $key Optional key.
 * @param mixed  $default Default value.
 *
 * @return mixed
 */
function wcpp_get_setting( $key = '', $default = '' ) {
	$instance = WCPP_Main::instance();

	if ( '' === $key ) {
		return $instance->get_settings();
	}

	return $instance->get_setting( $key, $default );
}
