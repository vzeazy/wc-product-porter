<?php
/**
 * Settings page template.
 *
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wcpp-settings">
	<h1><?php esc_html_e( 'Porter Settings', 'wc-product-porter' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Configure which additional product data should be included in export packages.', 'wc-product-porter' ); ?></p>

	<?php settings_errors( 'wcpp_settings_group' ); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
		<?php
		settings_fields( 'wcpp_settings_group' );
		do_settings_sections( 'wcpp_settings_page' );
		submit_button();
		?>
	</form>
</div>
