#!/bin/bash
# Dead Letter Retry Script
# This script attempts to reprocess orders that failed to sync to PowerBody

# Display start message
echo "Starting Dead Letter Retry Process at $(date)"
echo "----------------------------------------------------"

# Run the retry script with all passed arguments
php bin/retry-dead-letters.php "$@"

# Display completion message
echo "----------------------------------------------------"
echo "Retry process completed at $(date)"
echo "Check the logs for any errors or warnings" 