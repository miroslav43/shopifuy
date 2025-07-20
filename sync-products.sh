#!/bin/bash

# PowerBody to Shopify Product Sync Script
# This script handles complete product synchronization:
# 1. Fetch products from PowerBody SOAP API
# 2. Apply price markup and enhancements
# 3. Create/update products in Shopify with rich metafields
# 4. Sync inventory levels and collections

set -e  # Exit on any error

# Configuration
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
LOG_FILE="$SCRIPT_DIR/logs/sync-products.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Error handling
error_exit() {
    log "${RED}ERROR: $1${NC}"
    exit 1
}

# Check prerequisites
check_prerequisites() {
    log "${BLUE}Checking prerequisites...${NC}"
    
    # Check if PHP is available
    if ! command -v php &> /dev/null; then
        error_exit "PHP is not installed or not in PATH"
    fi
    
    # Check if composer dependencies are installed
    if [ ! -d "$SCRIPT_DIR/vendor" ]; then
        error_exit "Composer dependencies not installed. Run: composer install"
    fi
    
    # Check if .env file exists
    if [ ! -f "$SCRIPT_DIR/.env" ]; then
        error_exit ".env file not found. Please copy .env-sample to .env and configure it."
    fi
    
    # Check required PHP extensions
    php -m | grep -q soap || error_exit "PHP SOAP extension is required"
    php -m | grep -q json || error_exit "PHP JSON extension is required"
    php -m | grep -q sqlite3 || error_exit "PHP SQLite3 extension is required"
    
    log "${GREEN}Prerequisites check passed${NC}"
}

# Validate configuration
validate_config() {
    log "${BLUE}Validating configuration...${NC}"
    
    # Source .env file
    source "$SCRIPT_DIR/.env"
    
    # Check PowerBody configuration
    if [ -z "$POWERBODY_API_WSDL" ] || [ -z "$POWERBODY_USER" ] || [ -z "$POWERBODY_PASS" ]; then
        error_exit "PowerBody API credentials not configured in .env file"
    fi
    
    # Check Shopify configuration
    if [ -z "$SHOPIFY_STORE" ] || [ -z "$SHOPIFY_ACCESS_TOKEN" ]; then
        error_exit "Shopify API credentials not configured in .env file"
    fi
    
    log "${GREEN}Configuration validation passed${NC}"
}

# Test API connections
test_connections() {
    log "${BLUE}Testing API connections...${NC}"
    
    cd "$SCRIPT_DIR"
    
    # Test PowerBody SOAP API connection
    log "${YELLOW}Testing PowerBody API connection...${NC}"
    php -r "
        require 'vendor/autoload.php';
        \$env = App\Config\EnvLoader::getInstance();
        try {
            // Create SOAP client (no credentials in constructor)
            \$client = new SoapClient(\$_ENV['POWERBODY_API_WSDL'], [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'trace' => true,
                'exceptions' => true
            ]);
            
            // Login to get session (correct PowerBody API pattern)
            \$session = \$client->login(\$_ENV['POWERBODY_USER'], \$_ENV['POWERBODY_PASS']);
            
            // Test API call using session (getProductList takes no parameters)
            \$result = \$client->call(\$session, 'dropshipping.getProductList');
            
            // End session properly
            \$client->endSession(\$session);
            
            echo 'PowerBody API connection successful' . PHP_EOL;
        } catch (Exception \$e) {
            echo 'PowerBody API connection failed: ' . \$e->getMessage() . PHP_EOL;
            exit(1);
        }
    " || error_exit "PowerBody API connection test failed"
    
    # Test Shopify API connection
    log "${YELLOW}Testing Shopify API connection...${NC}"
    php -r "
        require 'vendor/autoload.php';
        \$env = App\Config\EnvLoader::getInstance();
        try {
            \$client = new GuzzleHttp\Client();
            \$response = \$client->get(
                'https://' . \$_ENV['SHOPIFY_STORE'] . '/admin/api/2023-10/shop.json',
                ['headers' => ['X-Shopify-Access-Token' => \$_ENV['SHOPIFY_ACCESS_TOKEN']]]
            );
            if (\$response->getStatusCode() === 200) {
                echo 'Shopify API connection successful' . PHP_EOL;
            } else {
                throw new Exception('HTTP ' . \$response->getStatusCode());
            }
        } catch (Exception \$e) {
            echo 'Shopify API connection failed: ' . \$e->getMessage() . PHP_EOL;
            exit(1);
        }
    " || error_exit "Shopify API connection test failed"
    
    log "${GREEN}API connections verified${NC}"
}

