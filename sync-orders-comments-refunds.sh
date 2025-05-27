#!/bin/bash
# PowerBody Dropshipping API Sync Script for Orders, Comments and Refunds
# This script runs orders, comments, and returns synchronization processes

# Set script directory as working directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR"

# Display start message
echo "Starting PowerBody Partial Sync Process at $(date)"
echo "----------------------------------------------------"

# Run sync.php for orders
echo "Running orders sync..."
php bin/sync.php orders
ORDERS_EXIT=$?

# Run sync.php for comments
echo "Running comments sync..."
php bin/sync.php comments
COMMENTS_EXIT=$?

# Run sync.php for returns
echo "Running returns sync..."
php bin/sync.php returns
RETURNS_EXIT=$?

# Calculate final exit code - if any sync failed, we'll exit with an error
if [ $ORDERS_EXIT -ne 0 ] || [ $COMMENTS_EXIT -ne 0 ] || [ $RETURNS_EXIT -ne 0 ]; then
    FINAL_EXIT=1
else
    FINAL_EXIT=0
fi

# Display completion message
echo "----------------------------------------------------"
echo "Sync results:"
echo "Orders sync: $([ $ORDERS_EXIT -eq 0 ] && echo 'SUCCESS' || echo 'FAILED')"
echo "Comments sync: $([ $COMMENTS_EXIT -eq 0 ] && echo 'SUCCESS' || echo 'FAILED')"
echo "Returns sync: $([ $RETURNS_EXIT -eq 0 ] && echo 'SUCCESS' || echo 'FAILED')"
echo "----------------------------------------------------"
echo "Sync process completed at $(date)"
echo "Check the logs for any errors or warnings"

# Exit with appropriate code
exit $FINAL_EXIT 