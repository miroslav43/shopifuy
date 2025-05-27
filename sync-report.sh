#!/bin/bash
# PowerBody Dropshipping API Statistics Report Generator
# This script generates comprehensive statistics reports

# Set script directory as working directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR"

# Parse command line arguments
DAYS=7
TYPE="all"
OUTPUT_FILE=""

# Process command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -d|--days)
            DAYS="$2"
            shift 2
            ;;
        -t|--type)
            TYPE="$2"
            shift 2
            ;;
        -o|--output)
            OUTPUT_FILE="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [options]"
            echo "Options:"
            echo "  -d, --days DAYS    Number of days to look back [default: 7]"
            echo "  -t, --type TYPE    Type of stats to show (all, product, order, comment, refund) [default: all]"
            echo "  -o, --output FILE  Output file to save the report [default: display to console]"
            echo "  -h, --help         Show this help message"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help to see available options"
            exit 1
            ;;
    esac
done

# Set the title based on the type
if [ "$TYPE" == "all" ]; then
    TITLE="All Synchronization Types"
elif [ "$TYPE" == "product" ]; then
    TITLE="Product Synchronization"
elif [ "$TYPE" == "order" ]; then
    TITLE="Order Synchronization"
elif [ "$TYPE" == "comment" ]; then
    TITLE="Comment Synchronization"
elif [ "$TYPE" == "refund" ]; then
    TITLE="Refund/Return Synchronization"
else
    echo "Invalid type: $TYPE"
    echo "Valid types are: all, product, order, comment, refund"
    exit 1
fi

# Generate the report
REPORT_CONTENT=$(cat << EOF
# PowerBody Dropshipping API Statistics Report
## ${TITLE} - Last ${DAYS} Days
Generated at: $(date)

EOF
)

# If output file is specified, write to file, otherwise display to console
if [ -n "$OUTPUT_FILE" ]; then
    echo "$REPORT_CONTENT" > "$OUTPUT_FILE"
    php bin/sync-stats.php -t "$TYPE" -d "$DAYS" >> "$OUTPUT_FILE"
    echo "Report saved to $OUTPUT_FILE"
else
    echo "$REPORT_CONTENT"
    php bin/sync-stats.php -t "$TYPE" -d "$DAYS"
fi

exit 0 