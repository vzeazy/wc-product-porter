<?php
/**
 * Export handler.
 */

defined( 'ABSPATH' ) || exit;

class WCPP_Export {
	/**
	 * Main plugin instance.
	 *
	 * @var WCPP_Main
	 */
	protected $plugin;

	/**
	 * Cached filename usage map.
	 *
	 * @var array
	 */
	protected $used_filenames = array();

	/**
	 * Attachment to filename map.
	 *
	 * @var array
	 */
	protected $image_map = array();

	/**
	 * Constructor.
	 *
	 * @param WCPP_Main $plugin Main plugin instance.
	 */
	public function __construct( WCPP_Main $plugin ) {
		$this->plugin = $plugin;

		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Register custom bulk action.
	 *
	 * @param array $actions Existing actions.
	 *
	 * @return array
	 */
	public function register_bulk_action( $actions ) {
		$actions['wcpp_export'] = __( 'Export with Porter', 'wc-product-porter' );

		return $actions;
	}

	/**
	 * Handle export action.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Current action.
	 * @param array  $post_ids    Selected post IDs.
	 *
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( 'wcpp_export' !== $action ) {
			return $redirect_to;
		}

		check_admin_referer( 'bulk-posts' );

		if ( ! current_user_can( 'export' ) ) {
			wp_die( esc_html__( 'You do not have permission to export products.', 'wc-product-porter' ) );
		}

		if ( empty( $post_ids ) ) {
			return add_query_arg( 'wcpp_export', 'empty', $redirect_to );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The PHP ZipArchive extension is required to export products.', 'wc-product-porter' ) );
		}

		$export_file = $this->build_export_package( array_map( 'absint', $post_ids ) );

		if ( is_wp_error( $export_file ) ) {
			return add_query_arg( 'wcpp_export', $export_file->get_error_code(), $redirect_to );
		}

		$this->stream_file_and_exit( $export_file );

		return $redirect_to;
	}

	/**
	 * Build export package and return archive path.
	 *
	 * @param array $product_ids Product IDs.
	 *
	 * @return string|WP_Error
	 */
	protected function build_export_package( $product_ids ) {
		$this->used_filenames = array();
		$this->image_map      = array();
		$products_data = array();
		$zip           = new ZipArchive();
		$tmp_file      = wp_tempnam( 'wcpp-export' );

		if ( empty( $tmp_file ) ) {
			return new WP_Error( 'temp_file', __( 'Unable to create a temporary file for the export package.', 'wc-product-porter' ) );
		}

		if ( true !== $zip->open( $tmp_file, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_open', __( 'Unable to create the export archive.', 'wc-product-porter' ) );
		}

		$zip->addEmptyDir( 'images' );

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$products_data[] = $this->prepare_product_data( $product, $zip );
		}

		$json = wp_json_encode( $products_data, JSON_PRETTY_PRINT );

		if ( false === $json ) {
			$zip->close();
			unlink( $tmp_file );

			return new WP_Error( 'json_encode', __( 'Failed to encode products data to JSON.', 'wc-product-porter' ) );
		}

		$zip->addFromString( 'products.json', $json );
		$zip->close();

		return $tmp_file;
	}

	/**
	 * Prepare product data array.
	 *
	 * @param WC_Product $product Product instance.
	 * @param ZipArchive $zip     Archive instance.
	 *
	 * @return array
	 */
	protected function prepare_product_data( WC_Product $product, ZipArchive $zip ) {
		$product_id         = $product->get_id();
		$custom_meta_keys   = $this->plugin->get_setting( 'custom_meta_keys', array() );
		$custom_taxonomies  = $this->plugin->get_setting( 'custom_taxonomies', array() );
		$featured_image     = $this->add_image_to_archive( $zip, $product->get_image_id() );
		$gallery_image_ids  = $product->get_gallery_image_ids();
		$gallery_images     = array();

		foreach ( $gallery_image_ids as $attachment_id ) {
			$filename = $this->add_image_to_archive( $zip, $attachment_id );
			if ( $filename ) {
				$gallery_images[] = $filename;
			}
		}

		$product_data = array(
			'id'                 => $product_id,
			'type'               => $product->get_type(),
			'sku'                => $product->get_sku(),
			'name'               => $product->get_name(),
			'slug'               => $product->get_slug(),
			'status'             => $product->get_status(),
			'description'        => $product->get_description(),
			'short_description'  => $product->get_short_description(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'featured'           => $product->get_featured(),
			'reviews_allowed'    => $product->get_reviews_allowed(),
			'menu_order'         => $product->get_menu_order(),
			'virtual'            => $product->get_virtual(),
			'downloadable'       => $product->get_downloadable(),
			'download_limit'     => $product->get_download_limit(),
			'download_expiry'    => $product->get_download_expiry(),
			'pricing'            => array(
				'regular_price'        => $product->get_regular_price(),
				'sale_price'           => $product->get_sale_price(),
				'sale_price_dates_from' => $this->format_date( $product->get_date_on_sale_from() ),
				'sale_price_dates_to'   => $this->format_date( $product->get_date_on_sale_to() ),
				'manage_stock'          => $product->get_manage_stock(),
				'stock_quantity'        => $product->get_stock_quantity(),
				'stock_status'          => $product->get_stock_status(),
				'backorders'            => $product->get_backorders(),
				'sold_individually'     => $product->get_sold_individually(),
				'purchase_note'         => $product->get_purchase_note(),
			),
			'shipping'          => array(
				'weight'          => $product->get_weight(),
				'length'          => $product->get_length(),
				'width'           => $product->get_width(),
				'height'          => $product->get_height(),
				'shipping_class'  => $product->get_shipping_class(),
			),
			'images'            => array(
				'featured' => $featured_image,
				'gallery'  => $gallery_images,
			),
			'taxonomies'        => $this->gather_taxonomies( $product_id, $custom_taxonomies ),
			'attributes'        => $this->gather_attributes( $product ),
			'meta'              => $this->gather_custom_meta( $product_id, $custom_meta_keys ),
			'variations'        => $this->gather_variations( $product, $zip ),
		);

		$date_created = $product->get_date_created();
		$date_modified = $product->get_date_modified();

		if ( $date_created ) {
			$product_data['date_created'] = $date_created->date( DATE_ATOM );
		}

		if ( $date_modified ) {
			$product_data['date_modified'] = $date_modified->date( DATE_ATOM );
		}

		return $product_data;
	}

	/**
	 * Gather taxonomy term slugs for core and custom taxonomies.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $custom_taxonomies Custom taxonomy slugs.
	 *
	 * @return array
	 */
	protected function gather_taxonomies( $product_id, $custom_taxonomies ) {
		$taxonomies = array();

		$core_taxonomies = array( 'product_cat', 'product_tag' );

		foreach ( $core_taxonomies as $taxonomy ) {
			$taxonomies[ $taxonomy ] = $this->get_term_slugs( $product_id, $taxonomy );
		}

		$custom_taxonomies = is_array( $custom_taxonomies ) ? $custom_taxonomies : array();

		foreach ( $custom_taxonomies as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$taxonomies[ $taxonomy ] = $this->get_term_slugs( $product_id, $taxonomy );
			}
		}

		return $taxonomies;
	}

	/**
	 * Return term slugs for a taxonomy.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy name.
	 *
	 * @return array
	 */
	protected function get_term_slugs( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return wp_list_pluck( $terms, 'slug' );
	}

	/**
	 * Gather custom meta data.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $meta_keys  Meta keys.
	 *
	 * @return array
	 */
	protected function gather_custom_meta( $product_id, $meta_keys ) {
		$meta = array();

		$meta_keys = is_array( $meta_keys ) ? $meta_keys : array();

		foreach ( $meta_keys as $meta_key ) {
			$value = get_post_meta( $product_id, $meta_key, true );

			if ( '' === $value ) {
				continue;
			}

			$meta[ $meta_key ] = maybe_unserialize( $value );
		}

		return $meta;
	}

	/**
	 * Gather attributes for a product.
	 *
	 * @param WC_Product $product Product instance.
	 *
	 * @return array
	 */
	protected function gather_attributes( WC_Product $product ) {
		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			$name        = $attribute->get_name();
			$options     = array();
			$is_taxonomy = $attribute->is_taxonomy();

			if ( $is_taxonomy ) {
				$options = wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'slugs' ) );
			} else {
				$options = array_map( 'wc_clean', $attribute->get_options() );
			}

