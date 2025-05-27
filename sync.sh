#!/bin/bash
# PowerBody Dropshipping API Sync Script
# This script runs all synchronization processes: products, orders, comments, and returns

# Set script directory as working directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR"

# Display start message
echo "Starting PowerBody Dropshipping Sync Process at $(date)"
echo "----------------------------------------------------"

# Run the sync script with 'all' parameter
php bin/sync.php all

# Get the exit code
EXIT_CODE=$?

# Display completion message
echo "----------------------------------------------------"
if [ $EXIT_CODE -eq 0 ]; then
    echo "Sync process completed successfully at $(date)"
else
    echo "Sync process encountered errors. Please check the logs for details."
fi

# Exit with the PHP script's exit code
exit $EXIT_CODE 