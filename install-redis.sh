#!/bin/bash
###############################################################################
# Redis Installation Script for VPS/Coolify Deployment
# Installs Redis server and PHP Redis extension
###############################################################################

set -e  # Exit on error

echo "=========================================="
echo "  Redis Installation Script"
echo "=========================================="
echo ""

# Detect if running as root
if [ "$EUID" -eq 0 ]; then
    SUDO=""
    echo "✓ Running as root"
else
    SUDO="sudo"
    echo "✓ Running with sudo"
fi

# Check OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
    echo "✓ Detected OS: $OS"
else
    echo "✗ Cannot detect OS"
    exit 1
fi

echo ""
echo "=========================================="
echo "  Step 1: Installing Redis Server"
echo "=========================================="

if command -v redis-server &> /dev/null; then
    echo "✓ Redis server already installed"
    redis-server --version
else
    echo "Installing Redis server..."
    
    if [ "$OS" = "ubuntu" ] || [ "$OS" = "debian" ]; then
        $SUDO apt-get update
        $SUDO apt-get install -y redis-server
    elif [ "$OS" = "centos" ] || [ "$OS" = "rhel" ] || [ "$OS" = "fedora" ]; then
        $SUDO yum install -y redis
    elif [ "$OS" = "alpine" ]; then
        $SUDO apk add --no-cache redis
    else
        echo "✗ Unsupported OS: $OS"
        exit 1
    fi
    
    echo "✓ Redis server installed"
    redis-server --version
fi

echo ""
echo "=========================================="
echo "  Step 2: Installing PHP Redis Extension"
echo "=========================================="

if php -m 2>/dev/null | grep -q "^redis$"; then
    echo "✓ PHP Redis extension already installed"
else
    echo "Installing PHP Redis extension..."
    
    if [ "$OS" = "ubuntu" ] || [ "$OS" = "debian" ]; then
        # Detect PHP version
        PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || echo "8.1")
        $SUDO apt-get install -y php${PHP_VERSION}-redis || $SUDO apt-get install -y php-redis
    elif [ "$OS" = "centos" ] || [ "$OS" = "rhel" ] || [ "$OS" = "fedora" ]; then
        $SUDO yum install -y php-redis
    elif [ "$OS" = "alpine" ]; then
        $SUDO apk add --no-cache php81-redis
    else
        echo "⚠ Warning: Cannot auto-install PHP Redis extension for $OS"
        echo "   Please install manually: https://github.com/phpredis/phpredis"
    fi
    
    if php -m 2>/dev/null | grep -q "^redis$"; then
        echo "✓ PHP Redis extension installed"
    else
        echo "⚠ PHP Redis extension installation may have failed"
        echo "   Verify with: php -m | grep redis"
    fi
fi

echo ""
echo "=========================================="
echo "  Step 3: Configuring Redis"
echo "=========================================="

REDIS_CONF="/etc/redis/redis.conf"
REDIS_CONF_ALT="/etc/redis.conf"

# Find Redis config file
if [ -f "$REDIS_CONF" ]; then
    REDIS_CONFIG="$REDIS_CONF"
elif [ -f "$REDIS_CONF_ALT" ]; then
    REDIS_CONFIG="$REDIS_CONF_ALT"
else
    echo "⚠ Redis config file not found, will create custom config"
    REDIS_CONFIG="/etc/redis/custom-redis.conf"
    $SUDO mkdir -p /etc/redis
fi

echo "✓ Using config file: $REDIS_CONFIG"

# Backup existing config
if [ -f "$REDIS_CONFIG" ]; then
    $SUDO cp "$REDIS_CONFIG" "${REDIS_CONFIG}.backup-$(date +%Y%m%d-%H%M%S)"
    echo "✓ Backed up existing config"
fi

# Apply recommended settings
echo "Applying recommended Redis settings..."

$SUDO tee "$REDIS_CONFIG" > /dev/null <<'EOF'
# Redis Configuration for Video Compression Queue
# Optimized for job queue with persistence

# Network
bind 127.0.0.1 ::1
port 6379
timeout 300
tcp-keepalive 300
tcp-backlog 511

# General
daemonize no
supervised no
pidfile /var/run/redis/redis-server.pid
loglevel notice
logfile /var/log/redis/redis-server.log

# Snapshotting (RDB)
save 900 1
save 300 10
save 60 10000
stop-writes-on-bgsave-error yes
rdbcompression yes
rdbchecksum yes
dbfilename dump.rdb
dir /var/lib/redis