			$attributes[] = array(
				'id'           => $attribute->get_id(),
				'name'         => $name,
				'slug'         => $is_taxonomy ? wc_sanitize_taxonomy_name( $name ) : $name,
				'is_taxonomy'  => $is_taxonomy,
				'visible'      => $attribute->get_visible(),
				'variation'    => $attribute->get_variation(),
				'position'     => $attribute->get_position(),
				'options'      => $options,
			);
		}

		return $attributes;
	}

	/**
	 * Gather variation data for variable products.
	 *
	 * @param WC_Product $product Product instance.
	 * @param ZipArchive $zip     Archive instance.
	 *
	 * @return array
	 */
	protected function gather_variations( WC_Product $product, ZipArchive $zip ) {
		if ( 'variable' !== $product->get_type() ) {
			return array();
		}

		$variations = array();

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$variation_image = $this->add_image_to_archive( $zip, $variation->get_image_id() );

			$variation_data = array(
				'id'                 => $variation_id,
				'sku'                => $variation->get_sku(),
				'status'             => $variation->get_status(),
				'price'              => array(
					'regular_price' => $variation->get_regular_price(),
					'sale_price'    => $variation->get_sale_price(),
				),
				'manage_stock'       => $variation->get_manage_stock(),
				'stock_quantity'     => $variation->get_stock_quantity(),
				'stock_status'       => $variation->get_stock_status(),
				'backorders'         => $variation->get_backorders(),
				'weight'             => $variation->get_weight(),
				'dimensions'         => array(
					'length' => $variation->get_length(),
					'width'  => $variation->get_width(),
					'height' => $variation->get_height(),
				),
				'image'              => $variation_image,
				'attributes'         => $variation->get_attributes(),
				'downloadable'       => $variation->get_downloadable(),
				'virtual'            => $variation->get_virtual(),
				'download_limit'     => $variation->get_download_limit(),
				'download_expiry'    => $variation->get_download_expiry(),
				'purchase_note'      => $variation->get_purchase_note(),
			);

			$date_created = $variation->get_date_created();
			$date_modified = $variation->get_date_modified();

			if ( $date_created ) {
				$variation_data['date_created'] = $date_created->date( DATE_ATOM );
			}

			if ( $date_modified ) {
				$variation_data['date_modified'] = $date_modified->date( DATE_ATOM );
			}

			$variations[] = $variation_data;
		}

		return $variations;
	}

	/**
	 * Add an image to the archive and return its filename.
	 *
	 * @param ZipArchive $zip           Archive instance.
	 * @param int        $attachment_id Attachment ID.
	 *
	 * @return string
	 */
	protected function add_image_to_archive( ZipArchive $zip, $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return '';
		}

		if ( isset( $this->image_map[ $attachment_id ] ) ) {
			return $this->image_map[ $attachment_id ];
		}

		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$filename = basename( $file_path );
		$filename = $this->ensure_unique_filename( $filename );

		$zip->addFile( $file_path, 'images/' . $filename );

		$this->image_map[ $attachment_id ] = $filename;

		return $filename;
	}

	/**
	 * Ensure filename uniqueness inside archive.
	 *
	 * @param string $filename Original filename.
	 *
	 * @return string
	 */
	protected function ensure_unique_filename( $filename ) {
		if ( ! in_array( $filename, $this->used_filenames, true ) ) {
			$this->used_filenames[] = $filename;

			return $filename;
		}

		$info      = pathinfo( $filename );
		$name      = isset( $info['filename'] ) ? $info['filename'] : $filename;
		$extension = isset( $info['extension'] ) ? $info['extension'] : '';
		$counter   = 1;

		do {
			$new_filename = $name . '-' . $counter . ( $extension ? '.' . $extension : '' );
			$counter++;
		} while ( in_array( $new_filename, $this->used_filenames, true ) );

		$this->used_filenames[] = $new_filename;

		return $new_filename;
	}

	/**
	 * Convert WC DateTime to ISO8601 string.
	 *
	 * @param WC_DateTime|null $date Date object.
	 *
	 * @return string|null
	 */
	protected function format_date( $date ) {
		if ( ! $date instanceof WC_DateTime ) {
			return null;
		}

		return $date->date( DATE_ATOM );
	}

	/**
	 * Stream export file to browser and exit.
	 *
	 * @param string $file_path Absolute file path.
	 */
	protected function stream_file_and_exit( $file_path ) {
		if ( headers_sent() ) {
			unlink( $file_path );

			return;
		}

		ignore_user_abort( true );
		nocache_headers();

		$filename = 'product-porter-' . gmdate( 'Ymd-His' ) . '.zip';

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Content-Transfer-Encoding: binary' );

		readfile( $file_path );
		unlink( $file_path );

		exit;
	}
}
