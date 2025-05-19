#!/bin/bash

# Default values
SYNC_TYPE="all"
WORKER_COUNT=4

# Parse command line arguments
while [[ $# -gt 0 ]]; do
  case $1 in
    -t|--type)
      SYNC_TYPE="$2"
      shift 2
      ;;
    -w|--workers)
      WORKER_COUNT="$2"
      shift 2
      ;;
    -h|--help)
      echo "Usage: $0 [options]"
      echo "Options:"
      echo "  -t, --type TYPE      Type of sync to run: all, products, orders (default: all)"
      echo "  -w, --workers COUNT  Number of worker processes to use (default: 4)"
      echo "  -h, --help           Display this help message"
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      exit 1
      ;;
  esac
done

# Validate sync type
if [[ "$SYNC_TYPE" != "all" && "$SYNC_TYPE" != "products" && "$SYNC_TYPE" != "orders" ]]; then
  echo "Error: Invalid sync type. Must be one of: all, products, orders"
  exit 1
fi

# Validate worker count
if ! [[ "$WORKER_COUNT" =~ ^[0-9]+$ ]] || [ "$WORKER_COUNT" -lt 1 ] || [ "$WORKER_COUNT" -gt 16 ]; then
  echo "Error: Worker count must be an integer between 1 and 16"
  exit 1
fi

# Set the current directory to the script's directory
cd "$(dirname "$0")"

echo "Starting parallel sync with the following configuration:"
echo "  Sync Type: $SYNC_TYPE"
echo "  Worker Count: $WORKER_COUNT"
echo "=================================================="

# Run the sync script with worker parameters
php sync.php "$SYNC_TYPE" --workers="$WORKER_COUNT"

exit_code=$?

if [ $exit_code -eq 0 ]; then
  echo "=================================================="
  echo "Parallel sync completed successfully!"
else
  echo "=================================================="
  echo "Parallel sync failed with exit code: $exit_code"
fi

exit $exit_code 