# Replication
replica-serve-stale-data yes
replica-read-only yes

# Security
# requirepass your_password_here  # Uncomment and set in production

# Memory Management
maxmemory 512mb
maxmemory-policy noeviction

# Append Only File (AOF) - For crash recovery
appendonly yes
appendfilename "appendonly.aof"
appendfsync everysec
no-appendfsync-on-rewrite no
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb
aof-load-truncated yes
aof-use-rdb-preamble yes

# Slow Log
slowlog-log-slower-than 10000
slowlog-max-len 128

# Advanced
hash-max-ziplist-entries 512
hash-max-ziplist-value 64
list-max-ziplist-size -2
set-max-intset-entries 512
zset-max-ziplist-entries 128
zset-max-ziplist-value 64
activerehashing yes
client-output-buffer-limit normal 0 0 0
client-output-buffer-limit replica 256mb 64mb 60
client-output-buffer-limit pubsub 32mb 8mb 60
hz 10
aof-rewrite-incremental-fsync yes
EOF

echo "✓ Redis configuration updated"

echo ""
echo "=========================================="
echo "  Step 4: Creating Required Directories"
echo "=========================================="

$SUDO mkdir -p /var/lib/redis
$SUDO mkdir -p /var/log/redis
$SUDO mkdir -p /var/run/redis

# Set permissions
if command -v redis &> /dev/null || id redis &> /dev/null 2>&1; then
    $SUDO chown -R redis:redis /var/lib/redis /var/log/redis /var/run/redis 2>/dev/null || true
    echo "✓ Set ownership to redis user"
else
    echo "⚠ Redis user not found, skipping chown"
fi

$SUDO chmod 755 /var/lib/redis /var/log/redis /var/run/redis

echo "✓ Directories created and configured"

echo ""
echo "=========================================="
echo "  Step 5: Starting Redis Service"
echo "=========================================="

# Try systemd first
if command -v systemctl &> /dev/null; then
    $SUDO systemctl enable redis-server 2>/dev/null || $SUDO systemctl enable redis 2>/dev/null || true
    $SUDO systemctl restart redis-server 2>/dev/null || $SUDO systemctl restart redis 2>/dev/null || true
    
    if $SUDO systemctl is-active --quiet redis-server 2>/dev/null || $SUDO systemctl is-active --quiet redis 2>/dev/null; then
        echo "✓ Redis service started (systemd)"
    else
        echo "⚠ Failed to start via systemd, trying manual start..."
        $SUDO redis-server "$REDIS_CONFIG" --daemonize yes 2>/dev/null || true
    fi
else
    # Manual start
    echo "Starting Redis manually..."
    $SUDO redis-server "$REDIS_CONFIG" --daemonize yes 2>/dev/null || true
fi

# Wait for Redis to start
sleep 2

echo ""
echo "=========================================="
echo "  Step 6: Verifying Installation"
echo "=========================================="

if redis-cli ping 2>/dev/null | grep -q "PONG"; then
    echo "✓ Redis server is running"
    echo ""
    redis-cli INFO server | grep "redis_version"
    redis-cli INFO persistence | grep "aof_enabled\|rdb_last_save_time"
else
    echo "✗ Redis server is not responding"
    echo "   Try starting manually: sudo systemctl start redis-server"
    exit 1
fi

if php -m 2>/dev/null | grep -q "^redis$"; then
    echo "✓ PHP Redis extension is loaded"
    php -r "echo 'PHP Version: ' . PHP_VERSION . PHP_EOL;"
else
    echo "⚠ PHP Redis extension is not loaded"
    echo "   Check php.ini and restart PHP-FPM/Apache"
fi

echo ""
echo "=========================================="
echo "  Installation Complete!"
echo "=========================================="
echo ""
echo "Redis Status:"
echo "  - Server: Running on 127.0.0.1:6379"
echo "  - Persistence: AOF + RDB enabled"
echo "  - Config: $REDIS_CONFIG"
echo "  - Data Dir: /var/lib/redis"
echo "  - Log: /var/log/redis/redis-server.log"
echo ""
echo "Next Steps:"
echo "  1. Test queue: php /path/to/test-redis-queue.php"
echo "  2. Monitor: redis-cli MONITOR"
echo "  3. Stats: redis-cli INFO"
echo ""
echo "✓ Redis Queue Setup Complete!"
echo ""
