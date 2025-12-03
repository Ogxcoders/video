#!/bin/bash
# Redis Startup and Verification Script
# This script ensures Redis starts properly and is ready to accept connections

set -e  # Exit on error

echo "========================================="
echo " Redis Startup Verification"
echo "========================================="
echo ""

# Start Redis in background
echo "Starting Redis server..."
redis-server /etc/redis/redis.conf &
REDIS_PID=$!

# Wait for Redis to start
echo "Waiting for Redis to be ready..."
sleep 2

# Check if Redis process is running
if ! ps -p $REDIS_PID > /dev/null 2>&1; then
    echo "❌ ERROR: Redis process failed to start!"
    echo "Check logs for details"
    exit 1
fi

# Test Redis connection
MAX_RETRIES=10
RETRY_COUNT=0

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
    if redis-cli ping > /dev/null 2>&1; then
        echo "✅ Redis is ready and accepting connections"
        redis-cli info server | grep redis_version
        echo ""
        echo "Redis startup successful!"
        echo "========================================="
        exit 0
    fi
    
    RETRY_COUNT=$((RETRY_COUNT + 1))
    echo "Attempt $RETRY_COUNT/$MAX_RETRIES - Redis not ready yet..."
    sleep 1
done

echo "❌ ERROR: Redis failed to start after $MAX_RETRIES attempts"
echo "Check Redis logs for details"
exit 1
