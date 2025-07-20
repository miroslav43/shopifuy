#!/bin/bash

# Quick Shopify-PowerBody Sync Script
# This script assumes JavaScript server is already running and handles:
# STEP 1: Fetch fresh orders from Shopify via existing JavaScript server
# STEP 2: Process those orders with PHP sync to PowerBody

set -e  # Exit on any error

# Configuration
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
JS_DIR="$SCRIPT_DIR/FetchOrdersJava"
ORDERS_FILE="$JS_DIR/orders_data/orders.json"
LOG_FILE="$SCRIPT_DIR/logs/sync-quick.log"
JS_PORT="${JS_PORT:-3000}"
SHOPIFY_STORE="${SHOPIFY_STORE:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
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
    
    # Check if JavaScript directory exists
    if [ ! -d "$JS_DIR" ]; then
        error_exit "JavaScript directory not found: $JS_DIR"
    fi
    
    # Check if orders data directory exists, create if not
    if [ ! -d "$JS_DIR/orders_data" ]; then
        log "${YELLOW}Creating orders_data directory...${NC}"
        mkdir -p "$JS_DIR/orders_data"
    fi
    
    # Check if .env file exists
    if [ ! -f "$SCRIPT_DIR/.env" ]; then
        error_exit ".env file not found. Please copy .env-sample to .env and configure it."
    fi
    
    # Source .env file to get SHOPIFY_STORE
    source "$SCRIPT_DIR/.env"
    
    if [ -z "$SHOPIFY_STORE" ]; then
        error_exit "SHOPIFY_STORE not configured in .env file"
    fi
    
    log "${GREEN}Prerequisites check passed${NC}"
}

# Check if JavaScript server is running
check_js_server() {
    log "${BLUE}Checking JavaScript server status...${NC}"
    
    if curl -s "http://localhost:$JS_PORT/" > /dev/null 2>&1; then
        log "${GREEN}JavaScript server is running on port $JS_PORT${NC}"
        return 0
    else
        error_exit "JavaScript server is not running on port $JS_PORT. Please start it first with:
${YELLOW}cd FetchOrdersJava && node server.js${NC}
${YELLOW}Or use the complete sync script: ./sync-complete.sh${NC}"
    fi
}

# Clear old orders data to ensure fresh fetch
clear_old_orders() {
    log "${CYAN}STEP 1: Preparing to fetch fresh orders...${NC}"
    
    if [ -f "$ORDERS_FILE" ]; then
        local old_timestamp=$(stat -c %y "$ORDERS_FILE" 2>/dev/null || echo "unknown")
        log "${YELLOW}Removing old orders.json (created: $old_timestamp)${NC}"
        rm -f "$ORDERS_FILE"
    fi
    
    touch "$ORDERS_FILE"
    log "${GREEN}New orders.json created${NC}"
    
    # Also clear any backup files
    rm -f "$JS_DIR/orders_data/"*.json.bak 2>/dev/null || true
    
    log "${GREEN}Ready to fetch fresh orders${NC}"
}

# Fetch orders via JavaScript component
fetch_fresh_orders() {
    log "${CYAN}STEP 1: Fetching fresh orders from Shopify...${NC}"
    log "=============================================="
    
    local orders_url="http://localhost:$JS_PORT/orders?shop=$SHOPIFY_STORE"
    local response
    
    # Check if we need authentication first
    log "${BLUE}Checking authentication status...${NC}"
    local auth_check=$(curl -s "$orders_url" 2>/dev/null)
    if echo "$auth_check" | grep -q "install the app first"; then
        error_exit "App not authenticated. Please visit: ${YELLOW}http://localhost:$JS_PORT/auth?shop=$SHOPIFY_STORE${NC}"
    fi
    
    # Fetch fresh orders
    log "${BLUE}Requesting fresh orders from JavaScript server...${NC}"
    response=$(curl -s "$orders_url" 2>/dev/null) || error_exit "Failed to fetch orders from JavaScript server"
    
    # Check if response contains error
    if echo "$response" | grep -q "error"; then
        error_exit "JavaScript server returned error: $response"
    fi
    
    # Verify orders.json was created and has content
    if [ ! -f "$ORDERS_FILE" ]; then
        error_exit "orders.json file was not created by JavaScript server"
    fi
    
    # Check file size
    local file_size=$(stat -c%s "$ORDERS_FILE" 2>/dev/null || echo "0")
    if [ "$file_size" -lt 50 ]; then
        error_exit "orders.json file is too small ($file_size bytes), likely empty or corrupted"
    fi
    
    # Parse and log order count
    local order_count=$(echo "$response" | grep -o '"ordersCount":[0-9]*' | cut -d':' -f2 || echo "unknown")
    local fetch_timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    log "${GREEN}✅ Successfully fetched $order_count fresh orders${NC}"
    log "${GREEN}✅ Orders saved to: $ORDERS_FILE${NC}"
    log "${GREEN}✅ Fetch completed at: $fetch_timestamp${NC}"
    log "${GREEN}✅ File size: ${file_size} bytes${NC}"
    
    # Show a sample of the data for verification
    if command -v jq &> /dev/null; then
        local sample_order=$(cat "$ORDERS_FILE" | jq -r '.orders.orders.edges[0].node.name // "No orders"' 2>/dev/null || echo "Could not parse")
        log "${BLUE}Sample order: $sample_order${NC}"
    fi
    
    log "=============================================="
}

