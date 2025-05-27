#!/bin/bash
# PowerBody Dropshipping API Sync Script for Products
# This script runs only product synchronization process

# Set script directory as working directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR"

# Display start message
echo "Starting PowerBody Products Sync Process at $(date)"
echo "----------------------------------------------------"

# Run the sync script with 'products' parameter
echo "Running products sync..."
php bin/sync.php products

# Get the exit code
EXIT_CODE=$?

# Display completion message
echo "----------------------------------------------------"
if [ $EXIT_CODE -eq 0 ]; then
    echo "Products sync completed successfully at $(date)"
else
    echo "Products sync encountered errors. Please check the logs for details."
fi

# Display statistics for products
echo "Generating product statistics report..."
php bin/sync-stats.php -t product -d 7

# Exit with the PHP script's exit code
exit $EXIT_CODE 