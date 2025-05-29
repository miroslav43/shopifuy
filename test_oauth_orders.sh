#!/bin/bash

# Shopify OAuth Order Test Script
# Usage: ./test_oauth_orders.sh

echo "=== Shopify OAuth Order Test ==="
echo ""

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "✗ PHP is not available in PATH"
    echo "Please install PHP or add it to your PATH"
    exit 1
fi

echo "✓ PHP is available"
echo ""

echo "This script will help you test OAuth authentication with your Partner app."
echo ""

# Get store domain
read -p "Enter your store domain (e.g., your-store.myshopify.com): " store_domain

if [ -z "$store_domain" ]; then
    echo "Error: Store domain is required"
    exit 1
fi

echo ""
echo "Choose an option:"
echo "1. Get OAuth access token (first time setup)"
echo "2. Test order fetching with existing token"
echo ""

read -p "Enter your choice (1 or 2): " choice

case $choice in
    1)
        echo ""
        echo "=== Getting OAuth Access Token ==="
        echo ""
        
        # Run the OAuth token helper
        php bin/get_oauth_token.php --store="$store_domain"
        
        if [ $? -eq 0 ]; then
            echo ""
            echo "✓ OAuth token setup completed!"
            echo "You can now run option 2 to test order fetching."
        else
            echo "✗ OAuth token setup failed"
        fi
        ;;
    
    2)
        echo ""
        echo "=== Testing Order Fetching ==="
        echo ""
        
        # Ask for number of days
        read -p "Number of days to fetch orders (default: 30): " days
        if [ -z "$days" ]; then
            days="30"
        fi
        
        # Run the OAuth order fetcher
        php bin/test_fetch_orders_oauth.php --store="$store_domain" --days="$days"
        
        if [ $? -eq 0 ]; then
            echo ""
            echo "✓ Order fetching completed!"
            echo "Check the storage/ directory for the output JSON file."
        else
            echo "✗ Order fetching failed"
        fi
        ;;
    
    *)
        echo "Invalid choice. Please run the script again and choose 1 or 2."
        exit 1
        ;;
esac

echo ""
echo "=== Script Complete ===" 