# PowerBody-Shopify Synchronization Service

This service provides bidirectional synchronization between PowerBody Dropshipping API (v1.5) and Shopify Admin API, enabling seamless dropshipping operations.

## Overview

The service synchronizes:
- **Products**: Import products from PowerBody API to Shopify with price markup and enhancements
- **Orders**: Send Shopify orders to PowerBody for fulfillment and sync status updates back
- **Order Comments**: Bidirectional comment synchronization between platforms
- **Returns/Refunds**: Sync return processing from PowerBody to Shopify

## PowerBody API Integration

This service integrates with PowerBody's SOAP-based Dropshipping API v1.5, implementing all core methods:

### Order Management
- `createOrder` - Send new orders to PowerBody for fulfillment
- `updateOrder` - Update existing orders when modifications are needed
- `getOrders` - Retrieve order status updates and tracking information

### Product Management
- `getProductList` - Fetch all available products with pricing and inventory
- `getProductInfo` - Get detailed product information including descriptions

### Comments & Communication
- `insertComment` - Send order comments to PowerBody
- `getComments` - Retrieve comments from PowerBody staff

### Returns & Refunds
- `getRefundOrders` - Fetch processed returns for creating Shopify refunds

## Features

### Product Sync
- **Configurable Price Markup**: Applies customizable markup percentage to all PowerBody prices (configurable via `PRICE_MARKUP_PERCENT` environment variable)
- **EAN Cleanup**: Removes EAN codes from product titles for cleaner presentation
- **Rich Metafields**: Adds manufacturer, portion count, price per serving data
- **Category Collections**: Auto-creates and assigns products to Shopify collections
- **Inventory Sync**: Keeps stock levels synchronized between platforms

### Order Sync
- **Complete Order Processing**: Sends all order details including customer info and shipping
- **Status Updates**: Tracks order progress from PowerBody back to Shopify
- **Fulfillment Management**: Creates and updates Shopify fulfillments with tracking
- **Comment Integration**: Syncs order notes and communication bidirectionally
- **Return Processing**: Handles refunds initiated by PowerBody

## Requirements

- PHP 7.4 or higher
- Composer
- SQLite3 extension
- SOAP extension  
- JSON extension
- Node.js (for FetchOrdersJava component)

## Installation

1. Clone the repository and install dependencies:
   ```bash
   composer install
   ```

2. Create environment file:
   ```bash
   cp .env-sample .env
   ```

3. Configure your credentials in `.env`:
   ```env
   # PowerBody SOAP API (v1.5)
   POWERBODY_API_WSDL="http://www.powerbody.co.uk/api/soap/?wsdl"
   POWERBODY_USER="your-username"
   POWERBODY_PASS="your-password"

   # Shopify Admin REST API
   SHOPIFY_STORE="your-store.myshopify.com"
   SHOPIFY_ACCESS_TOKEN="your-access-token"
   SHOPIFY_LOCATION_ID="your-location-id"

   # Product Settings
   PRICE_MARKUP_PERCENT="22"        # Markup percentage to apply to PowerBody prices
   DEFAULT_VENDOR="Powerbody"
   DEFAULT_PRODUCT_TYPE="Supplement"

   # Sync Settings
   LOG_LEVEL="INFO"
   SYNC_INTERVAL="3600"             # Sync interval in seconds
   ```

## Usage

### Workflow Scripts

```bash
# Complete automated workflow: Start JS server + fetch + sync + cleanup
./sync-complete.sh

# Quick sync: Assumes JS server already running, just fetch + sync
./sync-quick.sh

# Product sync: Complete PowerBody to Shopify product synchronization
./sync-products.sh

# JavaScript-only fetch: Fetch orders to JSON (no PHP processing)
./fetch-orders.sh
```

### Main Sync Commands

```bash
# Run all sync operations (products + orders)
php bin/sync.php

# Run only product sync
php bin/sync.php products

# Run only order sync (includes comments and returns)
php bin/sync.php orders

# Using shell script (direct API, no JavaScript)
./sync.sh
```

### Script Options Comparison

