#!/usr/bin/env php
<?php
/**
 * Simple Redis Connection Test
 * Tests Redis connectivity and provides diagnostic information
 * 
 * Usage: php test-redis-connection.php
 * Docker: docker exec <container> php /var/www/html/test-redis-connection.php
 */

echo "========================================\n";
echo " Redis Connection Test Script\n";
echo "========================================\n\n";

// Step 1: Check PHP Redis extension
echo "Step 1: Testing Redis Extension\n";
echo "----------------------------------------\n";

if (!extension_loaded('redis')) {
    echo "✗ PHP Redis extension NOT loaded\n";
    echo "\n";
    echo "To install:\n";
    echo "  - Ubuntu/Debian: sudo apt-get install php-redis\n";
    echo "  - PECL: sudo pecl install redis\n";
    echo "  - Docker: Already included in Dockerfile\n";
    exit(1);
}

echo "✓ Redis extension loaded\n";
echo "  Version: " . phpversion('redis') . "\n";
echo "\n";

// Step 2: Test Redis connection
echo "Step 2: Testing Redis Connection\n";
echo "----------------------------------------\n";

try {
    $redis = new Redis();
    
    // Try to connect
    $host = '127.0.0.1';
    $port = 6379;
    $timeout = 2.5;
    
    echo "Attempting connection to $host:$port...\n";
    
    $connected = $redis->pconnect($host, $port, $timeout);
    
    if (!$connected) {
        echo "✗ Failed to connect to Redis\n";
        echo "\n";
        echo "Troubleshooting:\n";
        echo "  - Make sure Redis is running: sudo systemctl start redis-server\n";
        echo "  - Check if Redis is listening: netstat -tlnp | grep 6379\n";
        echo "  - In Docker: Redis should start automatically\n";
        echo "  - Check logs: tail -f /var/log/redis/redis-server.log\n";
        exit(1);
    }
    
    echo "✓ Connected to Redis successfully\n";
    
    // Test ping
    $pong = $redis->ping();
    $isPong = ($pong === '+PONG' || $pong === true || $pong === 'PONG');
    
    if (!$isPong) {
        echo "✗ Redis ping failed (got: " . var_export($pong, true) . ")\n";
        exit(1);
    }
    
    echo "✓ Redis ping successful\n";
    echo "\n";
    
    // Step 3: Get server info
    echo "Step 3: Redis Server Information\n";
    echo "----------------------------------------\n";
    
    $info = $redis->info();
    
    echo "Redis Version: " . ($info['redis_version'] ?? 'unknown') . "\n";
    echo "Redis Mode: " . ($info['redis_mode'] ?? 'unknown') . "\n";
    echo "Uptime: " . ($info['uptime_in_seconds'] ?? '0') . " seconds\n";
    echo "Connected Clients: " . ($info['connected_clients'] ?? '0') . "\n";
    echo "Used Memory: " . ($info['used_memory_human'] ?? 'unknown') . "\n";
    echo "\n";
    
    // Step 4: Test basic operations
    echo "Step 4: Testing Basic Operations\n";
    echo "----------------------------------------\n";
    
    // Test SET
    $testKey = 'test_connection_' . time();
    $testValue = 'Hello Redis!';
    
    if (!$redis->set($testKey, $testValue)) {
        echo "✗ Failed to SET value\n";
        exit(1);
    }
    echo "✓ SET operation successful\n";
    
    // Test GET
    $retrieved = $redis->get($testKey);
    if ($retrieved !== $testValue) {
        echo "✗ GET operation failed (expected: $testValue, got: $retrieved)\n";
        exit(1);
    }
    echo "✓ GET operation successful\n";
    
    // Test DEL
    if (!$redis->del($testKey)) {
        echo "✗ DEL operation failed\n";
        exit(1);
    }
    echo "✓ DEL operation successful\n";
    
    // Test LIST operations (for queue)
    $testQueue = 'test_queue_' . time();
    
    if (!$redis->lPush($testQueue, 'item1')) {
        echo "✗ LPUSH operation failed\n";
        exit(1);
    }
    echo "✓ LPUSH operation successful\n";
    
    $item = $redis->rPop($testQueue);
    if ($item !== 'item1') {
        echo "✗ RPOP operation failed\n";
        exit(1);
    }
    echo "✓ RPOP operation successful\n";
    
    echo "\n";
    
    // Step 5: Test RedisQueue class
    echo "Step 5: Testing RedisQueue Class\n";
    echo "----------------------------------------\n";
    
    if (!file_exists(__DIR__ . '/RedisQueue.php')) {
        echo "✗ RedisQueue.php not found\n";
        exit(1);
    }
    
    require_once __DIR__ . '/RedisQueue.php';
    
    $queue = new RedisQueue([
        'host' => $host,
        'port' => $port,
        'queue_name' => 'test_connection_queue'
    ]);
    
    if (!$queue->isConnected()) {
        echo "✗ RedisQueue failed to connect\n";
        echo "  Error: " . $queue->getLastError() . "\n";
        exit(1);
    }
    
    echo "✓ RedisQueue connected successfully\n";
    
    // Test enqueue
    $testJob = [
        'jobId' => 'test_job_' . time(),
        'postId' => 99999,
        'wpMediaPath' => '/test/video.mp4',
        'year' => 2024,
        'month' => 11
    ];
    
    if (!$queue->enqueue($testJob)) {
        echo "✗ Failed to enqueue test job\n";
        echo "  Error: " . $queue->getLastError() . "\n";
        exit(1);
    }
    
    echo "✓ Successfully enqueued test job\n";
    
    // Test dequeue
    $job = $queue->dequeue(2);
    if (!$job || $job['jobId'] !== $testJob['jobId']) {
        echo "✗ Failed to dequeue test job\n";
        exit(1);
    }
    
    echo "✓ Successfully dequeued test job\n";
    
    // Cleanup
    $queue->clearAll();
    echo "✓ Cleanup successful\n";
    
    echo "\n";
    
    // Final summary
    echo "========================================\n";
    echo " ✅ All Tests Passed!\n";
    echo "========================================\n";
    echo "\n";
    echo "Redis is working correctly and ready for production use.\n";
    echo "\n";
    echo "Next steps:\n";
    echo "  1. Run full test suite: php run-tests.php\n";
    echo "  2. Start background worker: php worker.php\n";
    echo "  3. Send test compression job to /compress.php\n";
    echo "\n";
    
    exit(0);
    
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
