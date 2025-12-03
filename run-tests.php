#!/usr/bin/env php
<?php
/**
 * Comprehensive Test Suite for Video Compression System
 * Combines all tests: Redis Queue, Compression API, Configuration
 * 
 * Usage: php run-tests.php
 * Docker: docker exec <container> php /var/www/html/run-tests.php
 */

class TestSuite {
    private $passed = 0;
    private $failed = 0;
    private $warnings = 0;
    private $startTime;
    
    public function __construct() {
        $this->startTime = microtime(true);
    }
    
    public function run() {
        $this->printHeader("Video Compression System - Full Test Suite");
        
        // Run all test sections
        $this->testEnvironment();
        $this->testRedisConnection();
        $this->testRedisQueue();
        $this->testCompressionAPI();
        $this->testConfiguration();
        $this->testFileSystem();
        
        // Final summary
        $this->printSummary();
    }
    
    private function testEnvironment() {
        $this->printSection("1. Environment & Dependencies");
        
        // PHP Version
        $this->assert(
            version_compare(PHP_VERSION, '7.4', '>='),
            "PHP version >= 7.4",
            "Current: " . PHP_VERSION
        );
        
        // Redis Extension
        $this->assert(
            extension_loaded('redis'),
            "Redis extension loaded",
            extension_loaded('redis') ? "Version: " . phpversion('redis') : "NOT INSTALLED"
        );
        
        // cURL Extension
        $this->assert(
            extension_loaded('curl'),
            "cURL extension loaded",
            "Required for API requests"
        );
        
        // JSON Extension
        $this->assert(
            extension_loaded('json'),
            "JSON extension loaded",
            "Required for data encoding"
        );
        
        echo "\n";
    }
    
    private function testRedisConnection() {
        $this->printSection("2. Redis Connection Test");
        
        require_once __DIR__ . '/RedisQueue.php';
        
        $queue = new RedisQueue([
            'host' => '127.0.0.1',
            'port' => 6379,
            'queue_name' => 'test_suite_queue',
            'processing_queue' => 'test_suite_processing'
        ]);
        
        // Test connection
        $connected = $queue->isConnected();
        $this->assert(
            $connected,
            "Connect to Redis server",
            $connected ? "✓ Connected" : "✗ " . $queue->getLastError()
        );
        
        if (!$connected) {
            echo "  ⚠️  Skipping Redis tests - connection failed\n\n";
            $this->warnings++;
            return;
        }
        
        // Get stats
        $stats = $queue->getStats();
        $this->assert(
            isset($stats['redis_version']),
            "Get Redis server info",
            "Version: " . ($stats['redis_version'] ?? 'unknown')
        );
        
        echo "\n";
    }
    
