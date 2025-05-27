#!/bin/bash
# Shopify to PowerBody Order Sync Script
# This script synchronizes unfulfilled orders from Shopify to PowerBody

# Display start message
echo "Starting Shopify to PowerBody Order Sync at $(date)"
echo "----------------------------------------------------"

# Run the sync script
php bin/sync.php orders

# Display completion message
echo "----------------------------------------------------"
echo "Order sync completed at $(date)"
echo "Check the logs for any errors or warnings" 