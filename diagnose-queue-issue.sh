#!/bin/bash
#
# Quick Diagnostic Script for Queue Issues
# Run this inside your Docker container to diagnose why jobs aren't being processed
#

echo "=========================================="
echo "  Queue System Diagnostic Tool"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check 1: Redis Connection
echo "1. Checking Redis connection..."
if redis-cli ping > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Redis is running${NC}"
else
    echo -e "${RED}❌ Redis is NOT running${NC}"
    echo "   Fix: Check if Redis service is started"
fi
echo ""

# Check 2: Queue Length
echo "2. Checking queue status..."
QUEUE_LENGTH=$(redis-cli LLEN compression_queue 2>/dev/null || echo "0")
echo "   Queue length: $QUEUE_LENGTH jobs"

if [ "$QUEUE_LENGTH" -eq 0 ]; then
    echo -e "${YELLOW}⚠️  Queue is EMPTY - no jobs waiting${NC}"
    echo "   This means WordPress is not sending compression requests"
else
    echo -e "${GREEN}✅ Queue has $QUEUE_LENGTH pending job(s)${NC}"
fi
echo ""

# Check 3: Worker Process
echo "3. Checking worker process..."
if pgrep -f worker.php > /dev/null; then
    WORKER_PID=$(pgrep -f worker.php)
    echo -e "${GREEN}✅ Worker is running (PID: $WORKER_PID)${NC}"
else
    echo -e "${RED}❌ Worker is NOT running${NC}"
    echo "   Fix: Start worker with: php worker.php"
fi
echo ""

# Check 4: Recent Log Activity
echo "4. Checking recent log activity..."
if [ -f /var/www/html/logs/all.log ]; then
    LOG_LINES=$(wc -l < /var/www/html/logs/all.log)
    echo "   Log file exists: $LOG_LINES lines"
    
    # Check for COMPRESS entries (API requests)
    COMPRESS_COUNT=$(grep -c "\[COMPRESS\]" /var/www/html/logs/all.log 2>/dev/null || echo "0")
    echo "   Compression API calls: $COMPRESS_COUNT"
    
    if [ "$COMPRESS_COUNT" -eq 0 ]; then
        echo -e "${RED}❌ NO compress.php requests received${NC}"
        echo "   This is the root cause - WordPress is not sending requests"
    else
        echo -e "${GREEN}✅ Received $COMPRESS_COUNT API request(s)${NC}"
    fi
    
    # Check for WORKER entries
    WORKER_COUNT=$(grep -c "\[WORKER\]" /var/www/html/logs/all.log 2>/dev/null || echo "0")
    echo "   Worker log entries: $WORKER_COUNT"
else
    echo -e "${YELLOW}⚠️  Log file not found${NC}"
fi
echo ""

# Check 5: API Configuration
echo "5. Checking API configuration..."
if [ -f /var/www/html/config.php ]; then
    echo -e "${GREEN}✅ config.php exists${NC}"
    
    # Check if API key is set
    if grep -q "CHANGE_ME_TO_A_SECURE_RANDOM_KEY" /var/www/html/config.php 2>/dev/null; then
        echo -e "${RED}❌ API key is not configured (still default)${NC}"
    else
        echo -e "${GREEN}✅ API key is configured${NC}"
    fi
else
    echo -e "${RED}❌ config.php not found${NC}"
fi
echo ""

# Check 6: Redis Job History
echo "6. Checking Redis job statistics..."
COMPLETED=$(redis-cli HGET queue:stats completed 2>/dev/null || echo "0")
FAILED=$(redis-cli HGET queue:stats failed 2>/dev/null || echo "0")
echo "   Completed jobs: $COMPLETED"
echo "   Failed jobs: $FAILED"
echo ""

# Summary and Recommendations
echo "=========================================="
echo "  DIAGNOSIS SUMMARY"
echo "=========================================="
echo ""

if [ "$QUEUE_LENGTH" -eq 0 ] && [ "$COMPRESS_COUNT" -eq 0 ]; then
    echo -e "${YELLOW}ROOT CAUSE IDENTIFIED:${NC}"
    echo "  WordPress is NOT sending compression requests to the API"
    echo ""
    echo "RECOMMENDED FIXES:"
    echo "  1. Check WordPress plugin settings:"
    echo "     - API Endpoint should be: https://v.ogtemplate.com/compress.php"
    echo "     - API Key must match your Docker environment variable"
    echo ""
    echo "  2. Test WordPress connection:"
    echo "     - Go to WordPress → Video Processor → Settings"
    echo "     - Click 'Test Connection' button"
    echo ""
    echo "  3. Manually test queue system:"
    echo "     php /var/www/html/test-enqueue-job.php"
    echo ""
    echo "  4. Monitor for incoming requests:"
    echo "     tail -f /var/www/html/logs/all.log | grep COMPRESS"
    echo ""
elif [ "$QUEUE_LENGTH" -gt 0 ]; then
    echo -e "${GREEN}Queue has jobs pending${NC}"
    echo "  Worker should be processing them now"
    echo "  Monitor: tail -f /var/www/html/logs/all.log"
else
    echo "System appears operational but idle"
    echo "  No jobs in queue and worker is waiting"
    echo "  This is normal if no videos are being uploaded"
fi

echo ""
echo "For detailed troubleshooting, see:"
echo "  /var/www/html/QUEUE-TROUBLESHOOTING.md"
echo ""
