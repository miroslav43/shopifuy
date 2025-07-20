#!/bin/bash

# Sync Log Monitor
# Provides easy ways to monitor hourly sync logs

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
MAIN_LOG="$LOG_DIR/sync-hourly.log"
ERROR_LOG="$LOG_DIR/sync-hourly-error.log"
JS_LOG="$LOG_DIR/js-server.log"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

usage() {
    echo "Sync Log Monitor - Monitor hourly sync logs"
    echo ""
    echo "Usage: $0 [OPTION]"
    echo ""
    echo "Options:"
    echo "  -t, --tail         Follow the main log in real-time"
    echo "  -l, --last         Show last 50 lines of main log"
    echo "  -e, --errors       Show error log"
    echo "  -s, --status       Show sync status summary"
    echo "  -j, --js           Show JavaScript server log"
    echo "  -a, --all          Show all logs"
    echo "  -c, --count        Count successful vs failed syncs"
    echo "  -h, --help         Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 --tail         # Follow logs in real-time"
    echo "  $0 --status       # Quick status overview"
    echo "  $0 --errors       # Check for errors"
}

show_status() {
    echo -e "${BLUE}=== SYNC STATUS SUMMARY ===${NC}"
    
    if [ -f "$MAIN_LOG" ]; then
        echo -e "${GREEN}Main Log:${NC} $(wc -l < "$MAIN_LOG" 2>/dev/null || echo "0") lines"
        echo -e "${GREEN}Last Run:${NC} $(tail -n 20 "$MAIN_LOG" 2>/dev/null | grep "HOURLY SYNC" | tail -1 | cut -d']' -f1 | tr -d '[')"
        
        # Check success/failure counts
        local success=$(grep -c "COMPLETED: SUCCESS" "$MAIN_LOG" 2>/dev/null || echo "0")
        local failed=$(grep -c "COMPLETED: FAILED" "$MAIN_LOG" 2>/dev/null || echo "0")
        
        echo -e "${GREEN}Successful Syncs:${NC} $success"
        if [ "$failed" -gt 0 ]; then
            echo -e "${RED}Failed Syncs:${NC} $failed"
        else
            echo -e "${GREEN}Failed Syncs:${NC} $failed"
        fi
    else
        echo -e "${YELLOW}Main log not found${NC}"
    fi
    
    if [ -f "$ERROR_LOG" ]; then
        local error_count=$(wc -l < "$ERROR_LOG" 2>/dev/null || echo "0")
        if [ "$error_count" -gt 0 ]; then
            echo -e "${RED}Error Log:${NC} $error_count errors"
        else
            echo -e "${GREEN}Error Log:${NC} No errors"
        fi
    else
        echo -e "${GREEN}Error Log:${NC} No errors"
    fi
    
    # Check if cron job is active
    if crontab -l 2>/dev/null | grep -q "sync-hourly.sh"; then
        echo -e "${GREEN}Cron Job:${NC} Active (runs every hour)"
    else
        echo -e "${RED}Cron Job:${NC} Not found"
    fi
    
    # Check JavaScript server
    if curl -s http://localhost:3000/ >/dev/null 2>&1; then
        echo -e "${GREEN}JS Server:${NC} Running on port 3000"
    else
        echo -e "${RED}JS Server:${NC} Not running"
    fi
    
    echo ""
}

show_count() {
    echo -e "${BLUE}=== SYNC STATISTICS ===${NC}"
    
    if [ -f "$MAIN_LOG" ]; then
        local total_runs=$(grep -c "HOURLY SYNC STARTED" "$MAIN_LOG" 2>/dev/null || echo "0")
        local success=$(grep -c "COMPLETED: SUCCESS" "$MAIN_LOG" 2>/dev/null || echo "0")
        local failed=$(grep -c "COMPLETED: FAILED" "$MAIN_LOG" 2>/dev/null || echo "0")
        
        echo "Total Runs: $total_runs"
        echo -e "${GREEN}Successful: $success${NC}"
        echo -e "${RED}Failed: $failed${NC}"
        
        if [ "$total_runs" -gt 0 ]; then
            local success_rate=$((success * 100 / total_runs))
            echo "Success Rate: ${success_rate}%"
        fi
        
        # Show recent activity
        echo ""
        echo "Recent Activity (last 5 runs):"
        grep "COMPLETED:" "$MAIN_LOG" 2>/dev/null | tail -5 | while IFS= read -r line; do
            if [[ $line == *"SUCCESS"* ]]; then
                echo -e "${GREEN}✓${NC} $line"
            else
                echo -e "${RED}✗${NC} $line"
            fi
        done
    else
        echo "No log data available"
    fi
    
    echo ""
}

# Parse command line arguments
case "${1:-}" in
    -t|--tail)
        echo "Following sync logs (Ctrl+C to exit)..."
        tail -f "$MAIN_LOG" 2>/dev/null || echo "Log file not found"
        ;;
    -l|--last)
        echo -e "${BLUE}=== LAST 50 LINES ===${NC}"
        tail -50 "$MAIN_LOG" 2>/dev/null || echo "Log file not found"
        ;;
    -e|--errors)
        echo -e "${BLUE}=== ERROR LOG ===${NC}"
        if [ -f "$ERROR_LOG" ]; then
            cat "$ERROR_LOG"
        else
            echo "No errors found"
        fi
        ;;
    -s|--status)
        show_status
        ;;
    -j|--js)
        echo -e "${BLUE}=== JAVASCRIPT SERVER LOG ===${NC}"
        if [ -f "$JS_LOG" ]; then
            tail -50 "$JS_LOG"
        else
            echo "JavaScript server log not found"
        fi
        ;;
    -a|--all)
        show_status
        echo -e "${BLUE}=== MAIN LOG (last 30 lines) ===${NC}"
        tail -30 "$MAIN_LOG" 2>/dev/null || echo "Main log not found"
        echo ""
        if [ -f "$ERROR_LOG" ] && [ -s "$ERROR_LOG" ]; then
            echo -e "${BLUE}=== ERROR LOG ===${NC}"
            cat "$ERROR_LOG"
            echo ""
        fi
        ;;
    -c|--count)
        show_count
        ;;
    -h|--help)
        usage
        ;;
    "")
        show_status
        ;;
    *)
        echo "Unknown option: $1"
        echo "Use --help for usage information"
        exit 1
        ;;
esac 