| Script | Focus | JavaScript Server | API Tests | Use Case |
|--------|-------|------------------|-----------|----------|
| `sync-complete.sh` | Orders | Starts/stops automatically | ✗ | Fully automated order workflow |
| `sync-quick.sh` | Orders | Assumes already running | ✗ | Quick order sync with persistent server |
| `sync-products.sh` | Products | N/A | ✓ | Complete product synchronization |
| `fetch-orders.sh` | Orders | Assumes already running | ✗ | JavaScript fetch only |

### Complete Workflow Script

The `sync-complete.sh` script provides the full automated workflow:

```bash
# Run complete workflow with default settings
./sync-complete.sh

# Use custom port for JavaScript server
./sync-complete.sh -p 8080

# Extend server startup timeout
./sync-complete.sh -t 60

# Show help
./sync-complete.sh --help
```

**What the complete script does:**
1. **Prerequisites Check**: Verifies Node.js, PHP, and configuration
2. **JavaScript Server**: Starts OAuth server (if not already running)
3. **Order Fetch**: Fetches orders via JavaScript and saves to JSON
4. **PHP Processing**: Processes orders through PHP sync to PowerBody
5. **Cleanup**: Stops JavaScript server and cleans up resources

### Quick Sync Script

The `sync-quick.sh` script is perfect when you have a persistent JavaScript server:

```bash
# Run quick sync with default settings
./sync-quick.sh

# Use custom port for JavaScript server
./sync-quick.sh -p 8080

# Show help
./sync-quick.sh --help
```

**What the quick script does:**
1. **Prerequisites Check**: Verifies PHP and configuration
2. **Server Check**: Ensures JavaScript server is running
3. **Order Fetch**: Fetches orders via JavaScript and saves to JSON
4. **PHP Processing**: Processes orders through PHP sync to PowerBody

**Benefits:**
- **No Server Management**: Assumes server is already running
- **Faster Execution**: Skips server startup/shutdown overhead
- **Persistent Sessions**: Maintains OAuth sessions across multiple runs
- **Development Friendly**: Perfect for iterative development

### Product Sync Script

The `sync-products.sh` script handles complete PowerBody to Shopify product synchronization:

```bash
# Run full product sync with API tests
./sync-products.sh

# Skip API connection tests for faster execution
./sync-products.sh --skip-tests

# Show product statistics only
./sync-products.sh --stats-only

# Show help
./sync-products.sh --help
```

**What the product sync script does:**
1. **Prerequisites Check**: Verifies PHP, extensions, and dependencies
2. **Configuration Validation**: Ensures PowerBody and Shopify credentials are configured
3. **API Connection Tests**: Validates both PowerBody SOAP and Shopify REST APIs
4. **Product Synchronization**: Fetches products from PowerBody and creates/updates in Shopify
5. **Statistics Display**: Shows sync results and recent activity

**Product Sync Features:**
- **Price Markup**: Automatically applies configured markup to PowerBody prices
- **EAN Cleanup**: Removes EAN codes from product titles for cleaner presentation
- **Rich Metafields**: Adds manufacturer, portion count, and price per serving data
- **Auto Collections**: Creates and assigns products to category-based collections
- **Inventory Sync**: Synchronizes stock levels between PowerBody and Shopify
- **Error Handling**: Robust error checking with detailed logging

**Price Markup Configuration:**
The system applies a configurable markup to all PowerBody prices using the `PRICE_MARKUP_PERCENT` environment variable:

```bash
# Examples of different markup configurations:
PRICE_MARKUP_PERCENT="22"    # 22% markup (default) - €10.00 → €12.20
PRICE_MARKUP_PERCENT="35"    # 35% markup for higher margins - €10.00 → €13.50
PRICE_MARKUP_PERCENT="15"    # 15% markup for competitive pricing - €10.00 → €11.50
PRICE_MARKUP_PERCENT="0"     # No markup for cost price testing - €10.00 → €10.00
```

The markup is applied to both the main product price and the price-per-serving metafield, ensuring consistency across all pricing data.

**Benefits:**
- **Complete Workflow**: Handles entire product sync process
- **API Validation**: Pre-flight checks ensure connectivity before sync
- **Detailed Logging**: Comprehensive logging to `logs/sync-products.log`
- **Statistics**: Real-time sync statistics and recent activity tracking
- **Flexible Options**: Skip tests for speed or view stats without syncing

### Individual Components