# Run product synchronization
run_product_sync() {
    log "${BLUE}Starting PowerBody to Shopify product synchronization...${NC}"
    
    cd "$SCRIPT_DIR"
    
    # Run product sync with detailed output
    php bin/sync.php products 2>&1 | tee -a "$LOG_FILE"
    local php_exit_code=${PIPESTATUS[0]}
    
    if [ $php_exit_code -eq 0 ]; then
        log "${GREEN}Product sync completed successfully${NC}"
    else
        error_exit "Product sync failed with exit code $php_exit_code"
    fi
}

# Show sync statistics
show_statistics() {
    log "${BLUE}Product Sync Statistics${NC}"
    log "======================="
    
    cd "$SCRIPT_DIR"
    
    # Get statistics from the database
    php -r "
        require 'vendor/autoload.php';
        \$env = App\Config\EnvLoader::getInstance();
        \$db = new App\Core\Database();
        
        // Get product counts
        \$totalProducts = \$db->query('SELECT COUNT(*) as count FROM products')->fetch()['count'] ?? 0;
        \$syncedToday = \$db->query('SELECT COUNT(*) as count FROM products WHERE DATE(updated_at) = DATE(\"now\")')->fetch()['count'] ?? 0;
        
        echo 'Total Products in Database: ' . \$totalProducts . PHP_EOL;
        echo 'Products Synced Today: ' . \$syncedToday . PHP_EOL;
        
        // Get recent sync activity
        \$recent = \$db->query('SELECT * FROM products WHERE DATE(updated_at) = DATE(\"now\") ORDER BY updated_at DESC LIMIT 5')->fetchAll();
        if (!empty(\$recent)) {
            echo PHP_EOL . 'Recent Product Updates:' . PHP_EOL;
            foreach (\$recent as \$product) {
                echo '- ' . \$product['name'] . ' (ID: ' . \$product['powerbody_id'] . ')' . PHP_EOL;
            }
        }
    " 2>/dev/null || log "${YELLOW}Statistics not available (database may be empty)${NC}"
}

# Main execution
main() {
    log "${BLUE}Starting PowerBody to Shopify Product Sync${NC}"
    log "==========================================="
    
    # Ensure logs directory exists
    mkdir -p "$SCRIPT_DIR/logs"
    
    # Check prerequisites
    check_prerequisites
    
    # Validate configuration
    validate_config
    
    # Test API connections (skip if --skip-tests flag is provided)
    if [ "$SKIP_TESTS" != "true" ]; then
        test_connections
    fi
    
    # Run product synchronization
    run_product_sync
    
    # Show statistics
    show_statistics
    
    log "${GREEN}Product sync process completed successfully!${NC}"
    log "==========================================="
}

# Help function
show_help() {
    echo "PowerBody to Shopify Product Sync Script"
    echo "========================================"
    echo ""
    echo "This script handles complete product synchronization from PowerBody to Shopify:"
    echo "1. Fetches products from PowerBody SOAP API"
    echo "2. Applies price markup and enhancements"
    echo "3. Creates/updates products in Shopify with rich metafields"
    echo "4. Syncs inventory levels and creates collections"
    echo ""
    echo "Features:"
    echo "- Automatic price markup configuration"
    echo "- EAN code cleanup from product titles"
    echo "- Rich metafields (manufacturer, portions, price per serving)"
    echo "- Auto-creation of category collections"
    echo "- Inventory level synchronization"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  -h, --help        Show this help message"
    echo "  -t, --skip-tests  Skip API connection tests"
    echo "  -s, --stats-only  Show statistics only (no sync)"
    echo ""
    echo "Environment variables (.env file):"
    echo "  POWERBODY_API_WSDL     PowerBody SOAP API WSDL URL"
    echo "  POWERBODY_USER         PowerBody API username"
    echo "  POWERBODY_PASS         PowerBody API password"
    echo "  SHOPIFY_STORE          Shopify store domain"
    echo "  SHOPIFY_ACCESS_TOKEN   Shopify API access token"
    echo ""
    echo "Examples:"
    echo "  $0                     # Run full product sync"
    echo "  $0 --skip-tests        # Skip API connection tests"
    echo "  $0 --stats-only        # Show statistics only"
    echo ""
    echo "For order sync:"
    echo "  ./sync-complete.sh     # Full workflow with orders"
    echo "  ./sync-quick.sh        # Quick order sync"
    echo ""
}

# Parse command line arguments
SKIP_TESTS=false
STATS_ONLY=false

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            exit 0
            ;;
        -t|--skip-tests)
            SKIP_TESTS=true
            shift
            ;;
        -s|--stats-only)
            STATS_ONLY=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            show_help
            exit 1
            ;;
    esac
done

# Handle stats-only mode
if [ "$STATS_ONLY" = "true" ]; then
    echo "Product Sync Statistics"
    echo "======================"
    mkdir -p "$SCRIPT_DIR/logs"
    check_prerequisites
    validate_config
    show_statistics
    exit 0
fi

# Run main function
main 