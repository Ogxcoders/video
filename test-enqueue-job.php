#!/usr/bin/env php
<?php
/**
 * Test Script: Manually Enqueue a Job to Redis Queue
 * 
 * This script bypasses the compress.php API and directly adds a test job
 * to the Redis queue. Use this to verify that:
 * 1. Redis connection works
 * 2. Worker can pick up and process jobs
 * 3. The queue system itself is functional
 * 
 * Usage: php test-enqueue-job.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "===========================================\n";
echo "  TEST: Manually Enqueue Job to Redis\n";
echo "===========================================\n\n";

// Load configuration
$config = require __DIR__ . '/config.php';

// Load RedisQueue class
require_once __DIR__ . '/RedisQueue.php';

// Initialize RedisQueue
echo "1. Initializing Redis connection...\n";
$queue = new RedisQueue([
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => getenv('REDIS_PORT') ?: 6379,
    'queue_name' => 'compression_queue',
    'log_file' => __DIR__ . '/logs/all.log'
]);

if (!$queue->isConnected()) {
    echo "❌ FAILED: Redis not connected\n";
    echo "   Error: " . $queue->getLastError() . "\n";
    exit(1);
}

echo "✅ Redis connected successfully\n\n";

// Check current queue stats
echo "2. Current queue statistics:\n";
$stats = $queue->getStats();
echo "   - Pending jobs: " . $stats['queue_length'] . "\n";
echo "   - Processing: " . $stats['processing_count'] . "\n\n";

// Create a test job
$jobId = 'test_job_' . time();
$testJob = [
    'jobId' => $jobId,
    'postId' => 999,
    'wpMediaPath' => '/test/path/to/media.mp4',
    'wpThumbnailPath' => '/test/path/to/thumbnail.jpg',
    'year' => date('Y'),
    'month' => date('m'),
    'status' => 'pending',
    'createdAt' => date('Y-m-d H:i:s'),
    'updatedAt' => date('Y-m-d H:i:s')
];

echo "3. Enqueueing test job...\n";
echo "   Job ID: $jobId\n";
echo "   Post ID: 999\n\n";

$result = $queue->enqueue($testJob);

if ($result) {
    echo "✅ SUCCESS: Job enqueued!\n\n";
    
    // Check updated queue stats
    echo "4. Updated queue statistics:\n";
    $stats = $queue->getStats();
    echo "   - Pending jobs: " . $stats['queue_length'] . "\n";
    echo "   - Processing: " . $stats['processing_count'] . "\n\n";
    
    echo "===========================================\n";
    echo "  ✅ TEST PASSED\n";
    echo "===========================================\n\n";
    
    echo "Next steps:\n";
    echo "1. Check worker logs: tail -f logs/all.log\n";
    echo "2. Worker should pick up job ID: $jobId\n";
    echo "3. Job will fail (test video path doesn't exist) but proves queue works\n\n";
    
    echo "Expected worker behavior:\n";
    echo "- Worker sees pending job\n";
    echo "- Attempts to process $jobId\n";
    echo "- Fails validation (file doesn't exist)\n";
    echo "- Marks job as failed\n\n";
    
} else {
    echo "❌ FAILED: Could not enqueue job\n";
    echo "   Error: " . $queue->getLastError() . "\n";
    exit(1);
}

echo "Monitor the queue:\n";
echo "  watch -n 1 'redis-cli LLEN compression_queue'\n\n";

exit(0);
