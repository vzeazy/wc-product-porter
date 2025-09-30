# Product Porter for WooCommerce

A WordPress plugin that enables seamless export and import of WooCommerce products using portable ZIP packages. Perfect for migrating products between WooCommerce stores or backing up product data.

## Features

- **Bulk Export**: Export multiple products at once via WooCommerce admin bulk actions
- **Portable Packages**: Products are exported as self-contained ZIP files including images and metadata
- **Flexible Import**: Import products with progress tracking and batch processing
- **Custom Data Support**: Configure which custom meta keys and taxonomies to include
- **Update Existing Products**: Option to update products when SKU matches during import
- **AJAX-Powered**: Smooth import experience with real-time progress updates

## Requirements

- WordPress 5.5 or higher
- PHP 7.4 or higher
- WooCommerce (tested up to 8.0)
- PHP ZipArchive extension

## Installation

1. Download the plugin ZIP file
2. Go to WordPress Admin > Plugins > Add New
3. Click "Upload Plugin" and select the ZIP file
4. Click "Install Now" and then "Activate"

Alternatively, you can install via FTP:
1. Upload the `wc-product-porter` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin

## Usage

### Exporting Products

1. Go to **WooCommerce > Products** in your WordPress admin
2. Select the products you want to export
3. Choose **"Export with Porter"** from the bulk actions dropdown
4. Click **"Apply"**
5. Download the generated ZIP package

### Importing Products

1. Go to **Products > Product Porter** in your WordPress admin
2. Click **"Choose File"** and select your export package
3. Optionally check **"Update existing products when SKU matches"**
4. Click **"Start Import"**
5. Monitor the progress and review the import log

### Configuring Settings

1. Go to **Products > Porter Settings**
2. **Custom Meta Keys**: Enter meta keys (one per line) to include in exports
3. **Custom Taxonomies**: Enter taxonomy slugs (one per line) to include in exports
4. Save your changes

## Package Contents

When you export products, the ZIP package includes:

- Product data (title, description, prices, etc.)
- Product images and gallery images
- Product variations and attributes
- Custom meta data (as configured)
- Custom taxonomies (as configured)
- Product categories and tags

## Development

### Project Structure

```
wc-product-porter/
├── wc-product-porter.php          # Main plugin file
├── includes/                      # Core classes
│   ├── class-wcpp-main.php        # Main bootstrap class
│   ├── class-wcpp-admin.php       # Admin interface
│   ├── class-wcpp-export.php      # Export functionality
│   ├── class-wcpp-import.php      # Import functionality
│   ├── class-wcpp-settings.php    # Settings management
│   └── class-wcpp-ajax.php        # AJAX handlers
├── templates/                     # Admin page templates
│   ├── admin-import-page.php
│   └── admin-settings-page.php
└── assets/                        # CSS and JavaScript
    ├── css/
    │   └── wcpp-admin-style.css
    └── js/
        └── wcpp-importer.js
```

### Hooks and Filters

The plugin provides several hooks for customization:

- `wcpp_export_product_data` - Filter product data before export
- `wcpp_import_product_data` - Filter product data before import
- `wcpp_export_includes` - Modify what gets included in exports

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## License

This plugin is licensed under the GPL v2 or later.

## Support

For support, please create an issue on GitHub or contact the development team.

## Changelog

### 0.1.0
- Initial release
- Basic export/import functionality
- Custom meta and taxonomy support
- AJAX import with progress tracking</content>
<parameter name="filePath">/home/vas/sites/wrd/wp-content/plugins/wc-product-porter/README.md