<?php
/**
 * Admin integrations.
 */

defined( 'ABSPATH' ) || exit;

class WCPP_Admin {
	/**
	 * Main plugin instance.
	 *
	 * @var WCPP_Main
	 */
	protected $plugin;

	/**
	 * Import page hook suffix.
	 *
	 * @var string
	 */
	protected $import_page_hook = '';

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	protected $settings_page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param WCPP_Main $plugin Main plugin instance.
	 */
	public function __construct( WCPP_Main $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_display_export_notice' ) );
	}

	/**
	 * Register admin menu entries.
	 */
	public function register_menus() {
		$parent_slug = 'edit.php?post_type=product';

		$this->import_page_hook = add_submenu_page(
			$parent_slug,
			__( 'Product Porter', 'wc-product-porter' ),
			__( 'Product Porter', 'wc-product-porter' ),
			'manage_woocommerce',
			'wcpp-product-porter',
			array( $this, 'render_import_page' )
		);

		$this->settings_page_hook = add_submenu_page(
			$parent_slug,
			__( 'Porter Settings', 'wc-product-porter' ),
			__( 'Porter Settings', 'wc-product-porter' ),
			'manage_woocommerce',
			'wcpp-porter-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		$assets_url = trailingslashit( WCPP_PLUGIN_URL );

		if ( $hook === $this->import_page_hook || $hook === $this->settings_page_hook ) {
			wp_enqueue_style( 'wcpp-admin', $assets_url . 'assets/css/wcpp-admin-style.css', array(), WCPP_VERSION );
		}

		if ( $hook === $this->import_page_hook ) {
			wp_enqueue_script( 'wcpp-importer', $assets_url . 'assets/js/wcpp-importer.js', array( 'jquery' ), WCPP_VERSION, true );
			wp_localize_script(
				'wcpp-importer',
				'wcppImporter',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wcpp_import_nonce' ),
					'strings' => array(
						'uploading'     => __( 'Uploading package…', 'wc-product-porter' ),
						'processing'    => __( 'Processing batch', 'wc-product-porter' ),
						'completed'     => __( 'Import complete.', 'wc-product-porter' ),
						'error'         => __( 'An error occurred during import.', 'wc-product-porter' ),
						'cleanup'       => __( 'Cleaning up temporary files…', 'wc-product-porter' ),
						'confirmCancel' => __( 'Cancel the current import?', 'wc-product-porter' ),
						'missingFile'   => __( 'Please choose a package before starting the import.', 'wc-product-porter' ),
						'cancelled'     => __( 'Import cancelled by user.', 'wc-product-porter' ),
					),
				)
			);
		}
	}

	/**
	 * Display export related admin notices.
	 */
	public function maybe_display_export_notice() {
		if ( empty( $_GET['wcpp_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['wcpp_export'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$message = '';
		$type    = 'error';

		switch ( $code ) {
			case 'empty':
				$message = __( 'Please select at least one product to export.', 'wc-product-porter' );
				$type    = 'warning';
				break;
			case 'temp_file':
				$message = __( 'Unable to create a temporary file for the export package.', 'wc-product-porter' );
				break;
			case 'zip_open':
				$message = __( 'Unable to create the export archive. Please verify file permissions.', 'wc-product-porter' );
				break;
			case 'json_encode':
				$message = __( 'Unable to bundle the product data. Please try again.', 'wc-product-porter' );
				break;
			default:
				return;
		}

		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render import page.
	 */
	public function render_import_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-product-porter' ) );
		}

		$settings = $this->plugin->get_settings();

		require WCPP_PLUGIN_DIR . 'templates/admin-import-page.php';
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-product-porter' ) );
		}

		$settings = $this->plugin->get_settings();

		require WCPP_PLUGIN_DIR . 'templates/admin-settings-page.php';
	}
}
