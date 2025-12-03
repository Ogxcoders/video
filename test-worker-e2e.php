#!/usr/bin/env php
<?php
/**
 * End-to-End Worker Test
 * Tests the complete workflow: enqueue → worker picks → compresses → webhook
 * 
 * Usage: php test-worker-e2e.php
 */

echo "========================================\n";
echo "  Worker End-to-End Test\n";
echo "========================================\n\n";

require_once __DIR__ . '/RedisQueue.php';

// Initialize Redis Queue
$queue = new RedisQueue([
    'host' => '127.0.0.1',
    'port' => 6379,
    'queue_name' => 'compression_queue'
]);

// Check Redis connection
echo "Step 1: Checking Redis Connection\n";
echo "----------------------------------------\n";
if (!$queue->isConnected()) {
    echo "✗ Failed to connect to Redis\n";
    echo "  Error: " . $queue->getLastError() . "\n";
    exit(1);
}
echo "✓ Connected to Redis\n\n";

// Create test video file (if needed for testing)
echo "Step 2: Preparing Test Data\n";
echo "----------------------------------------\n";

$testJob = [
    'jobId' => 'test_e2e_' . time(),
    'postId' => 99999,
    'wpPostUrl' => 'https://example.com/post/99999',
    'wpMediaPath' => '/wp-content/uploads/2024/11/test-video.mp4',
    'wpVideoUrl' => 'https://example.com/uploads/test-video.mp4',
    'wpThumbnailPath' => '/wp-content/uploads/2024/11/test-thumb.jpg',
    'year' => 2024,
    'month' => 11
];

echo "Test Job ID: {$testJob['jobId']}\n";
echo "Post ID: {$testJob['postId']}\n\n";

// Enqueue the job
echo "Step 3: Enqueuing Test Job\n";
echo "----------------------------------------\n";
$enqueued = $queue->enqueue($testJob);

if ($enqueued) {
    echo "✓ Job enqueued successfully\n";
    echo "  Queue length: " . $queue->getQueueLength() . "\n\n";
} else {
    echo "✗ Failed to enqueue job\n";
    exit(1);
}

// Monitor job status
echo "Step 4: Monitoring Job Status\n";
echo "----------------------------------------\n";
echo "Waiting for worker to pick up job...\n";
echo "(Worker must be running: php worker.php)\n\n";

$timeout = 60; // 60 seconds timeout
$startTime = time();
$lastStatus = null;

while (time() - $startTime < $timeout) {
    $status = $queue->getJobStatus($testJob['jobId']);
    
    if ($status && $status['status'] !== $lastStatus) {
        $lastStatus = $status['status'];
        echo "[" . date('H:i:s') . "] Status: {$status['status']}\n";
        
        if ($status['status'] === 'completed') {
            echo "\n✓ Job completed successfully!\n\n";
            
            echo "Results:\n";
            echo "  Status: {$status['status']}\n";
            echo "  Created: {$status['created_at']}\n";
            echo "  Completed: {$status['completed_at']}\n";
            
            if (isset($status['result'])) {
                $result = json_decode($status['result'], true);
                if ($result && isset($result['stats'])) {
                    echo "  Original Size: " . formatBytes($result['stats']['original_size'] ?? 0) . "\n";
                    echo "  Compressed Size: " . formatBytes($result['stats']['compressed_size'] ?? 0) . "\n";
                    echo "  Compression Ratio: {$result['stats']['compression_ratio']}%\n";
                    echo "  Processing Time: {$result['stats']['processing_time']}s\n";
                }
            }
            
            echo "\n";
            exit(0);
        }
        
        if ($status['status'] === 'failed') {
            echo "\n✗ Job failed!\n";
            echo "  Error: {$status['error']}\n\n";
            exit(1);
        }
    }
    
    sleep(2);
}

echo "\n⏱ Timeout: Job not completed within {$timeout} seconds\n";
echo "  Last status: " . ($lastStatus ?: 'pending') . "\n";
echo "  Check worker logs: /var/www/html/logs/worker.log\n\n";

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}
