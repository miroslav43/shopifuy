#!/bin/bash

# Dead Letter Retry Script
# Retries the latest failed order from the dead letter queue

set -e  # Exit on any error

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')] ✅ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] ⚠️  $1${NC}"
}

log_error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ❌ $1${NC}"
}

# Check if we're in the correct directory
if [ ! -f "$PROJECT_ROOT/composer.json" ]; then
    log_error "Error: Not in project root directory. Please run from the shopifuy project root."
    exit 1
fi

# Check if PHP script exists
PHP_SCRIPT="$PROJECT_ROOT/bin/retry-dead-letter.php"
if [ ! -f "$PHP_SCRIPT" ]; then
    log_error "Error: PHP script not found at $PHP_SCRIPT"
    exit 1
fi

# Check if storage directory exists
STORAGE_DIR="$PROJECT_ROOT/storage"
if [ ! -d "$STORAGE_DIR" ]; then
    log_error "Error: Storage directory not found at $STORAGE_DIR"
    exit 1
fi

# Count available dead letter files
DEAD_LETTER_COUNT=$(find "$STORAGE_DIR" -name "dead_letter_order_*.json" -not -name "*.processed*" -not -name "*.failed*" | wc -l)

log "=== Dead Letter Retry Script ==="
log "Project Root: $PROJECT_ROOT"
log "Storage Directory: $STORAGE_DIR"
log "Available dead letter files: $DEAD_LETTER_COUNT"

if [ "$DEAD_LETTER_COUNT" -eq 0 ]; then
    log_warning "No dead letter files available for retry"
    exit 0
fi

# Show the latest dead letter file that will be processed
LATEST_FILE=$(find "$STORAGE_DIR" -name "dead_letter_order_*.json" -not -name "*.processed*" -not -name "*.failed*" -type f -printf '%T@ %p\n' | sort -nr | head -1 | cut -d' ' -f2)
if [ -n "$LATEST_FILE" ]; then
    log "Latest dead letter file: $(basename "$LATEST_FILE")"
    log "File size: $(du -h "$LATEST_FILE" | cut -f1)"
    log "Modified: $(date -r "$LATEST_FILE" '+%Y-%m-%d %H:%M:%S')"
fi

# Ask for confirmation if running interactively
if [ -t 0 ]; then
    echo
    read -p "Do you want to proceed with retrying the latest dead letter file? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log "Operation cancelled by user"
        exit 0
    fi
fi

log "Starting PHP retry process..."

# Run the PHP script and capture output
if php "$PHP_SCRIPT"; then
    RETRY_EXIT_CODE=$?
    log_success "PHP retry script completed successfully"
    
    # Count remaining dead letter files
    REMAINING_COUNT=$(find "$STORAGE_DIR" -name "dead_letter_order_*.json" -not -name "*.processed*" -not -name "*.failed*" | wc -l)
    log "Remaining dead letter files: $REMAINING_COUNT"
    
    if [ "$REMAINING_COUNT" -gt 0 ]; then
        log_warning "There are still $REMAINING_COUNT dead letter files that can be retried"
        log "Run this script again to retry the next file, or use retry-all-dead-letters.sh for bulk processing"
    else
        log_success "All dead letter files have been processed!"
    fi
    
else
    RETRY_EXIT_CODE=$?
    log_error "PHP retry script failed with exit code: $RETRY_EXIT_CODE"
    
    # Show recent logs for debugging
    LOG_FILE="$PROJECT_ROOT/logs/app.log"
    if [ -f "$LOG_FILE" ]; then
        log "Recent log entries (last 10 lines):"
        echo "----------------------------------------"
        tail -10 "$LOG_FILE"
        echo "----------------------------------------"
    fi
    
    exit $RETRY_EXIT_CODE
fi

log "Dead letter retry process completed"
exit 0 