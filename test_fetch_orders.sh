#!/bin/bash
# Shopify Order Test Fetch Script
# This script fetches all orders from Shopify and saves the complete raw data to a JSON file

# Display start message
echo "Starting Shopify Orders Test Fetch at $(date)"
echo "----------------------------------------------------"

# Check if days parameter is provided
if [ "$1" == "--days" ] && [ -n "$2" ]; then
    DAYS="$2"
    shift 2
else
    DAYS=30
fi

# Check if output file parameter is provided
if [ "$1" == "--output" ] && [ -n "$2" ]; then
    OUTPUT="$2"
    php bin/test_fetch_orders.php --days=$DAYS --output=$OUTPUT
else
    php bin/test_fetch_orders.php --days=$DAYS
fi

# Display completion message
echo "----------------------------------------------------"
echo "Test fetch completed at $(date)"
echo "You can review the JSON data to understand the Shopify order structure" 