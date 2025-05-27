# PowerBody-Shopify Synchronization Service

This microservice provides bidirectional synchronization between PowerBody Dropshipping API and Shopify Admin API, with additional product enhancements and customizations.

## Features

### Core Synchronization
- **Product Sync**: Import products from PowerBody API to Shopify with customizations
- **Order Sync**: Send Shopify orders to PowerBody and sync order status back to Shopify
- **Comment Sync**: Synchronize order comments between platforms
- **Return Sync**: Handle order returns and refunds
- **Automatic Inventory Updates**: Keep inventory levels synchronized in real-time
- **Fulfillment Tracking**: Update Shopify fulfillments with tracking information

### Product Enhancements
- **Price Markup**: Automatically applies a 22% markup to all product prices
- **EAN Cleanup**: Removes "(EAN XXXXX)" patterns from product titles
- **Metafield Integration**: Adds rich product data through metafields:
  - `manufacturer`: Source manufacturer of the product
  - `portion_count`: Number of servings in the product
  - `price_per_serving`: Price per serving with 22% markup applied
  - `supplier`: Always set to "Powerbody" for tracking purposes
- **Automatic Collections**: Creates and adds products to collections based on categories

### Performance Features
- **Product Caching**: Cache PowerBody product data to reduce API calls and improve performance
- **Batch Processing**: Process products in batches to respect API rate limits
- **Robust Error Handling**: Retry mechanisms and detailed logging for API interactions
- **Detailed Statistics**: Comprehensive statistics tracking and reporting system

## Requirements

- PHP 7.4 or higher
- Composer
- SQLite3 extension
- SOAP extension
- JSON extension

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/your-username/shopify-powerbody-sync.git
   cd shopify-powerbody-sync
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Create environment file:
   ```bash
   cp .env.sample .env
   ```

4. Edit `.env` file and add your credentials:
   ```
   # PowerBody SOAP API
   POWERBODY_API_WSDL="http://www.powerbody.co.uk/api/soap/?wsdl"
   POWERBODY_USER="your-username"
   POWERBODY_PASS="your-password"

   # Shopify Admin REST API
   SHOPIFY_STORE="your-store.myshopify.com"
   SHOPIFY_API_KEY="your-api-key"
   SHOPIFY_API_SECRET="your-api-secret"
   SHOPIFY_ACCESS_TOKEN="your-access-token"
   SHOPIFY_LOCATION_ID="your-location-id"

   # Sync cadence
   PRODUCT_SYNC_CRON="0 2 * * *"   # daily at 02:00, products
   ORDER_SYNC_CRON="0 * * * *"     # hourly, orders + returns + comments
   LOG_LEVEL="INFO"                # DEBUG / INFO / WARNING / ERROR
   ```

5. Make the shell scripts executable (Linux/Mac only):
   ```bash
   chmod +x *.sh
   ```

## Usage

### Run Sync Scripts

#### Using Shell Scripts (Linux/Mac)

```bash
# Run full product sync
./sync-products.sh

# Run orders, comments, and returns sync
./sync-orders-comments-refunds.sh

# Generate comprehensive statistics report
./sync-report.sh
```

Shell script options:
```bash
# Generate custom report with options
./sync-report.sh -d 30 -t order -o report.txt
```

#### Using PHP Scripts (All platforms)

```bash
# Run all sync operations
php bin/sync.php

# Run with specific options
php bin/sync.php all      # Runs all sync jobs
php bin/sync.php products # Only sync products
php bin/sync.php orders   # Only sync orders
php bin/sync.php comments # Only sync comments
php bin/sync.php returns  # Only sync returns
```

### Individual Sync Tasks

```bash
# Product sync only
php bin/product-sync.php

# Order sync only
php bin/order-sync.php

# Comment sync only
php bin/comment-sync.php

# Return/Refund sync only
php bin/return-sync.php
```

### Statistics and Reporting

The system includes a comprehensive statistics tracking and reporting system that records detailed information about each sync operation.

```bash
# View general statistics for the last 7 days
php bin/sync-stats.php

# View statistics for a specific sync type
php bin/sync-stats.php -t product  # product, order, comment, refund, or all
php bin/sync-stats.php -t order -d 14  # Last 14 days of order sync stats

# View detailed logs for a specific sync run
php bin/sync-stats.php -r 123  # Where 123 is the run ID

# Generate a comprehensive report with custom date range
./sync-report.sh -d 30 -t all

# Save report to a file
./sync-report.sh -d 30 -t order -o order_report.txt
```

The statistics system tracks:
- Number of sync runs per type
- Success and failure rates
- Items processed, succeeded, and failed
- Detailed logs for each item processed
- Daily summaries for quick analysis

### Export Tools

```bash
# Export all Shopify products to JSON
php bin/export-products.php

# With options
php bin/export-products.php --output=custom_filename.json --pretty

# Get help with options
php bin/export-products.php --help
```

### Product Management Tools

