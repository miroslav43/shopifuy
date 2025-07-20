a#!/bin/bash

# Complete Shopify-PowerBody Sync Script
# This script orchestrates the full workflow:
# 1. Start JavaScript OAuth server (if needed)
# 2. Fetch orders from Shopify via JavaScript component
# 3. Process orders with PHP sync to PowerBody

set -e  # Exit on any error

# Configuration
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
JS_DIR="$SCRIPT_DIR/FetchOrdersJava"
LOG_FILE="$SCRIPT_DIR/logs/sync-complete.log"
PID_FILE="$SCRIPT_DIR/js-server.pid"
JS_PORT="${JS_PORT:-3000}"
SHOPIFY_STORE="${SHOPIFY_STORE:-}"
TIMEOUT="${TIMEOUT:-30}"

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
    cleanup
    exit 1
}

# Cleanup function
cleanup() {
    if [ -f "$PID_FILE" ]; then
        local pid=$(cat "$PID_FILE")
        if kill -0 "$pid" 2>/dev/null; then
            log "${YELLOW}Stopping JavaScript server (PID: $pid)${NC}"
            kill "$pid" 2>/dev/null || true
            sleep 2
            kill -9 "$pid" 2>/dev/null || true
        fi
        rm -f "$PID_FILE"
    fi
}

# Trap to ensure cleanup on exit
trap cleanup EXIT

# Check prerequisites
check_prerequisites() {
    log "${BLUE}Checking prerequisites...${NC}"
    
    # Check if Node.js is available
    if ! command -v node &> /dev/null; then
        error_exit "Node.js is not installed or not in PATH"
    fi
    
    # Check if PHP is available
    if ! command -v php &> /dev/null; then
        error_exit "PHP is not installed or not in PATH"
    fi
    
    # Check if JavaScript directory exists
    if [ ! -d "$JS_DIR" ]; then
        error_exit "JavaScript directory not found: $JS_DIR"
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

# Start JavaScript server
start_js_server() {
    log "${BLUE}Starting JavaScript OAuth server...${NC}"
    
    cd "$JS_DIR"
    
    # Check if dependencies are installed
    if [ ! -d "node_modules" ]; then
        log "${YELLOW}Installing JavaScript dependencies...${NC}"
        npm install || error_exit "Failed to install JavaScript dependencies"
    fi
    
    # Start the server in background
    HOST="http://localhost:$JS_PORT" node server.js > "$SCRIPT_DIR/logs/js-server.log" 2>&1 &
    local server_pid=$!
    echo "$server_pid" > "$PID_FILE"
    
    # Wait for server to start
    log "${YELLOW}Waiting for JavaScript server to start on port $JS_PORT...${NC}"
    local attempts=0
    while [ $attempts -lt $TIMEOUT ]; do
        if curl -s "http://localhost:$JS_PORT/" > /dev/null 2>&1; then
            log "${GREEN}JavaScript server started successfully (PID: $server_pid)${NC}"
            return 0
        fi
        sleep 1
        attempts=$((attempts + 1))
    done
    
    error_exit "JavaScript server failed to start within $TIMEOUT seconds"
}

# Check if server is already running
check_existing_server() {
    if curl -s "http://localhost:$JS_PORT/" > /dev/null 2>&1; then
        log "${GREEN}JavaScript server already running on port $JS_PORT${NC}"
        return 0
    fi
    return 1
}

# Fetch orders via JavaScript component
fetch_orders() {
    log "${BLUE}Fetching orders from Shopify via JavaScript component...${NC}"
    
    local orders_url="http://localhost:$JS_PORT/orders?shop=$SHOPIFY_STORE"
    local response
    
    # Check if we need authentication first
    if curl -s "$orders_url" | grep -q "install the app first"; then
        log "${YELLOW}App not authenticated. Please visit the following URL to authenticate:${NC}"
        log "${YELLOW}http://localhost:$JS_PORT/auth?shop=$SHOPIFY_STORE${NC}"
        
        # Wait for user to authenticate
        read -p "Press Enter after completing OAuth authentication..."
    fi
    
    # Fetch orders
    response=$(curl -s "$orders_url" 2>/dev/null) || error_exit "Failed to fetch orders from JavaScript server"
    
    # Check if response contains error
    if echo "$response" | grep -q "error"; then
        error_exit "JavaScript server returned error: $response"
    fi
    
    # Check if orders.json was created
    if [ -f "$JS_DIR/orders_data/orders.json" ]; then
        local order_count=$(echo "$response" | grep -o '"ordersCount":[0-9]*' | cut -d':' -f2 || echo "unknown")
        log "${GREEN}Successfully fetched $order_count orders and saved to orders.json${NC}"
    else
        error_exit "orders.json file was not created"
    fi
}

# Run PHP sync
run_php_sync() {
    log "${BLUE}Running PHP sync to PowerBody...${NC}"
    
    cd "$SCRIPT_DIR"
    
    # Run only order sync since products are handled separately
    php bin/sync.php orders 2>&1 | tee -a "$LOG_FILE"
    local php_exit_code=${PIPESTATUS[0]}
    
    if [ $php_exit_code -eq 0 ]; then
        log "${GREEN}PHP sync completed successfully${NC}"
    else
        error_exit "PHP sync failed with exit code $php_exit_code"
    fi
}

# Main execution
main() {
    log "${BLUE}Starting complete Shopify-PowerBody sync process${NC}"
    log "======================================================"
    
    # Ensure logs directory exists
    mkdir -p "$SCRIPT_DIR/logs"
    
    # Check prerequisites
    check_prerequisites
    
    # Check if server is already running, start if needed
    if ! check_existing_server; then
        start_js_server
        local started_server=true
    else
        local started_server=false
    fi
    
    # Fetch orders from Shopify
    fetch_orders
    
    # Stop the server if we started it
    if [ "$started_server" = true ]; then
        cleanup
    fi
    
    # Run PHP sync to process orders
    run_php_sync
    
    log "${GREEN}Complete sync process finished successfully!${NC}"
    log "======================================================"
}

# Help function
show_help() {
    echo "Complete Shopify-PowerBody Sync Script"
    echo "======================================"
    echo ""
    echo "This script orchestrates the complete workflow:"
    echo "1. Starts JavaScript OAuth server (if needed)"
    echo "2. Fetches orders from Shopify via JavaScript component"
    echo "3. Processes orders with PHP sync to PowerBody"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  -h, --help     Show this help message"
    echo "  -p, --port     JavaScript server port (default: 3000)"
    echo "  -t, --timeout  Server startup timeout in seconds (default: 30)"
    echo ""
    echo "Environment variables:"
    echo "  SHOPIFY_STORE  Your Shopify store domain (configured in .env)"
    echo "  JS_PORT        JavaScript server port (default: 3000)"
    echo "  TIMEOUT        Server startup timeout (default: 30)"
    echo ""
    echo "Examples:"
    echo "  $0                    # Run with default settings"
    echo "  $0 -p 8080           # Use port 8080 for JavaScript server"
    echo "  $0 -t 60             # Wait up to 60 seconds for server start"
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
        -t|--timeout)
            TIMEOUT="$2"
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