    private function testRedisQueue() {
        $this->printSection("3. Redis Queue Operations");
        
        require_once __DIR__ . '/RedisQueue.php';
        
        $queue = new RedisQueue([
            'host' => '127.0.0.1',
            'port' => 6379,
            'queue_name' => 'test_suite_queue',
            'processing_queue' => 'test_suite_processing'
        ]);
        
        if (!$queue->isConnected()) {
            echo "  ⚠️  Skipping - Redis not connected\n\n";
            $this->warnings++;
            return;
        }
        
        // Clear test queues
        $queue->clearAll();
        echo "  🧹 Cleared test queues\n";
        
        // Test 1: Enqueue jobs
        $testJobs = [
            [
                'jobId' => 'test_job_1_' . time(),
                'postId' => 99001,
                'wpMediaPath' => '/test/video1.mp4',
                'year' => 2024,
                'month' => 11
            ],
            [
                'jobId' => 'test_job_2_' . time(),
                'postId' => 99002,
                'wpMediaPath' => '/test/video2.mp4',
                'year' => 2024,
                'month' => 11
            ],
            [
                'jobId' => 'test_job_3_' . time(),
                'postId' => 99003,
                'wpMediaPath' => '/test/video3.mp4',
                'year' => 2024,
                'month' => 11
            ]
        ];
        
        $enqueueCount = 0;
        foreach ($testJobs as $job) {
            if ($queue->enqueue($job)) {
                $enqueueCount++;
            }
            usleep(10000); // 10ms delay
        }
        
        $this->assert(
            $enqueueCount === 3,
            "Enqueue 3 test jobs",
            "Enqueued: $enqueueCount/3"
        );
        
        // Test 2: Queue length
        $length = $queue->getQueueLength();
        $this->assert(
            $length === 3,
            "Verify queue length",
            "Length: $length"
        );
        
        // Test 3: FIFO dequeue
        $job1 = $queue->dequeue(1);
        $this->assert(
            $job1 !== null && $job1['postId'] === 99001,
            "Dequeue first job (FIFO order)",
            $job1 ? "Job ID: {$job1['jobId']}" : "Failed"
        );
        
        // Test 4: Mark completed
        if ($job1) {
            $completed = $queue->markCompleted($job1['jobId'], [
                'output' => '/test/output1/',
                'time' => 1.5
            ]);
            $this->assert(
                $completed,
                "Mark job as completed",
                "Job: {$job1['jobId']}"
            );
        }
        
        // Test 5: Mark failed
        $job2 = $queue->dequeue(1);
        if ($job2) {
            $failed = $queue->markFailed($job2['jobId'], 'Test error message');
            $this->assert(
                $failed,
                "Mark job as failed",
                "Job: {$job2['jobId']}"
            );
        }
        
        // Test 6: Get job status
        if ($job1) {
            $status = $queue->getJobStatus($job1['jobId']);
            $this->assert(
                $status && $status['status'] === 'completed',
                "Get job status",
                $status ? "Status: {$status['status']}" : "Failed"
            );
        }
        
        // Test 7: Queue statistics
        $stats = $queue->getStats();
        $this->assert(
            isset($stats['completed']) && $stats['completed'] >= 1,
            "Get queue statistics",
            "Completed: {$stats['completed']}, Failed: {$stats['failed']}"
        );
        
        // Test 8: Timeout on empty queue
        $job3 = $queue->dequeue(1); // Clear last job
        $startTime = microtime(true);
        $noJob = $queue->dequeue(1); // Should timeout
        $elapsed = microtime(true) - $startTime;
        
        $this->assert(
            $noJob === null && $elapsed >= 0.9,
            "Timeout on empty queue",
            sprintf("Elapsed: %.2fs", $elapsed)
        );
        
        // Cleanup
        $queue->clearAll();
        echo "  🧹 Cleaned up test data\n\n";
    }
    
