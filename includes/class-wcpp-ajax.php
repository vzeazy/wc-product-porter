<?php
/**
 * AJAX handlers.
 */

defined( 'ABSPATH' ) || exit;

class WCPP_Ajax {
	/**
	 * Main plugin instance.
	 *
	 * @var WCPP_Main
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param WCPP_Main $plugin Main plugin instance.
	 */
	public function __construct( WCPP_Main $plugin ) {
		$this->plugin = $plugin;

		add_action( 'wp_ajax_wcpp_import_setup', array( $this, 'handle_setup' ) );
		add_action( 'wp_ajax_wcpp_process_batch', array( $this, 'handle_process_batch' ) );
		add_action( 'wp_ajax_wcpp_import_cleanup', array( $this, 'handle_cleanup' ) );
	}

	/**
	 * Handle setup request.
	 */
	public function handle_setup() {
		$this->verify_request();

		if ( empty( $_FILES['import_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select a Product Porter package.', 'wc-product-porter' ) ) );
		}

		$update_existing = ! empty( $_POST['update_existing'] ); // phpcs:ignore WordPress.Security.NonceVerification

		$result = $this->get_importer()->handle_setup( $_FILES['import_file'], $update_existing );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle batch processing request.
	 */
	public function handle_process_batch() {
		$this->verify_request();

		$import_id    = isset( $_POST['import_id'] ) ? sanitize_key( wp_unslash( $_POST['import_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$batch_number = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $import_id ) {
			wp_send_json_error( array( 'message' => __( 'Import session is missing or has expired.', 'wc-product-porter' ) ) );
		}

		$result = $this->get_importer()->process_batch( $import_id, $batch_number );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle cleanup request.
	 */
	public function handle_cleanup() {
		$this->verify_request();

		$import_id = isset( $_POST['import_id'] ) ? sanitize_key( wp_unslash( $_POST['import_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $import_id ) {
			wp_send_json_success();
		}

		$result = $this->get_importer()->cleanup( $import_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	/**
	 * Verify AJAX request permissions and nonce.
	 */
	protected function verify_request() {
		check_ajax_referer( 'wcpp_import_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'wc-product-porter' ) ) );
		}
	}

	/**
	 * Retrieve importer instance.
	 *
	 * @return WCPP_Import
	 */
	protected function get_importer() {
		return $this->plugin->get_importer();
	}
}
