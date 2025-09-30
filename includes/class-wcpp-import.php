<?php
/**
 * Import handler.
 */

defined( 'ABSPATH' ) || exit;

class WCPP_Import {
	/**
	 * Number of products processed per batch.
	 */
	const BATCH_SIZE = 5;

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
	}

	/**
	 * Handle the setup step: validate package, unzip, store state.
	 *
	 * @param array $file            Uploaded file array from $_FILES.
	 * @param bool  $update_existing Whether to update existing products.
	 *
	 * @return array|WP_Error
	 */
	public function handle_setup( $file, $update_existing ) {
		if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
			return new WP_Error( 'missing_file', __( 'No file received. Please choose a Product Porter package.', 'wc-product-porter' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'missing_zip', __( 'The PHP ZipArchive extension is required to import packages.', 'wc-product-porter' ) );
		}

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'upload_dir', $upload_dir['error'] );
		}

		$import_id  = $this->generate_import_id();
		$import_dir = trailingslashit( $upload_dir['basedir'] ) . 'wcpp-temp-' . $import_id;

		if ( ! wp_mkdir_p( $import_dir ) ) {
			return new WP_Error( 'mkdir', __( 'Unable to create a temporary directory for the import.', 'wc-product-porter' ) );
		}

		if ( ! $this->ensure_filesystem() ) {
			$this->remove_directory( $import_dir );

			return new WP_Error( 'filesystem', __( 'File system credentials are required to import packages. Please check your WordPress configuration.', 'wc-product-porter' ) );
		}

		$tmp_zip = wp_tempnam( $file['name'] );

		if ( ! $tmp_zip || ! @copy( $file['tmp_name'], $tmp_zip ) ) {
			$this->remove_directory( $import_dir );

			return new WP_Error( 'temp_zip', __( 'Unable to copy the uploaded package to a temporary location.', 'wc-product-porter' ) );
		}

		$unzipped = unzip_file( $tmp_zip, $import_dir );

		@unlink( $tmp_zip );

		if ( is_wp_error( $unzipped ) ) {
			$this->remove_directory( $import_dir );

			return new WP_Error( 'unzip', sprintf( __( 'Failed to unpack the package: %s', 'wc-product-porter' ), $unzipped->get_error_message() ) );
		}

		$products = $this->read_products_file( $import_dir );

		if ( is_wp_error( $products ) ) {
			$this->remove_directory( $import_dir );

			return $products;
		}

		$total_products = count( $products );

		if ( 0 === $total_products ) {
			$this->remove_directory( $import_dir );

			return new WP_Error( 'empty_package', __( 'The package does not contain any products.', 'wc-product-porter' ) );
		}

		$this->update_import_state(
			$import_id,
			array(
				'dir'             => $import_dir,
				'total'           => $total_products,
				'processed'       => 0,
				'update_existing' => (bool) $update_existing,
				'batch_size'      => self::BATCH_SIZE,
				'media_map'       => array(),
				'created'         => time(),
			)
		);

		return array(
			'import_id'      => $import_id,
			'total_products' => $total_products,
			'batch_size'     => self::BATCH_SIZE,
		);
	}

	/**
	 * Process a batch of products.
	 *
	 * @param string $import_id    Import identifier.
	 * @param int    $batch_number Batch number starting from 1.
	 *
	 * @return array|WP_Error
	 */
	public function process_batch( $import_id, $batch_number ) {
		$state = $this->get_import_state( $import_id );

		if ( false === $state ) {
			return new WP_Error( 'invalid_import', __( 'The import session could not be found. Please restart the import.', 'wc-product-porter' ) );
		}

		$products = $this->read_products_file( $state['dir'] );

		if ( is_wp_error( $products ) ) {
			return $products;
		}

		$batch_size    = isset( $state['batch_size'] ) ? (int) $state['batch_size'] : self::BATCH_SIZE;
		$batch_number  = max( 1, (int) $batch_number );
		$offset        = ( $batch_number - 1 ) * $batch_size;
		$batch         = array_slice( $products, $offset, $batch_size );
		$logs          = array();
		$processed_now = 0;

		if ( empty( $batch ) ) {
			return array(
				'logs'            => array(),
				'processed'       => 0,
				'processed_total' => $state['processed'],
				'total'           => $state['total'],
				'completed'       => $state['processed'] >= $state['total'],
			);
		}

		foreach ( $batch as $product_data ) {
			$result = $this->import_single_product( $product_data, $state );
			$logs[] = $result['message'];
			$processed_now++;
		}

		$state['processed'] = max( $state['processed'], min( $offset + $processed_now, $state['total'] ) );

		$this->update_import_state( $import_id, $state );

		return array(
			'logs'            => $logs,
			'processed'       => $processed_now,
			'processed_total' => $state['processed'],
			'total'           => $state['total'],
			'completed'       => $state['processed'] >= $state['total'],
		);
	}

	/**
	 * Remove temporary files and state for an import session.
	 *
	 * @param string $import_id Import identifier.
	 *
	 * @return true|WP_Error
	 */
	public function cleanup( $import_id ) {
		$state = $this->get_import_state( $import_id );

		if ( false !== $state && ! empty( $state['dir'] ) ) {
			$this->remove_directory( $state['dir'] );
		}

		$this->delete_import_state( $import_id );

		return true;
	}

	/**
	 * Import a single product entry.
	 *
	 * @param array $product_data Product payload.
	 * @param array $state        Import state (passed by reference).
	 *
	 * @return array
	 */
	protected function import_single_product( $product_data, array &$state ) {
		$product_data = is_array( $product_data ) ? $product_data : array();

		$sku             = isset( $product_data['sku'] ) ? wc_clean( $product_data['sku'] ) : '';
		$type            = isset( $product_data['type'] ) ? wc_clean( $product_data['type'] ) : 'simple';
		$update_existing = ! empty( $state['update_existing'] );
		$is_new          = false;

		$existing_id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;

		if ( $existing_id ) {
			$product = wc_get_product( $existing_id );

			if ( $product && ! $update_existing ) {
				return array(
					'message' => sprintf( __( 'SKIPPED: %1$s (SKU: %2$s) already exists.', 'wc-product-porter' ), $product->get_name(), $sku ? $sku : __( 'N/A', 'wc-product-porter' ) ),
				);
			}
		} else {
			$product = $this->create_product_instance( $type );
			$is_new  = true;
		}

		if ( ! $product || ! $product instanceof WC_Product ) {
			return array(
				'message' => sprintf( __( 'ERROR: Unable to create product of type %s.', 'wc-product-porter' ), esc_html( $type ) ),
			);
		}

		$this->populate_product_core( $product, $product_data );

		list( $attributes, $attribute_terms ) = $this->prepare_attributes_for_import( $product_data );
		$product->set_attributes( $attributes );

		$prepared_taxonomies = $this->prepare_taxonomy_terms( isset( $product_data['taxonomies'] ) ? $product_data['taxonomies'] : array() );
		$manual_taxonomies   = $this->apply_product_taxonomy_props( $product, $prepared_taxonomies );

		$this->apply_custom_meta( $product, isset( $product_data['meta'] ) ? $product_data['meta'] : array() );

		$assigned_attachments = $this->assign_images( $product, isset( $product_data['images'] ) ? $product_data['images'] : array(), $state );

		$product = apply_filters( 'woocommerce_product_import_pre_insert_product_object', $product, $product_data );

		if ( is_wp_error( $product ) || ! $product instanceof WC_Product ) {
			return array(
				'message' => sprintf( __( 'ERROR: Unable to create product of type %s.', 'wc-product-porter' ), esc_html( $type ) ),
			);
		}

		$product->save();
		$product_id = $product->get_id();

		$term_assignments = $this->merge_taxonomy_assignments( $manual_taxonomies, $attribute_terms );

		if ( ! empty( $term_assignments ) ) {
			$this->assign_manual_taxonomies( $product_id, $term_assignments );
		}

		if ( ! empty( $assigned_attachments ) ) {
			$this->maybe_update_attachment_parents( $product_id, $assigned_attachments );
		}

		if ( 'variable' === $product->get_type() ) {
			$this->sync_variations( $product, isset( $product_data['variations'] ) ? $product_data['variations'] : array(), $state );
		}

		if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables( $product_id );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		$action = $is_new ? __( 'Created', 'wc-product-porter' ) : __( 'Updated', 'wc-product-porter' );

		do_action( 'woocommerce_product_import_inserted_product_object', $product, $product_data );

		return array(
			'message' => sprintf( __( 'SUCCESS: %1$s "%2$s" (SKU: %3$s)', 'wc-product-porter' ), $action, $product->get_name(), $sku ? $sku : __( 'N/A', 'wc-product-porter' ) ),
		);
	}

	/**
	 * Instantiate product object for a given type.
	 *
	 * @param string $type Product type.
	 *
	 * @return WC_Product|false
	 */
	protected function create_product_instance( $type ) {
		$type = $type ? sanitize_title( $type ) : 'simple';

		if ( function_exists( 'wc_get_product_object' ) ) {
			$product = wc_get_product_object( $type );

			if ( $product instanceof WC_Product ) {
				return $product;
			}
		}

		switch ( $type ) {
			case 'variable':
				return new WC_Product_Variable();
			case 'simple':
			default:
				return new WC_Product_Simple();
		}
	}

	/**
	 * Populate core product data from payload.
	 *
	 * @param WC_Product $product      Product instance.
	 * @param array      $product_data Payload.
	 */
	protected function populate_product_core( WC_Product $product, $product_data ) {
		$pricing  = isset( $product_data['pricing'] ) ? (array) $product_data['pricing'] : array();
		$shipping = isset( $product_data['shipping'] ) ? (array) $product_data['shipping'] : array();

		$product->set_name( isset( $product_data['name'] ) ? wc_clean( $product_data['name'] ) : '' );
		$product->set_slug( isset( $product_data['slug'] ) ? sanitize_title( $product_data['slug'] ) : '' );
		$product->set_status( isset( $product_data['status'] ) ? sanitize_key( $product_data['status'] ) : 'draft' );
		$product->set_catalog_visibility( isset( $product_data['catalog_visibility'] ) ? sanitize_key( $product_data['catalog_visibility'] ) : 'visible' );
		$product->set_description( isset( $product_data['description'] ) ? wp_kses_post( $product_data['description'] ) : '' );
		$product->set_short_description( isset( $product_data['short_description'] ) ? wp_kses_post( $product_data['short_description'] ) : '' );
		$product->set_menu_order( isset( $product_data['menu_order'] ) ? (int) $product_data['menu_order'] : 0 );
		$product->set_featured( ! empty( $product_data['featured'] ) );
		$product->set_reviews_allowed( ! empty( $product_data['reviews_allowed'] ) );
		$product->set_virtual( ! empty( $product_data['virtual'] ) );
		$product->set_downloadable( ! empty( $product_data['downloadable'] ) );
		$product->set_purchase_note( isset( $pricing['purchase_note'] ) ? wp_kses_post( $pricing['purchase_note'] ) : '' );

		if ( isset( $product_data['sku'] ) ) {
			$product->set_sku( wc_clean( $product_data['sku'] ) );
		}

		$product->set_regular_price( isset( $pricing['regular_price'] ) ? wc_clean( $pricing['regular_price'] ) : '' );
		$product->set_sale_price( isset( $pricing['sale_price'] ) ? wc_clean( $pricing['sale_price'] ) : '' );

		$product->set_date_on_sale_from( $this->parse_datetime( isset( $pricing['sale_price_dates_from'] ) ? $pricing['sale_price_dates_from'] : null ) );
		$product->set_date_on_sale_to( $this->parse_datetime( isset( $pricing['sale_price_dates_to'] ) ? $pricing['sale_price_dates_to'] : null ) );

		$product->set_manage_stock( ! empty( $pricing['manage_stock'] ) );
		$product->set_stock_quantity( isset( $pricing['stock_quantity'] ) ? wc_stock_amount( $pricing['stock_quantity'] ) : null );
		$product->set_stock_status( isset( $pricing['stock_status'] ) ? sanitize_key( $pricing['stock_status'] ) : 'instock' );
		$product->set_backorders( isset( $pricing['backorders'] ) ? sanitize_key( $pricing['backorders'] ) : 'no' );
		$product->set_sold_individually( ! empty( $pricing['sold_individually'] ) );

		$product->set_weight( isset( $shipping['weight'] ) ? wc_format_decimal( $shipping['weight'] ) : '' );
		$product->set_length( isset( $shipping['length'] ) ? wc_format_decimal( $shipping['length'] ) : '' );
		$product->set_width( isset( $shipping['width'] ) ? wc_format_decimal( $shipping['width'] ) : '' );
		$product->set_height( isset( $shipping['height'] ) ? wc_format_decimal( $shipping['height'] ) : '' );

		if ( ! empty( $shipping['shipping_class'] ) && taxonomy_exists( 'product_shipping_class' ) ) {
			$term_id = $this->ensure_term( 'product_shipping_class', sanitize_title( $shipping['shipping_class'] ) );

			if ( $term_id ) {
				$product->set_shipping_class_id( $term_id );
			}
		}

		if ( isset( $product_data['download_limit'] ) ) {
			$product->set_download_limit( (int) $product_data['download_limit'] );
		}

		if ( isset( $product_data['download_expiry'] ) ) {
			$product->set_download_expiry( (int) $product_data['download_expiry'] );
		}
	}

	/**
	 * Prepare attributes for import.
	 *
	 * @param array $product_data Product payload.
	 *
	 * @return array Array containing attribute objects and taxonomy term assignments.
	 */
	protected function prepare_attributes_for_import( $product_data ) {
		$attributes_data = isset( $product_data['attributes'] ) ? (array) $product_data['attributes'] : array();
		$attributes      = array();
		$taxonomy_terms  = array();

		foreach ( $attributes_data as $attribute_data ) {
			$attribute_data = (array) $attribute_data;
			$attribute      = new WC_Product_Attribute();

			$is_taxonomy = ! empty( $attribute_data['is_taxonomy'] );
			$name        = isset( $attribute_data['name'] ) ? $attribute_data['name'] : '';
			$options     = isset( $attribute_data['options'] ) ? (array) $attribute_data['options'] : array();

			$attribute->set_position( isset( $attribute_data['position'] ) ? (int) $attribute_data['position'] : 0 );
			$attribute->set_visible( ! empty( $attribute_data['visible'] ) );
			$attribute->set_variation( ! empty( $attribute_data['variation'] ) );

			if ( $is_taxonomy && taxonomy_exists( $name ) ) {
				$attribute_id = wc_attribute_taxonomy_id_by_name( $name );
				$attribute->set_id( $attribute_id );
				$attribute->set_name( $name );

				$term_ids = array();

				foreach ( $options as $slug ) {
					$term_id = $this->ensure_term( $name, sanitize_title( $slug ) );

					if ( $term_id ) {
						$term_ids[] = (int) $term_id;
					}
				}

				$attribute->set_options( $term_ids );

				if ( $term_ids ) {
					$taxonomy_terms[ $name ] = $term_ids;
				}
			} else {
				$attribute->set_name( isset( $attribute_data['slug'] ) ? $attribute_data['slug'] : $name );
				$attribute->set_options( array_map( 'wc_clean', $options ) );
			}

			$attributes[] = $attribute;
		}

		return array( $attributes, $taxonomy_terms );
	}

	/**
	 * Assign taxonomy terms to a product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $taxonomies Taxonomy => slugs map.
	 */
	protected function prepare_taxonomy_terms( $taxonomies ) {
		$taxonomies = is_array( $taxonomies ) ? $taxonomies : array();
		$result     = array();

		foreach ( $taxonomies as $taxonomy => $slugs ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$slugs    = is_array( $slugs ) ? $slugs : array();
			$term_ids = array();

			foreach ( $slugs as $slug ) {
				$term_id = $this->ensure_term( $taxonomy, sanitize_title( $slug ) );

				if ( $term_id ) {
					$term_ids[] = (int) $term_id;
				}
			}

			if ( $term_ids ) {
				$result[ $taxonomy ] = array_values( array_unique( $term_ids ) );
			}
		}

		return $result;
	}

	/**
	 * Apply taxonomy relationships using product setters when available.
	 *
	 * @param WC_Product $product         Product instance.
	 * @param array      $taxonomy_terms  Taxonomy => term IDs map.
	 *
	 * @return array Taxonomies that need to be applied manually via wp_set_object_terms().
	 */
	protected function apply_product_taxonomy_props( WC_Product $product, array $taxonomy_terms ) {
		$manual = array();

		foreach ( $taxonomy_terms as $taxonomy => $term_ids ) {
			switch ( $taxonomy ) {
				case 'product_cat':
					$product->set_category_ids( $term_ids );
					break;
				case 'product_tag':
					$product->set_tag_ids( $term_ids );
					break;
				default:
					$manual[ $taxonomy ] = $term_ids;
					break;
			}
		}

		return $manual;
	}

	/**
	 * Merge taxonomy assignments ensuring term lists remain unique.
	 *
	 * @param array $manual_taxonomies  Taxonomy => term IDs requiring manual assignment.
	 * @param array $attribute_terms    Attribute taxonomy => term IDs.
	 *
	 * @return array
	 */
	protected function merge_taxonomy_assignments( array $manual_taxonomies, array $attribute_terms ) {
		$merged = $manual_taxonomies;

		foreach ( $attribute_terms as $taxonomy => $term_ids ) {
			if ( empty( $term_ids ) ) {
				continue;
			}

			if ( isset( $merged[ $taxonomy ] ) ) {
				$merged[ $taxonomy ] = array_values( array_unique( array_merge( $merged[ $taxonomy ], $term_ids ) ) );
			} else {
				$merged[ $taxonomy ] = $term_ids;
			}
		}

		return $merged;
	}

	/**
	 * Apply taxonomy relationships using wp_set_object_terms().
	 *
	 * @param int   $product_id Product ID.
	 * @param array $taxonomies Taxonomy => term IDs map.
	 */
	protected function assign_manual_taxonomies( $product_id, array $taxonomies ) {
		$product_id = (int) $product_id;

		if ( ! $product_id || empty( $taxonomies ) ) {
			return;
		}

		foreach ( $taxonomies as $taxonomy => $term_ids ) {
			if ( empty( $term_ids ) || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			wp_set_object_terms( $product_id, array_values( array_unique( array_map( 'intval', $term_ids ) ) ), $taxonomy );
		}
	}

	/**
	 * Ensure imported attachments are linked to the product post.
	 *
	 * @param int   $product_id      Product ID.
	 * @param array $attachment_ids  Attachment IDs to link.
	 */
	protected function maybe_update_attachment_parents( $product_id, array $attachment_ids ) {
		$product_id     = (int) $product_id;
		$attachment_ids = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );

		if ( ! $product_id || empty( $attachment_ids ) ) {
			return;
		}

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! $attachment_id ) {
				continue;
			}

			$attachment = get_post( $attachment_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			if ( (int) $attachment->post_parent === $product_id ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $product_id,
				)
			);
		}
	}

	/**
	 * Assign images to a product, importing from the temporary directory as needed.
	 *
	 * @param WC_Product $product Product instance.
	 * @param array      $images  Image data.
	 * @param array      $state   Import state (passed by reference).
	 *
	 * @return array Attachment IDs that were imported/attached.
	 */
	protected function assign_images( WC_Product $product, $images, array &$state ) {
		$images           = is_array( $images ) ? $images : array();
		$featured         = isset( $images['featured'] ) ? $images['featured'] : '';
		$gallery          = isset( $images['gallery'] ) ? (array) $images['gallery'] : array();
		$assigned_media   = array();
		$resolved_gallery = array();

		if ( $featured ) {
			$attachment_id = $this->import_image_from_temp( $featured, $product->get_id(), $state );

			if ( $attachment_id ) {
				$product->set_image_id( $attachment_id );
				$assigned_media[] = (int) $attachment_id;
			}
		}

		foreach ( $gallery as $filename ) {
			$attachment_id = $this->import_image_from_temp( $filename, $product->get_id(), $state );

			if ( $attachment_id ) {
				$resolved_gallery[] = (int) $attachment_id;
				$assigned_media[]   = (int) $attachment_id;
			}
		}

		if ( $resolved_gallery ) {
			$product->set_gallery_image_ids( array_values( array_unique( $resolved_gallery ) ) );
		} else {
			$product->set_gallery_image_ids( array() );
		}

		return array_values( array_unique( $assigned_media ) );
	}

	/**
	 * Apply custom meta values.
	 *
	 * @param WC_Product $product Product instance.
	 * @param array      $meta    Meta data.
	 */
	protected function apply_custom_meta( WC_Product $product, $meta ) {
		$meta = is_array( $meta ) ? $meta : array();

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
	}

	/**
	 * Synchronise product variations.
	 *
	 * @param WC_Product $product         Variable product.
	 * @param array      $variations_data Variation payloads.
	 * @param array      $state           Import state (passed by reference).
	 */
	protected function sync_variations( WC_Product $product, $variations_data, array &$state ) {
		if ( ! $product instanceof WC_Product_Variable ) {
			return;
		}

		$variations_data = is_array( $variations_data ) ? $variations_data : array();
		$update_existing = ! empty( $state['update_existing'] );

		foreach ( $variations_data as $variation_data ) {
			$variation_data = (array) $variation_data;
			$variation      = $this->find_existing_variation( $product, $variation_data );

			if ( $variation && ! $update_existing ) {
				continue;
			}

			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
			}

			$this->populate_variation( $variation, $variation_data );
			$variation->save();

			$variation_images = isset( $variation_data['image'] ) ? $variation_data['image'] : '';

			if ( $variation_images ) {
				$attachment_id = $this->import_image_from_temp( $variation_images, $variation->get_id(), $state );

				if ( $attachment_id ) {
					$variation->set_image_id( $attachment_id );
					$variation->save();
				}
			}
		}

		WC_Product_Variable::sync( $product->get_id() );
	}

	/**
	 * Populate variation data from payload.
	 *
	 * @param WC_Product_Variation $variation     Variation instance.
	 * @param array                $variation_data Payload.
	 */
	protected function populate_variation( WC_Product_Variation $variation, $variation_data ) {
		$price = isset( $variation_data['price'] ) ? (array) $variation_data['price'] : array();

		if ( isset( $variation_data['sku'] ) ) {
			$variation->set_sku( wc_clean( $variation_data['sku'] ) );
		}

		$variation->set_status( isset( $variation_data['status'] ) ? sanitize_key( $variation_data['status'] ) : 'publish' );
		$variation->set_regular_price( isset( $price['regular_price'] ) ? wc_clean( $price['regular_price'] ) : '' );
		$variation->set_sale_price( isset( $price['sale_price'] ) ? wc_clean( $price['sale_price'] ) : '' );
		$variation->set_manage_stock( ! empty( $variation_data['manage_stock'] ) );
		$variation->set_stock_quantity( isset( $variation_data['stock_quantity'] ) ? wc_stock_amount( $variation_data['stock_quantity'] ) : null );
		$variation->set_stock_status( isset( $variation_data['stock_status'] ) ? sanitize_key( $variation_data['stock_status'] ) : 'instock' );
		$variation->set_backorders( isset( $variation_data['backorders'] ) ? sanitize_key( $variation_data['backorders'] ) : 'no' );
		$variation->set_weight( isset( $variation_data['weight'] ) ? wc_format_decimal( $variation_data['weight'] ) : '' );

		$dimensions = isset( $variation_data['dimensions'] ) ? (array) $variation_data['dimensions'] : array();
		$variation->set_length( isset( $dimensions['length'] ) ? wc_format_decimal( $dimensions['length'] ) : '' );
		$variation->set_width( isset( $dimensions['width'] ) ? wc_format_decimal( $dimensions['width'] ) : '' );
		$variation->set_height( isset( $dimensions['height'] ) ? wc_format_decimal( $dimensions['height'] ) : '' );

		$variation->set_downloadable( ! empty( $variation_data['downloadable'] ) );
		$variation->set_virtual( ! empty( $variation_data['virtual'] ) );
		$variation->set_download_limit( isset( $variation_data['download_limit'] ) ? (int) $variation_data['download_limit'] : -1 );
		$variation->set_download_expiry( isset( $variation_data['download_expiry'] ) ? (int) $variation_data['download_expiry'] : -1 );
		$variation->set_purchase_note( isset( $variation_data['purchase_note'] ) ? wp_kses_post( $variation_data['purchase_note'] ) : '' );
		$variation->set_attributes( isset( $variation_data['attributes'] ) ? (array) $variation_data['attributes'] : array() );
	}

	/**
	 * Find an existing variation matching the payload.
	 *
	 * @param WC_Product_Variable $product        Parent product.
	 * @param array               $variation_data Variation data.
	 *
	 * @return WC_Product_Variation|null
	 */
	protected function find_existing_variation( WC_Product_Variable $product, $variation_data ) {
		$variation_data = is_array( $variation_data ) ? $variation_data : array();
		$sku            = isset( $variation_data['sku'] ) ? wc_clean( $variation_data['sku'] ) : '';

		if ( $sku ) {
			$variation_id = wc_get_product_id_by_sku( $sku );

			if ( $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( $variation instanceof WC_Product_Variation && (int) $variation->get_parent_id() === $product->get_id() ) {
					return $variation;
				}
			}
		}

		$target_attributes = isset( $variation_data['attributes'] ) ? (array) $variation_data['attributes'] : array();
		$target_attributes = $this->normalise_attributes( $target_attributes );

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child instanceof WC_Product_Variation ) {
				continue;
			}

			if ( $this->normalise_attributes( $child->get_attributes() ) === $target_attributes ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * Normalise variation attribute array for comparison.
	 *
	 * @param array $attributes Attributes.
	 *
	 * @return array
	 */
	protected function normalise_attributes( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		$normalised = array();

		foreach ( $attributes as $key => $value ) {
			$normalised[ sanitize_title( $key ) ] = is_array( $value ) ? array_map( 'wc_clean', $value ) : wc_clean( $value );
		}

		ksort( $normalised );

		return $normalised;
	}

	/**
	 * Import image from temporary directory into Media Library.
	 *
	 * @param string $filename  Image filename.
	 * @param int    $post_id   Parent post ID.
	 * @param array  $state     Import state (passed by reference).
	 *
	 * @return int Attachment ID.
	 */
	protected function import_image_from_temp( $filename, $post_id, array &$state ) {
		$filename = sanitize_file_name( $filename );

		if ( ! $filename ) {
			return 0;
		}

		if ( isset( $state['media_map'][ $filename ] ) ) {
			$attachment_id = (int) $state['media_map'][ $filename ];

			if ( $attachment_id && get_post( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		$source = trailingslashit( $state['dir'] ) . 'images/' . $filename;

		if ( ! file_exists( $source ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_name = wp_tempnam( $filename );

		if ( ! $tmp_name || ! @copy( $source, $tmp_name ) ) {
			return 0;
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_name,
		);

		$filetype = wp_check_filetype( $filename );

		if ( ! empty( $filetype['type'] ) ) {
			$file_array['type'] = $filetype['type'];
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_name );

			return 0;
		}

		if ( file_exists( $tmp_name ) ) {
			@unlink( $tmp_name );
		}

		$state['media_map'][ $filename ] = $attachment_id;

		return (int) $attachment_id;
	}

	/**
	 * Read products.json from the temporary directory.
	 *
	 * @param string $import_dir Directory path.
	 *
	 * @return array|WP_Error
	 */
	protected function read_products_file( $import_dir ) {
		$products_file = trailingslashit( $import_dir ) . 'products.json';

		if ( ! file_exists( $products_file ) ) {
			return new WP_Error( 'missing_manifest', __( 'The package is missing products.json.', 'wc-product-porter' ) );
		}

		$contents = file_get_contents( $products_file );

		if ( false === $contents ) {
			return new WP_Error( 'manifest_read', __( 'Unable to read products.json from the package.', 'wc-product-porter' ) );
		}

		$data = json_decode( $contents, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error( 'manifest_json', __( 'The products.json file is invalid JSON.', 'wc-product-porter' ) );
		}

		return $data;
	}

	/**
	 * Generate unique import identifier.
	 *
	 * @return string
	 */
	protected function generate_import_id() {
		return substr( md5( uniqid( 'wcpp', true ) ), 0, 12 );
	}

	/**
	 * Retrieve stored import state.
	 *
	 * @param string $import_id Import identifier.
	 *
	 * @return array|false
	 */
	protected function get_import_state( $import_id ) {
		$key = $this->get_state_key( $import_id );

		$state = get_transient( $key );

		return false !== $state ? $state : false;
	}

	/**
	 * Persist import state.
	 *
	 * @param string $import_id Import identifier.
	 * @param array  $state     State payload.
	 */
	protected function update_import_state( $import_id, array $state ) {
		set_transient( $this->get_state_key( $import_id ), $state, DAY_IN_SECONDS );
	}

	/**
	 * Delete stored import state.
	 *
	 * @param string $import_id Import identifier.
	 */
	protected function delete_import_state( $import_id ) {
		delete_transient( $this->get_state_key( $import_id ) );
	}

	/**
	 * Build transient key for import state.
	 *
	 * @param string $import_id Import identifier.
	 *
	 * @return string
	 */
	protected function get_state_key( $import_id ) {
		return 'wcpp_import_' . sanitize_key( $import_id );
	}

	/**
	 * Ensure WordPress filesystem abstraction is available.
	 *
	 * @return bool
	 */
	protected function ensure_filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;

		if ( $wp_filesystem ) {
			return true;
		}

		return WP_Filesystem();
	}

	/**
	 * Remove a directory recursively.
	 *
	 * @param string $path Directory path.
	 */
	protected function remove_directory( $path ) {
		if ( empty( $path ) || ! is_dir( $path ) ) {
			return;
		}

		if ( $this->ensure_filesystem() ) {
			global $wp_filesystem;

			if ( $wp_filesystem ) {
				$wp_filesystem->delete( $path, true );

				return;
			}
		}

		$iterator = new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS );
		$files    = new RecursiveIteratorIterator( $iterator, RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $files as $file ) {
			/** @var SplFileInfo $file */
			if ( $file->isDir() ) {
				@rmdir( $file->getRealPath() );
			} else {
				@unlink( $file->getRealPath() );
			}
		}

		@rmdir( $path );
	}

	/**
	 * Ensure a taxonomy term exists, creating it when necessary.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Term slug.
	 *
	 * @return int Term ID.
	 */
	protected function ensure_term( $taxonomy, $slug ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );

		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}

		$term_name = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		$result   = wp_insert_term( $term_name, $taxonomy, array( 'slug' => $slug ) );

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		return (int) $result['term_id'];
	}

	/**
	 * Convert arbitrary date string to WC_DateTime.
	 *
	 * @param string|null $value Date string.
	 *
	 * @return WC_DateTime|null
	 */
	protected function parse_datetime( $value ) {
		if ( empty( $value ) ) {
			return null;
		}

		try {
			return new WC_DateTime( $value );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
