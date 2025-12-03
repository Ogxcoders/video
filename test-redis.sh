#!/bin/bash
#
# Redis Queue Test Script
# Runs comprehensive Redis queue tests inside the Docker container
#
# Usage:
#   ./test-redis.sh                    # Run from host (auto-detects container)
#   docker exec <container> bash /var/www/html/test-redis.sh  # Run directly
#

echo "========================================"
echo " Redis Queue Test Script"
echo "========================================"
echo ""

# Check if running inside container or on host
if [ -f /.dockerenv ]; then
    echo "✓ Running inside Docker container"
    echo ""
    
    # Run tests directly
    php /var/www/html/run-tests.php
    
else
    echo "⚠️  Running on host machine"
    echo ""
    
    # Try to find the container
    CONTAINER=$(docker ps --filter "ancestor=*vps-api*" --format "{{.Names}}" | head -n 1)
    
    if [ -z "$CONTAINER" ]; then
        # Try alternative search
        CONTAINER=$(docker ps --filter "name=*vps*" --filter "name=*api*" --format "{{.Names}}" | head -n 1)
    fi
    
    if [ -z "$CONTAINER" ]; then
        echo "❌ Could not find running VPS-API container"
        echo ""
        echo "Available containers:"
        docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}"
        echo ""
        echo "Please run manually:"
        echo "  docker exec <container-name> php /var/www/html/run-tests.php"
        exit 1
    fi
    
    echo "📦 Found container: $CONTAINER"
    echo ""
    echo "Running tests inside container..."
    echo "========================================"
    echo ""
    
    docker exec "$CONTAINER" php /var/www/html/run-tests.php
    
fi