```bash
# CAUTION: Delete all Shopify products (with safety confirmations)
php bin/delete-products.php

# Delete products from a specific vendor only
php bin/delete-products.php --vendor="Powerbody"

# Delete without confirmation (DANGEROUS)
php bin/delete-products.php --force

# For CI/CD automation (requires exact confirmation text)
php bin/delete-products.php --yes="DELETE ALL PRODUCTS"

# Show help with all options
php bin/delete-products.php --help
```

### Advanced Options

#### Debug Mode
Enable debug mode to get more verbose logging and save detailed product data for troubleshooting:

```bash
# Enable debug mode using the sync-products.php script
php bin/sync-products.php --debug

# You can also use the help option to see all available commands
php bin/sync-products.php --help

# Debug mode can also be enabled in your own scripts:
$productSync = new ProductSync(true); // true enables debug mode
$productSync->sync();
```

Debug mode outputs additional information to logs and saves detailed product data in the `storage/debug/` directory, allowing you to inspect the raw data received from PowerBody's API.

#### Skip Draft Products
By default, products with zero inventory are created as draft products in Shopify. You can skip these products entirely with the `--skip-draft` flag:

```bash
# Skip products with zero inventory
php bin/product-sync.php --skip-draft

# Combine with debug mode
php bin/product-sync.php --debug --skip-draft
```

This is useful to:
- Reduce the number of products in your Shopify store
- Only import products that are available for purchase
- Improve store performance by having fewer products

#### Batch Processing
For large catalogs, you can process products in batches by specifying a starting batch index. To do this, you'll need to modify the sync script or create a custom one:

```php
<?php
// custom-sync.php
require_once __DIR__ . '/vendor/autoload.php';

use App\Sync\ProductSync;

// Create product sync instance, optionally with debug mode
$productSync = new ProductSync(true); // true for debug mode, false for normal mode

// Start from a specific batch (each batch contains 50 products by default)
$startBatchIndex = 5; // Start from the 6th batch (0-indexed)
$productSync->sync($startBatchIndex);

echo "Product sync completed from batch {$startBatchIndex}\n";
```

This is useful for:
- Resuming interrupted syncs
- Troubleshooting specific product batches
- Reducing server load by spreading the sync process over time
- Processing only a subset of products during testing

#### Examining Cached Product Data

For debugging purposes, you can examine the cached product data directly:

```bash
# List all cached products
php bin/cache-manager.php list

# View detailed cache status
php bin/cache-manager.php status

# View a specific product's cached data
cat storage/cache/products/product_12345.json | json_pp
```

The cached data shows exactly what information is being used for product synchronization and can be helpful for troubleshooting price, inventory, or data mapping issues.

### Cron Jobs

Set up cron jobs to run the sync automatically:

```
# Products - Once per day (2am)
0 2 * * * /path/to/shopify-powerbody-sync/sync-products.sh >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1

# Orders, Comments, Returns - Every hour
0 * * * * /path/to/shopify-powerbody-sync/sync-orders-comments-refunds.sh >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1

# Generate daily report at 6am
0 6 * * * /path/to/shopify-powerbody-sync/sync-report.sh -d 1 -o /path/to/shopify-powerbody-sync/logs/daily_report_$(date +\%Y\%m\%d).txt

# Alternative using PHP scripts directly:
0 2 * * * /usr/bin/php /path/to/shopify-powerbody-sync/bin/product-sync.php >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1
0 * * * * /usr/bin/php /path/to/shopify-powerbody-sync/bin/order-sync.php >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1
0 * * * * /usr/bin/php /path/to/shopify-powerbody-sync/bin/comment-sync.php >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1
0 * * * * /usr/bin/php /path/to/shopify-powerbody-sync/bin/return-sync.php >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1
```

## Product Customization

The synchronization includes several product customizations:

### Price Markup
All product prices from PowerBody are automatically increased by 22% before being imported to Shopify.

### Title Cleanup
The system automatically removes "(EAN XXXXX)" patterns from product titles for cleaner display in your store.

### Metafields
The following metafields are added to products:

1. **manufacturer**: The original manufacturer of the product
2. **portion_count**: Number of servings in the product
3. **price_per_serving**: The price per serving with the 22% markup applied
4. **supplier**: Always set to "Powerbody" for easy identification in Shopify

### Displaying Metafields in Shopify

To display metafields on your store:

1. Go to Shopify admin > Settings > Custom data
2. Make the metafields visible in the product editor
3. Update your theme to display these values on the product page:

Example code for your theme's product template:
```liquid
{% if product.metafields.powerbody.portion_count %}
  <div class="product-servings">
    <span class="label">Servings:</span>
    <span class="value">{{ product.metafields.powerbody.portion_count }}</span>
  </div>
{% endif %}

{% if product.metafields.powerbody.price_per_serving %}
  <div class="price-per-serving">
    <span class="label">Price per serving:</span>
    <span class="value">{{ product.metafields.powerbody.price_per_serving | money }}</span>
  </div>
{% endif %}
```

