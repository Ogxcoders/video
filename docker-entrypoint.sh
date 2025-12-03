#!/bin/bash
# Docker Entrypoint Script
# Starts both Redis and Apache with proper health checks

set -e

echo "========================================="
echo " VPS-API Container Starting"
echo "========================================="
echo ""

# Start Redis server in background
echo "🔧 Starting Redis server..."
redis-server /etc/redis/redis.conf &
REDIS_PID=$!

# Wait for Redis to be ready
echo "⏳ Waiting for Redis..."
for i in {1..15}; do
    if redis-cli ping > /dev/null 2>&1; then
        echo "✅ Redis is ready (pid: $REDIS_PID)"
        break
    fi
    if [ $i -eq 15 ]; then
        echo "❌ Redis failed to start within 15 seconds"
        exit 1
    fi
    sleep 1
done

# Display Redis info
echo ""
echo "📊 Redis Status:"
redis-cli info server | grep -E "redis_version|redis_mode|process_id|uptime_in_seconds" || true
echo ""

# Start Background Worker in background
echo "⚙️  Starting Background Worker..."
/usr/local/bin/php /var/www/html/worker.php > /var/www/html/logs/all.log 2>&1 &
WORKER_PID=$!
echo "✅ Worker started (pid: $WORKER_PID)"

# Setup worker health check cron (every 2 minutes)
echo "*/2 * * * * /var/www/html/check-worker-health.sh" | crontab -
service cron start 2>/dev/null || true
echo "⏰ Worker health monitoring enabled"
echo ""

# Start Apache in foreground
echo "🚀 Starting Apache..."
echo "========================================="
echo ""
exec apache2-foreground
