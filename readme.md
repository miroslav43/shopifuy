# PowerBody-Shopify Synchronization Service

This microservice provides bidirectional synchronization between PowerBody Dropshipping API and Shopify Admin API.

## Features

- **Product Sync**: Import products from PowerBody API to Shopify
- **Order Sync**: Send Shopify orders to PowerBody and sync order status back to Shopify
- **Automatic Inventory Updates**: Keep inventory levels synchronized
- **Fulfillment Tracking**: Update Shopify fulfillments with tracking information
- **Product Caching**: Cache PowerBody product data to reduce API calls and improve performance

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

## Usage

### Run Full Synchronization

```bash
# Run all sync operations
php bin/sync.php

# Run with specific options
php bin/sync.php all     # Runs all sync jobs
php bin/sync.php products  # Only sync products
php bin/sync.php orders    # Only sync orders
```

### Individual Sync Tasks

```bash
# Product sync only
php bin/product-sync.php

# Order sync only
php bin/order-sync.php
```

### Cron Jobs

Set up cron jobs to run the sync automatically:

```
# Products - Once per day (2am)
0 2 * * * /usr/bin/php /path/to/shopify-powerbody-sync/bin/product-sync.php >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1

# Orders - Every hour
0 * * * * /usr/bin/php /path/to/shopify-powerbody-sync/bin/order-sync.php >> /path/to/shopify-powerbody-sync/logs/cron.log 2>&1
```

## Data Storage

- SQLite database is used to store mapping information between Shopify and PowerBody entities
- Database is created automatically on first run
- Located at `storage/sync.db`

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

### Benefits

- Reduced API calls to PowerBody
- Faster product synchronization
- More reliable operation with less dependence on API uptime
- Reduced likelihood of hitting rate limits

## Logging

- Logs are stored in `logs/` directory
- Log level can be configured in `.env` file

## Troubleshooting

If you encounter any issues with the synchronization:

1. Check the log files in the `logs/` directory
2. Failed operations are stored as JSON files in the `storage/` directory with `dead_letter_` prefix
3. Ensure your API credentials are correct
4. Verify that your Shopify API access token has the required permissions
5. Check cache status with `php bin/cache-manager.php status` for product sync issues

## License

This project is licensed under the MIT License.