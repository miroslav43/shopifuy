#!/bin/bash

# Hourly Sync Cron Wrapper
# This script is designed to run from cron every hour
# It ensures proper environment setup and comprehensive logging

# Set strict error handling
set -euo pipefail

# Get script directory (absolute path)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
cd "$SCRIPT_DIR"

# Configuration
LOG_DIR="$SCRIPT_DIR/logs"
MAIN_LOG="$LOG_DIR/sync-hourly.log"
ERROR_LOG="$LOG_DIR/sync-hourly-error.log"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# Ensure logs directory exists
mkdir -p "$LOG_DIR"

# Logging function
log() {
    echo "[$TIMESTAMP] $1" | tee -a "$MAIN_LOG"
}

# Error logging function  
error_log() {
    echo "[$TIMESTAMP] ERROR: $1" | tee -a "$ERROR_LOG" >&2
}

# Trap errors
trap 'error_log "Script failed at line $LINENO"' ERR

# Start logging
log "========================================"
log "HOURLY SYNC STARTED"
log "========================================"
log "Working directory: $SCRIPT_DIR"
log "User: $(whoami)"
log "Environment: $(uname -a)"

# Source environment variables (cron has minimal environment)
if [ -f "$SCRIPT_DIR/.env" ]; then
    source "$SCRIPT_DIR/.env"
    log "Loaded environment from .env file"
else
    error_log ".env file not found in $SCRIPT_DIR"
    exit 1
fi

# Add Node.js to PATH if needed (cron might not have it)
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"
if command -v node >/dev/null 2>&1; then
    log "Node.js version: $(node --version)"
else
    error_log "Node.js not found in PATH"
    exit 1
fi

# Check if JavaScript server is running
if ! curl -s http://localhost:3000/ >/dev/null 2>&1; then
    log "JavaScript server not running, attempting to start..."
    
    # Start JavaScript server in background
    cd "$SCRIPT_DIR/FetchOrdersJava"
    nohup node server.js > "$LOG_DIR/js-server.log" 2>&1 &
    JS_PID=$!
    
    # Wait for server to start
    sleep 10
    
    # Check if server started successfully
    if curl -s http://localhost:3000/ >/dev/null 2>&1; then
        log "JavaScript server started successfully (PID: $JS_PID)"
    else
        error_log "Failed to start JavaScript server"
        exit 1
    fi
else
    log "JavaScript server is already running"
fi

# Go back to main directory
cd "$SCRIPT_DIR"

# Run the sync with full output capture
log "Starting sync process..."
if ./sync-quick.sh 2>&1 | tee -a "$MAIN_LOG"; then
    log "Sync completed successfully"
    SYNC_STATUS="SUCCESS"
else
    error_log "Sync failed with exit code $?"
    SYNC_STATUS="FAILED"
fi

# Log completion
log "========================================"
log "HOURLY SYNC COMPLETED: $SYNC_STATUS"
log "========================================"
log ""

# Rotate logs if they get too large (keep last 1000 lines)
if [ -f "$MAIN_LOG" ] && [ $(wc -l < "$MAIN_LOG") -gt 1000 ]; then
    tail -1000 "$MAIN_LOG" > "$MAIN_LOG.tmp" && mv "$MAIN_LOG.tmp" "$MAIN_LOG"
    log "Log rotated to keep last 1000 lines"
fi

# Exit with appropriate code
if [ "$SYNC_STATUS" = "SUCCESS" ]; then
    exit 0
else
    exit 1
fi 