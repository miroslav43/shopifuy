#!/bin/bash

# Shopify Orders Fetch Script (JavaScript only)
# This script only handles the JavaScript order fetching part

set -e

# Configuration
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
JS_DIR="$SCRIPT_DIR/FetchOrdersJava"
JS_PORT="${JS_PORT:-3000}"
SHOPIFY_STORE="${SHOPIFY_STORE:-}"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}Shopify Orders Fetch (JavaScript)${NC}"
echo "=================================="

# Source .env file
if [ -f "$SCRIPT_DIR/.env" ]; then
    source "$SCRIPT_DIR/.env"
fi

if [ -z "$SHOPIFY_STORE" ]; then
    echo "Error: SHOPIFY_STORE not configured in .env file"
    exit 1
fi

# Check if JavaScript server is running
if curl -s "http://localhost:$JS_PORT/" > /dev/null 2>&1; then
    echo -e "${GREEN}JavaScript server is running on port $JS_PORT${NC}"
else
    echo -e "${YELLOW}JavaScript server not running. Please start it with:${NC}"
    echo "cd FetchOrdersJava && node server.js"
    echo ""
    echo -e "${YELLOW}Or use the complete sync script: ./sync-complete.sh${NC}"
    exit 1
fi

# Fetch orders
echo -e "${BLUE}Fetching orders from Shopify...${NC}"
orders_url="http://localhost:$JS_PORT/orders?shop=$SHOPIFY_STORE"

response=$(curl -s "$orders_url" 2>/dev/null)

# Check if response contains error
if echo "$response" | grep -q "error"; then
    echo "Error: JavaScript server returned error: $response"
    exit 1
fi

# Check if authentication is needed
if echo "$response" | grep -q "install the app first"; then
    echo -e "${YELLOW}App not authenticated. Please visit:${NC}"
    echo "http://localhost:$JS_PORT/auth?shop=$SHOPIFY_STORE"
    exit 1
fi

# Check if orders.json was created
if [ -f "$JS_DIR/orders_data/orders.json" ]; then
    order_count=$(echo "$response" | grep -o '"ordersCount":[0-9]*' | cut -d':' -f2 || echo "unknown")
    echo -e "${GREEN}Successfully fetched $order_count orders and saved to orders.json${NC}"
    echo ""
    echo -e "${BLUE}To process these orders with PowerBody, run:${NC}"
    echo "php bin/sync.php orders"
else
    echo "Error: orders.json file was not created"
    exit 1
fi 