    private function testCompressionAPI() {
        $this->printSection("4. Compression API Endpoint");
        
        // Check if compress.php exists
        $compressFile = __DIR__ . '/compress.php';
        $this->assert(
            file_exists($compressFile),
            "compress.php exists",
            "Path: $compressFile"
        );
        
        if (!file_exists($compressFile)) {
            echo "  ⚠️  Skipping API tests - compress.php not found\n\n";
            $this->warnings++;
            return;
        }
        
        // Test API endpoint with cURL
        $apiUrl = 'http://localhost/compress.php';
        $apiKey = getenv('API_KEY') ?: 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY';
        
        $testData = [
            'postId' => 88888,
            'wpPostUrl' => 'https://example.com/post/88888',
            'wpMediaPath' => '/wp-content/uploads/2024/11/test.mp4',
            'wpVideoUrl' => 'https://example.com/uploads/test.mp4',
            'wpThumbnailPath' => '/wp-content/uploads/2024/11/thumb.jpg',
            'year' => 2024,
            'month' => 11
        ];
        
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($testData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Test successful API call
        $success = ($httpCode === 200 || $httpCode === 202) && !$curlError;
        $this->assert(
            $success,
            "POST request to compress API",
            $success ? "HTTP $httpCode" : "HTTP $httpCode - $curlError"
        );
        
        // Test response format
        $decoded = json_decode($response, true);
        $this->assert(
            $decoded !== null && isset($decoded['status']),
            "Valid JSON response",
            $decoded ? "Status: {$decoded['status']}" : "Invalid JSON"
        );
        
        // Test job ID generation
        if ($decoded && $decoded['status'] === 'success') {
            $this->assert(
                isset($decoded['jobId']) && strpos($decoded['jobId'], 'job_') === 0,
                "Job ID generated correctly",
                "Job ID: " . ($decoded['jobId'] ?? 'missing')
            );
        }
        
        echo "\n";
    }
    
    private function testConfiguration() {
        $this->printSection("5. Configuration");
        
        $configFile = __DIR__ . '/config.php';
        $this->assert(
            file_exists($configFile),
            "config.php exists",
            "Path: $configFile"
        );
        
        if (!file_exists($configFile)) {
            echo "  ⚠️  Skipping config tests\n\n";
            $this->warnings++;
            return;
        }
        
        $config = require $configFile;
        
        // Test API key configured
        $apiKeySet = !empty($config['api_key']) && 
                     $config['api_key'] !== 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY';
        
        if (!$apiKeySet) {
            echo "  ⚠️  API_KEY not configured (using default)\n";
            $this->warnings++;
        } else {
            $this->assert(
                true,
                "API key configured",
                "Length: " . strlen($config['api_key']) . " chars"
            );
        }
        
        // Test FFmpeg binary
        $ffmpegPath = $config['ffmpeg_binary'] ?? '/usr/bin/ffmpeg';
        exec(escapeshellarg($ffmpegPath) . ' -version 2>&1', $output, $returnCode);
        $this->assert(
            $returnCode === 0,
            "FFmpeg available",
            $returnCode === 0 ? "Path: $ffmpegPath" : "NOT FOUND"
        );
        
        echo "\n";
    }
    
    private function testFileSystem() {
        $this->printSection("6. File System & Permissions");
        
        // Test logs directory
        $logsDir = __DIR__ . '/logs';
        $this->assert(
            is_dir($logsDir) || mkdir($logsDir, 0755, true),
            "Logs directory exists/created",
            "Path: $logsDir"
        );
        
        $this->assert(
            is_writable($logsDir),
            "Logs directory writable",
            "Permissions: " . substr(sprintf('%o', fileperms($logsDir)), -4)
        );
        
        // Test videos directory
        $videosDir = __DIR__ . '/videos';
        $this->assert(
            is_dir($videosDir) || mkdir($videosDir, 0755, true),
            "Videos directory exists/created",
            "Path: $videosDir"
        );
        
        // Test HLS directory
        $hlsDir = __DIR__ . '/hls';
        $this->assert(
            is_dir($hlsDir) || mkdir($hlsDir, 0755, true),
            "HLS directory exists/created",
            "Path: $hlsDir"
        );
        
        // Check RedisQueue.php
        $redisQueueFile = __DIR__ . '/RedisQueue.php';
        $this->assert(
            file_exists($redisQueueFile),
            "RedisQueue.php exists",
            "Path: $redisQueueFile"
        );
        
        echo "\n";
    }
    
    // Helper methods
    
    private function assert($condition, $description, $details = '') {
        if ($condition) {
            $this->passed++;
            echo "  ✓ $description";
            if ($details) {
                echo " - $details";
            }
            echo "\n";
        } else {
            $this->failed++;
            echo "  ✗ $description";
            if ($details) {
                echo " - $details";
            }
            echo "\n";
        }
    }
    
    private function printHeader($title) {
        $width = 70;
        echo "\n";
        echo str_repeat("=", $width) . "\n";
        echo str_pad(" $title ", $width, " ", STR_PAD_BOTH) . "\n";
        echo str_repeat("=", $width) . "\n\n";
    }
    
    private function printSection($title) {
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  $title\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    }
    
    private function printSummary() {
        $elapsed = microtime(true) - $this->startTime;
        $total = $this->passed + $this->failed;
        $successRate = $total > 0 ? round(($this->passed / $total) * 100, 1) : 0;
        
        $this->printSection("Test Summary");
        
        echo "  Total Tests:    $total\n";
        echo "  ✓ Passed:       {$this->passed}\n";
        echo "  ✗ Failed:       {$this->failed}\n";
        echo "  ⚠️  Warnings:     {$this->warnings}\n";
        echo "  Success Rate:   {$successRate}%\n";
        echo "  Execution Time: " . number_format($elapsed, 2) . "s\n\n";
        
        if ($this->failed === 0) {
            echo "  🎉 All tests passed!\n\n";
            exit(0);
        } else {
            echo "  ❌ Some tests failed. Review output above.\n\n";
            exit(1);
        }
    }
}

// Run the test suite
$suite = new TestSuite();
$suite->run();
