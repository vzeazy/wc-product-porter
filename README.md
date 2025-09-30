# Product Porter for WooCommerce

![Product Porter Logo](assets/imgs/product-porter.png)

A powerful WordPress plugin that enables seamless export and import of WooCommerce products using portable ZIP packages. Perfect for migrating products between sites, creating backups, or sharing product catalogs.

[![Version](https://img.shields.io/badge/version-0.1.0-blue.svg)](https://github.com/vzeazy/wc-product-porter)
[![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-5.5%2B-blue.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-purple.svg)](https://woocommerce.com)

## 🚀 Features

- **Bulk Export**: Export multiple products at once using WordPress bulk actions
- **Portable ZIP Packages**: Self-contained packages include product data, images, and metadata
- **Batch Import Processing**: Large imports are processed in manageable batches to prevent timeouts
- **Product Updates**: Option to update existing products based on SKU matching
- **Custom Fields Support**: Configure additional meta keys and taxonomies to include in exports
- **Real-time Progress**: Live progress tracking with detailed logging during imports
- **Image Handling**: Automatic download and attachment of product images
- **Data Integrity**: Comprehensive validation and error handling
- **AJAX Interface**: Smooth, non-blocking user experience

## 📋 Requirements

- **WordPress**: 5.5 or higher
- **WooCommerce**: Active installation (tested up to 8.0)
- **PHP**: 7.4 or higher
- **PHP ZipArchive Extension**: Required for creating and extracting ZIP files
- **File Upload Permissions**: WordPress must be able to create temporary directories

## 📦 Installation

### Method 1: WordPress Admin (Recommended)

1. Download the latest release ZIP file
2. In your WordPress admin, go to **Plugins → Add New**
3. Click **Upload Plugin** and select the ZIP file
4. Click **Install Now** and then **Activate**

### Method 2: Manual Installation

1. Download and extract the plugin files
2. Upload the `wc-product-porter` folder to `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** menu in WordPress

### Method 3: Git Clone (Development)

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/vzeazy/wc-product-porter.git
```

## 🔧 Configuration

1. Navigate to **Products → Porter Settings** in your WordPress admin
2. Configure additional product data to include in exports:
   - **Custom Meta Keys**: Specify custom product meta fields (one per line)
   - **Custom Taxonomies**: Add custom taxonomy slugs to export (one per line)

### Example Configuration

**Custom Meta Keys:**
```
_custom_field_name
_product_gtin
_manufacturer_code
```

**Custom Taxonomies:**
```
pwb-brand
product-condition
custom-category
```

## 📤 Exporting Products

### Bulk Export from Products List

1. Go to **Products → All Products**
2. Select the products you want to export using checkboxes
3. Choose **Export with Porter** from the **Bulk Actions** dropdown
4. Click **Apply**
5. The export ZIP file will automatically download

### What's Included in Exports

- Complete product data (title, description, price, etc.)
- Product images and galleries
- Categories and tags
- Attributes and variations
- Custom meta fields (as configured)
- Custom taxonomies (as configured)
- Stock status and inventory data

## 📥 Importing Products

### Using the Import Interface

1. Navigate to **Products → Product Porter**
2. Click **Choose File** and select your Porter ZIP package
3. Optionally check **Update existing products when SKU matches**
4. Click **Start Import**
5. Monitor the real-time progress and logs

### Import Process

- **Validation**: Package is validated for integrity and compatibility
- **Extraction**: ZIP contents are extracted to a temporary directory
- **Batch Processing**: Products are processed in batches of 5 to prevent timeouts
- **Progress Tracking**: Real-time progress bar and detailed logging
- **Cleanup**: Temporary files are automatically removed after completion

### Update Behavior

When **Update existing products** is enabled:
- Products with matching SKUs will be updated
- Existing product IDs are preserved
- Images are re-imported and attached
- Custom fields are updated based on configuration

## 🛠️ Technical Details

### Plugin Architecture

The plugin follows a modular architecture with clear separation of concerns:

```
wc-product-porter/
├── wc-product-porter.php          # Main plugin file
├── includes/
│   ├── class-wcpp-main.php        # Core plugin bootstrap
│   ├── class-wcpp-admin.php       # Admin interface handler
│   ├── class-wcpp-export.php      # Export functionality
│   ├── class-wcpp-import.php      # Import functionality
│   ├── class-wcpp-ajax.php        # AJAX request handlers
│   └── class-wcpp-settings.php    # Settings management
├── templates/
│   ├── admin-import-page.php      # Import interface template
│   └── admin-settings-page.php    # Settings page template
└── assets/
    ├── css/
    │   └── wcpp-admin-style.css    # Admin styling
    └── js/
        └── wcpp-importer.js        # Import interface JavaScript
```

### Key Classes

- **`WCPP_Main`**: Singleton main class that initializes all components
- **`WCPP_Export`**: Handles product export and ZIP package creation
- **`WCPP_Import`**: Manages batch import processing and product creation
- **`WCPP_Admin`**: Registers admin menus and enqueues assets
- **`WCPP_Ajax`**: Processes AJAX requests for import operations
- **`WCPP_Settings`**: Manages plugin configuration and custom field settings

### WooCommerce Compatibility Notes

- Product imports now use `wc_get_product_object()` so custom product class filters are honoured for each SKU that is created.
- Import runs through the standard WooCommerce CRUD workflow, firing the `woocommerce_product_import_pre_insert_product_object` filter and `woocommerce_product_import_inserted_product_object` action once per product.
- Meta data is persisted via `$product->update_meta_data()` before the final save, keeping lookup tables and caches in sync.
- Imported attachments are linked back to the product and lookup tables/transients are refreshed after each import pass to avoid stale catalogue data.

### Data Format

Products are exported in JSON format with the following structure:
- Product metadata and attributes
- Image URLs and attachment data
- Category and tag assignments
- Custom field values
- Variation data (for variable products)

### Security Features

- Nonce verification for all AJAX requests
- Capability checks (`manage_woocommerce`, `export`)
- Input sanitization and validation
- Secure file handling with temporary directories
- Automatic cleanup of sensitive data

## 🔍 Troubleshooting

### Common Issues

**"PHP ZipArchive extension is required"**
- Contact your hosting provider to enable the ZipArchive extension
- On some servers, you may need to install php-zip package

**Import fails with timeout errors**
- Increase PHP `max_execution_time` in your server configuration
- Reduce batch size by modifying `BATCH_SIZE` constant in `class-wcpp-import.php`

**File upload size too large**
- Increase `upload_max_filesize` and `post_max_size` in PHP configuration
- Check WordPress `WP_MEMORY_LIMIT` setting

**Images not importing correctly**
- Verify WordPress has write permissions to the uploads directory
- Check that `allow_url_fopen` is enabled in PHP configuration

### Debug Mode

To enable detailed logging, add this to your `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Logs will be written to `/wp-content/debug.log`.

## 🤝 Contributing

We welcome contributions! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Setup

```bash
# Clone the repository
git clone https://github.com/vzeazy/wc-product-porter.git
cd wc-product-porter

# Ensure you have a local WordPress installation with WooCommerce
# Place the plugin in wp-content/plugins/
```

### Coding Standards

- Follow WordPress Coding Standards
- Use proper escaping for all output
- Include comprehensive inline documentation
- Write meaningful commit messages

## 📝 Changelog

### Version 0.1.0
- Initial release
- Bulk export functionality
- Batch import processing
- Custom fields and taxonomies support
- Real-time progress tracking
- Product update capabilities

## 📄 License

This project is licensed under the GPL-3.0 License - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

- **Issues**: [GitHub Issues](https://github.com/vzeazy/wc-product-porter/issues)
- **Documentation**: This README and inline code documentation
- **Community**: WordPress.org support forums

## 🙏 Acknowledgments

- WordPress and WooCommerce teams for excellent documentation
- Contributors and beta testers
- The WordPress community for inspiration and feedback

---

**Made with ❤️ for the WordPress community**</content>
<parameter name="filePath">/home/vas/sites/wrd/wp-content/plugins/wc-product-porter/README.md
