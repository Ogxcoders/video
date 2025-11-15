#!/usr/bin/env php
<?php
/**
 * Background Worker for Video Processing
 * Continuously processes jobs from Redis queue
 * 
 * Usage: php worker.php
 * Supervisor manages multiple instances of this script
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/RedisQueue.php';
require_once __DIR__ . '/VideoProcessor.php';

// Worker configuration
$workerId = getenv('WORKER_ID') ?: (gethostname() . '_' . getmypid());
$processTitle = "video-worker: {$workerId}";

if (function_exists('cli_set_process_title')) {
    cli_set_process_title($processTitle);
}

echo "[{$workerId}] Worker started at " . date('Y-m-d H:i:s') . "\n";

// Initialize dependencies
$config = require __DIR__ . '/config.php';
$queue = new RedisQueue();
$processor = new VideoProcessor($config);

// Graceful shutdown handler
$shutdown = false;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$shutdown, $workerId) {
        echo "[{$workerId}] Received SIGTERM, shutting down gracefully...\n";
        $shutdown = true;
    });
    pcntl_signal(SIGINT, function() use (&$shutdown, $workerId) {
        echo "[{$workerId}] Received SIGINT, shutting down gracefully...\n";
        $shutdown = true;
    });
}

/**
 * Send webhook with retry logic
 */
function sendWebhookWithRetry($url, $data, $secret, $maxAttempts = 5) {
    $delays = [1, 5, 30, 300, 1800]; // 1s, 5s, 30s, 5m, 30m
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            // Generate signature
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha256', $payload, $secret);
            
            // Send webhook
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Webhook-Signature: ' . $signature,
                    'User-Agent: Video-Processor-Worker/1.0'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                return true; // Success
            }
            
            // Retry with delay
            if ($attempt < $maxAttempts - 1) {
                sleep($delays[$attempt]);
            }
            
        } catch (Exception $e) {
            if ($attempt < $maxAttempts - 1) {
                sleep($delays[$attempt]);
            }
        }
    }
    
    return false; // All attempts failed
}

// Main worker loop
while (!$shutdown) {
    try {
        // Get next job (blocking for 5 seconds)
        $job = $queue->getNextJob(5);
        
        // Process signals if available
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        if (!$job) {
            // No job available, check for delayed jobs
            $queue->processDelayedJobs();
            continue;
        }
        
        $postId = $job['data']['post_id'];
        $videoUrl = $job['data']['video_url'];
        $thumbnailUrl = $job['data']['thumbnail_url'];
        $webhookUrl = $job['data']['webhook_url'];
        
        echo "[{$workerId}] Processing job {$job['id']} for post {$postId}\n";
        
        $startTime = microtime(true);
        
        try {
            // Process video and thumbnail
            $result = $processor->processVideo($videoUrl, $thumbnailUrl, $postId);
            
            $duration = round(microtime(true) - $startTime, 2);
            
            if ($result['status'] === 'success') {
                echo "[{$workerId}] Successfully processed post {$postId} in {$duration}s\n";
                
                // Prepare webhook data
                $webhookData = [
                    'post_id' => $postId,
                    'status' => 'success',
                    'thumbnails' => $result['thumbnails'],
                    'compressed_mp4s' => $result['compressed_mp4s'],
                    'hls_playlists' => $result['hls_playlists'],
                    'master_playlist' => $result['master_playlist'],
                    'processing_time' => $duration
                ];
                
                // Send webhook with retry
                $webhookSecret = getenv('WEBHOOK_SECRET') ?: 'default_secret';
                $webhookSuccess = sendWebhookWithRetry($webhookUrl, $webhookData, $webhookSecret);
                
                if ($webhookSuccess) {
                    echo "[{$workerId}] Webhook delivered successfully for post {$postId}\n";
                    $queue->completeJob($job['id'], $result);
                } else {
                    echo "[{$workerId}] Webhook delivery failed for post {$postId}\n";
                    $queue->failJob($job['id'], 'Webhook delivery failed after all retries');
                }
                
            } else {
                echo "[{$workerId}] Failed to process post {$postId}: {$result['message']}\n";
                $queue->failJob($job['id'], $result['message']);
            }
            
        } catch (Exception $e) {
            echo "[{$workerId}] Exception processing post {$postId}: {$e->getMessage()}\n";
            $queue->failJob($job['id'], $e->getMessage());
        }
        
    } catch (Exception $e) {
        echo "[{$workerId}] Worker error: {$e->getMessage()}\n";
        sleep(5); // Wait before retrying
    }
    
    // Process signals if available
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}

echo "[{$workerId}] Worker stopped gracefully at " . date('Y-m-d H:i:s') . "\n";
exit(0);
?>
