#!/bin/bash
# Shopify Single Order Test Fetch Script
# This script fetches a specific order from Shopify by ID and saves the complete raw data to a JSON file

# Display start message
echo "Starting Shopify Single Order Test Fetch at $(date)"
echo "----------------------------------------------------"

# Check if order ID is provided
if [ -z "$1" ]; then
    echo "Error: Order ID is required."
    echo "Usage: ./test_fetch_order.sh <order_id> [--output=order.json]"
    exit 1
fi

ORDER_ID="$1"
shift

# Check if output file parameter is provided
if [ "$1" == "--output" ] && [ -n "$2" ]; then
    OUTPUT="$2"
    php bin/test_fetch_order.php $ORDER_ID --output=$OUTPUT
else
    php bin/test_fetch_order.php $ORDER_ID
fi

# Display completion message
echo "----------------------------------------------------"
echo "Test fetch completed at $(date)"
echo "You can review the JSON data to understand the Shopify order structure" 