#!/bin/bash
# PowerBody Dropshipping API Sync Script for Orders Only
# This script runs only order synchronization process

# Set script directory as working directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR"

# Display start message
echo "Starting PowerBody Orders Sync Process at $(date)"
echo "----------------------------------------------------"

# Run the sync script with 'orders' parameter
echo "Running orders sync..."
php bin/sync.php orders

# Get the exit code
EXIT_CODE=$?

# Display completion message
echo "----------------------------------------------------"
if [ $EXIT_CODE -eq 0 ]; then
    echo "Orders sync completed successfully at $(date)"
else
    echo "Orders sync encountered errors. Please check the logs for details."
fi

# Display statistics for orders
echo "Generating order statistics report..."
php bin/sync-stats.php -t order -d 1

# Exit with the PHP script's exit code
exit $EXIT_CODE 