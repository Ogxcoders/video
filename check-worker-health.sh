#!/bin/bash
# Worker Health Check Script
# Checks if worker is running and restarts if needed

WORKER_SCRIPT="/var/www/html/worker.php"
LOG_FILE="/var/www/html/logs/all.log"

# Create log directory if needed
mkdir -p "$(dirname "$LOG_FILE")"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Check if worker is running
if pgrep -f "$WORKER_SCRIPT" > /dev/null; then
    log "Worker is running (PID: $(pgrep -f "$WORKER_SCRIPT"))"
    exit 0
else
    log "Worker is not running - attempting to start"
    
    # Start worker in background
    /usr/local/bin/php "$WORKER_SCRIPT" >> /var/www/html/logs/all.log 2>&1 &
    NEW_PID=$!
    
    # Wait a moment for startup
    sleep 2
    
    # Verify it started
    if pgrep -f "$WORKER_SCRIPT" > /dev/null; then
        log "Worker started successfully (PID: $NEW_PID)"
        exit 0
    else
        log "ERROR: Failed to start worker"
        exit 1
    fi
fi
