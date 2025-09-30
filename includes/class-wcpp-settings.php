<?php
/**
 * Settings handler.
 */

defined( 'ABSPATH' ) || exit;

class WCPP_Settings {
	/**
	 * Option name.
	 */
	const OPTION_KEY = 'wcpp_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings and fields.
	 */
	public function register_settings() {
		register_setting(
			'wcpp_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'custom_meta_keys'   => array(),
					'custom_taxonomies' => array(),
				),
			)
		);

		add_settings_section(
			'wcpp_settings_section_general',
			__( 'Migration Options', 'wc-product-porter' ),
			array( $this, 'render_general_section' ),
			'wcpp_settings_page'
		);

		add_settings_field(
			'wcpp_custom_meta_keys',
			__( 'Custom Meta Keys', 'wc-product-porter' ),
			array( $this, 'render_custom_meta_field' ),
			'wcpp_settings_page',
			'wcpp_settings_section_general'
		);

		add_settings_field(
			'wcpp_custom_taxonomies',
			__( 'Custom Taxonomies', 'wc-product-porter' ),
			array( $this, 'render_custom_taxonomy_field' ),
			'wcpp_settings_page',
			'wcpp_settings_section_general'
		);
	}

	/**
	 * Render section description.
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Define additional product data to include when exporting and importing packages.', 'wc-product-porter' ) . '</p>';
	}

	/**
	 * Render custom meta textarea field.
	 */
	public function render_custom_meta_field() {
		$value = implode( "\n", $this->get_custom_meta_keys() );
		echo '<textarea name="' . esc_attr( self::OPTION_KEY . '[custom_meta_keys]' ) . '" rows="6" cols="50" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Enter one custom meta key per line. Example: _custom_field_name', 'wc-product-porter' ) . '</p>';
	}

	/**
	 * Render custom taxonomy textarea field.
	 */
	public function render_custom_taxonomy_field() {
		$value = implode( "\n", $this->get_custom_taxonomies() );
		echo '<textarea name="' . esc_attr( self::OPTION_KEY . '[custom_taxonomies]' ) . '" rows="6" cols="50" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Enter one custom taxonomy slug per line. Example: pwb-brand', 'wc-product-porter' ) . '</p>';
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array $input Raw settings.
	 *
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		$meta_input = isset( $input['custom_meta_keys'] ) ? wp_unslash( $input['custom_meta_keys'] ) : '';
		$tax_input  = isset( $input['custom_taxonomies'] ) ? wp_unslash( $input['custom_taxonomies'] ) : '';

		$sanitized['custom_meta_keys']   = $this->sanitize_lines( $meta_input );
		$sanitized['custom_taxonomies'] = $this->sanitize_lines( $tax_input );

		return $sanitized;
	}

	/**
	 * Convert textarea input into array of unique, sanitized lines.
	 *
	 * @param string $value Raw textarea value.
	 *
	 * @return array
	 */
	protected function sanitize_lines( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		$value = (string) $value;
		$lines = preg_split( '/\r?\n/', $value );
		$lines = array_filter( array_map( 'trim', $lines ) );
		$lines = array_unique( $lines );

		return array_map( 'sanitize_key', $lines );
	}

	/**
	 * Fetch settings from database.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args(
			$settings,
			array(
				'custom_meta_keys'   => array(),
				'custom_taxonomies' => array(),
			)
		);
	}

	/**
	 * Return custom meta keys array.
	 *
	 * @return array
	 */
	public function get_custom_meta_keys() {
		$settings = $this->get_settings();

		return isset( $settings['custom_meta_keys'] ) && is_array( $settings['custom_meta_keys'] ) ? $settings['custom_meta_keys'] : array();
	}

	/**
	 * Return custom taxonomy slugs.
	 *
	 * @return array
	 */
	public function get_custom_taxonomies() {
		$settings = $this->get_settings();

		return isset( $settings['custom_taxonomies'] ) && is_array( $settings['custom_taxonomies'] ) ? $settings['custom_taxonomies'] : array();
	}
}