## Data Storage

- SQLite database is used to store mapping information between Shopify and PowerBody entities
- Database is created automatically on first run
- Located at `storage/sync.db`
- Statistics data is stored in a separate `storage/sync_stats.db` database

## Statistics and Analytics

The system includes a comprehensive statistics tracking and reporting system with the following features:

### Statistics Database

The statistics system maintains several tables in the `storage/sync_stats.db` SQLite database:

1. **sync_run**: Records each sync operation with details on timing, status, and item counts
2. **sync_detail**: Tracks detailed information for each item processed
3. **sync_daily_summary**: Aggregates daily statistics for quick reporting

### Reporting Tools

Several tools are available for viewing and analyzing statistics:

1. **sync-stats.php**: Command-line tool for viewing statistics
   ```bash
   # View all stats for the last 7 days
   php bin/sync-stats.php
   
   # View stats for a specific type with custom date range
   php bin/sync-stats.php -t product -d 14
   
   # View detailed logs for a specific sync run
   php bin/sync-stats.php -r 123
   ```

2. **sync-report.sh**: Shell script for generating comprehensive reports
   ```bash
   # Generate a report for all sync types in the last 7 days
   ./sync-report.sh
   
   # Generate a report for specific type and date range
   ./sync-report.sh -t order -d 30
   
   # Save report to a file
   ./sync-report.sh -o monthly_report.txt -d 30
   ```

### Statistics Data Available

The statistics system tracks:

1. **Sync Runs**: Basic information about each sync operation
   - Date and time started/completed
   - Sync type (product, order, comment, refund)
   - Status (success, failure, running)
   - Item counts (processed, succeeded, failed)

2. **Item Details**: Detailed information for each item processed
   - Item ID and type
   - Operation performed (create, update, delete)
   - Status (success, failure)
   - Timestamp
   - Error messages or additional details

3. **Daily Summaries**: Aggregated daily statistics
   - Counts by sync type
   - Success and failure rates
   - Total items processed

### Integrating Statistics in Custom Scripts

You can integrate the statistics tracking in your own custom scripts:

```php
<?php
use App\Logger\SyncStats;

// Get the singleton instance
$stats = SyncStats::getInstance();

// Start tracking a sync run
$runId = $stats->startSync('custom');

// Log individual items
$stats->logItem($runId, 'item-123', 'product', 'create', 'success', 'Product created successfully');
$stats->logItem($runId, 'item-456', 'product', 'update', 'failure', 'API error: Rate limit exceeded');

// End the sync run
$stats->endSync($runId, 'success'); // or 'failure'
```

## Product Caching System

The application implements a caching system for PowerBody product data to reduce API calls and improve performance. This helps address API rate limits and speeds up synchronization tasks.

### How Caching Works

- Product information is cached in JSON files in the `storage/cache/products` directory
- Cache has an expiration time of one week
- When requesting product information, the system first checks the cache
- If cache is valid, it returns cached data immediately
- If cache is expired or missing, it fetches fresh data from the API and updates the cache
- Background processes preload additional product details to warm up the cache for future use

### Cache Management Tool

A cache management tool is included to view and manage the product cache:

```bash
# Show cache help
php bin/cache-manager.php help

# List all cached products
php bin/cache-manager.php list

# Show cache status summary
php bin/cache-manager.php status

# Clear all product caches
php bin/cache-manager.php clear

# Clear specific product cache
php bin/cache-manager.php clear 12345

# Refresh cache for specific product
php bin/cache-manager.php refresh 12345
```

## PowerBody API Integration

This application integrates with all PowerBody Dropshipping API v1.5 methods:

- **getProductList** - Fetch all products
- **getProductInfo** - Get detailed product information
- **getOrders** - Fetch order status updates
- **createOrder** - Send new orders to PowerBody
- **updateOrder** - Update existing orders
- **getRefundOrders** - Handle returns and refunds
- **insertComment** - Send comments to orders
- **getComments** - Fetch comments from orders
- **getShippingMethods** - Get available shipping methods

## Logging

- Logs are stored in `logs/` directory
- Log level can be configured in `.env` file
- Statistics data is stored in the `storage/sync_stats.db` database
- Detailed run statistics can be viewed using the `sync-stats.php` tool

## Troubleshooting

If you encounter any issues with the synchronization:

1. Check the log files in the `logs/` directory
2. Use `php bin/sync-stats.php` to view recent sync operations and their status
3. View detailed logs for specific sync runs with `php bin/sync-stats.php -r RUN_ID`
4. Failed operations are stored as JSON files in the `storage/` directory with `dead_letter_` prefix
5. Ensure your API credentials are correct
6. Verify that your Shopify API access token has the required permissions
7. Check cache status with `php bin/cache-manager.php status` for product sync issues

## License

This project is licensed under the MIT License.