```bash
# Product sync only
php bin/product-sync.php

# Order sync only (includes comments and returns)
php bin/order-sync.php

# Fetch orders via JavaScript only (no PHP processing)
./fetch-orders.sh
```

### JavaScript Order Fetcher

The FetchOrdersJava component provides OAuth-based order fetching capabilities:

```bash
cd FetchOrdersJava
npm install
node server.js
```

This component handles OAuth authentication and provides endpoints for fetching order data that can be processed by the PHP sync components.

#### Integration with PHP OrderSync

The OrderSync component intelligently integrates with the JavaScript order fetcher:

1. **Primary Source**: OrderSync first checks for `FetchOrdersJava/orders_data/orders.json`
2. **Data Conversion**: Converts GraphQL order format (from JS) to REST API format for compatibility
3. **Fallback**: If no JavaScript JSON file exists, falls back to direct Shopify API calls

**Order Processing Flow:**
```
JavaScript OAuth → orders.json → PHP OrderSync → PowerBody SOAP API
                ↓ (fallback)
              Direct Shopify API → PHP OrderSync → PowerBody SOAP API
```

**Benefits of JavaScript Integration:**
- Better OAuth token management
- More complete order data via GraphQL
- Reduced API rate limits on main sync process
- Separation of concerns (auth vs processing)

## Architecture

### Core Components

- **ProductSync**: Handles product synchronization from PowerBody to Shopify
- **OrderSync**: Manages complete order lifecycle including comments and returns
- **PowerBodyLink**: SOAP API client for PowerBody integration
- **ShopifyLink**: REST/GraphQL API client for Shopify integration
- **Database**: SQLite-based tracking for sync states and mappings

### Sync Process

1. **Product Sync**: Fetches products from PowerBody, applies markup and enhancements, creates/updates in Shopify
2. **Order Sync**: 
   - Retrieves unfulfilled Shopify orders
   - Sends new orders to PowerBody via SOAP API
   - Checks PowerBody for order status updates
   - Syncs comments bidirectionally
   - Processes returns and creates Shopify refunds

### Data Flow

```
PowerBody SOAP API ←→ OrderSync ←→ Shopify REST/GraphQL API
PowerBody SOAP API ←→ ProductSync ←→ Shopify REST API
```

## PowerBody API Payment Process

Orders sent to PowerBody require payment before fulfillment:

1. Orders are created with "on hold" status in PowerBody
2. Login to your PowerBody account to review and pay for orders
3. Payment is processed via Sage Pay
4. Orders move to fulfillment once payment is confirmed
5. Daily invoicing and settlement available

## Error Handling

- **Dead Letter Queue**: Failed orders are saved for manual review and retry
- **Comprehensive Logging**: All operations logged with configurable levels
- **Graceful Degradation**: Sync continues even if individual items fail
- **Retry Mechanisms**: Built-in retry for transient API failures

## File Structure

```
shopifuy/
├── src/
│   ├── Sync/
│   │   ├── ProductSync.php    # Product synchronization
│   │   └── OrderSync.php      # Order, comment, return sync
│   ├── Core/
│   │   ├── PowerBodyLink.php  # PowerBody SOAP API client
│   │   ├── ShopifyLink.php    # Shopify REST/GraphQL client
│   │   └── Database.php       # SQLite data layer
│   ├── Config/
│   │   └── EnvLoader.php      # Environment configuration
│   └── Logger/
├── bin/
│   ├── sync.php              # Main sync script
│   ├── product-sync.php      # Product sync only
│   └── order-sync.php        # Order sync only
├── FetchOrdersJava/          # Node.js OAuth order fetcher
├── storage/
│   └── cache/               # Order and product caching
├── sync-complete.sh          # Full automated workflow
├── sync-quick.sh            # Quick sync (assumes JS server running)
├── sync-products.sh          # Complete product synchronization
├── fetch-orders.sh          # JavaScript fetch only
└── vendor/                  # Composer dependencies
```

## Monitoring

- **Logs**: Stored in `logs/` directory with rotation
- **Sync States**: Tracked in SQLite database for resumable operations
- **Dead Letters**: Failed operations saved in `storage/` for review

## Support

For PowerBody API documentation and credentials, contact PowerBody IT department.
For Shopify API setup, refer to Shopify Partner documentation.