# Verify fresh orders before processing
verify_fresh_orders() {
    log "${BLUE}Verifying fresh orders data before processing...${NC}"
    
    if [ ! -f "$ORDERS_FILE" ]; then
        error_exit "No orders.json file found. Fresh fetch may have failed."
    fi
    
    # Check if file was recently created (within last 5 minutes)
    local current_time=$(date +%s)
    local file_time=$(stat -c %Y "$ORDERS_FILE" 2>/dev/null || echo "0")
    local age_seconds=$((current_time - file_time))
    
    if [ $age_seconds -gt 300 ]; then
        log "${YELLOW}Warning: orders.json is $((age_seconds/60)) minutes old${NC}"
        log "${YELLOW}Consider running fetch again if you need very fresh data${NC}"
    else
        log "${GREEN}✅ Orders data is fresh (${age_seconds} seconds old)${NC}"
    fi
    
    # Quick JSON validation
    if command -v jq &> /dev/null; then
        if ! jq empty "$ORDERS_FILE" 2>/dev/null; then
            error_exit "orders.json contains invalid JSON"
        fi
        log "${GREEN}✅ JSON format is valid${NC}"
    fi
}

# Run PHP sync to process the fresh orders
run_php_sync() {
    log "${CYAN}STEP 2: Processing fresh orders with PHP sync...${NC}"
    log "=============================================="
    
    cd "$SCRIPT_DIR"
    
    # Show what we're about to process
    log "${BLUE}Processing orders from: $ORDERS_FILE${NC}"
    log "${BLUE}Target: PowerBody Dropshipping API${NC}"
    log "${BLUE}Mode: Orders without ANY tags → PowerBody${NC}"
    
    # Run only order sync since products are handled separately
    log "${BLUE}Starting PHP order sync...${NC}"
    php bin/sync.php orders 2>&1 | tee -a "$LOG_FILE"
    local php_exit_code=${PIPESTATUS[0]}
    
    if [ $php_exit_code -eq 0 ]; then
        log "${GREEN}✅ PHP sync completed successfully${NC}"
        log "${GREEN}✅ Orders processed and sent to PowerBody${NC}"
    else
        error_exit "PHP sync failed with exit code $php_exit_code"
    fi
    
    log "=============================================="
}

# Main execution
main() {
    log "${CYAN}=========================================${NC}"
    log "${CYAN}  QUICK SHOPIFY → POWERBODY SYNC${NC}"
    log "${CYAN}=========================================${NC}"
    log "${BLUE}Process: FETCH FRESH → SYNC TO POWERBODY${NC}"
    
    # Ensure logs directory exists
    mkdir -p "$SCRIPT_DIR/logs"
    
    # Check prerequisites
    check_prerequisites
    
    # Check if JavaScript server is running
    check_js_server
    
    # STEP 1: Fetch fresh orders from Shopify
    clear_old_orders
    fetch_fresh_orders
    verify_fresh_orders
    
    # Small pause between steps
    log "${YELLOW}Pausing 2 seconds before processing...${NC}"
    sleep 2
    
    # STEP 2: Process the fresh orders
    run_php_sync
    
    log "${GREEN}=========================================${NC}"
    log "${GREEN}  QUICK SYNC COMPLETED SUCCESSFULLY!${NC}"
    log "${GREEN}=========================================${NC}"
    log "${GREEN}✅ Fresh orders fetched from Shopify${NC}"
    log "${GREEN}✅ Orders without tags sent to PowerBody${NC}"
    log "${GREEN}✅ Successful orders now have tags${NC}"
}

# Help function
show_help() {
    echo "Quick Shopify-PowerBody Sync Script"
    echo "===================================="
    echo ""
    echo "This script performs a two-step quick sync process:"
    echo ""
    echo "${CYAN}STEP 1: FETCH FRESH ORDERS${NC}"
    echo "  - Clears old orders.json file"
    echo "  - Fetches fresh orders from Shopify via JavaScript server"
    echo "  - Verifies data integrity"
    echo ""
    echo "${CYAN}STEP 2: SYNC TO POWERBODY${NC}"
    echo "  - Processes fresh orders with PHP sync"
    echo "  - Sends orders without ANY tags to PowerBody"
    echo "  - Tags successful orders to prevent reprocessing"
    echo ""
    echo "Prerequisites:"
    echo "- JavaScript server must be running on specified port"
    echo "- App must be authenticated with Shopify"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  -h, --help     Show this help message"
    echo "  -p, --port     JavaScript server port (default: 3000)"
    echo ""
    echo "Environment variables:"
    echo "  SHOPIFY_STORE  Your Shopify store domain (configured in .env)"
    echo "  JS_PORT        JavaScript server port (default: 3000)"
    echo ""
    echo "Examples:"
    echo "  $0                    # Run with default settings"
    echo "  $0 -p 8080           # Use port 8080 for JavaScript server"
    echo ""
    echo "To start JavaScript server:"
    echo "  cd FetchOrdersJava && node server.js"
    echo ""
    echo "For fully automated workflow (starts/stops server):"
    echo "  ./sync-complete.sh"
    echo ""
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            exit 0
            ;;
        -p|--port)
            JS_PORT="$2"
            shift 2
            ;;
        *)
            echo "Unknown option: $1"
            show_help
            exit 1
            ;;
    esac
done

# Run main function
main 