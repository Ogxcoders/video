<?php
/**
 * Enhanced Health Check Endpoint
 * Provides comprehensive system health monitoring
 * Checks: Redis, Workers, Disk Space, Queue Status
 * 
 * Status levels:
 * - healthy: All systems operational
 * - degraded: Some issues but system functional
 * - unhealthy: Critical issues detected
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$health = [
    'status' => 'healthy',
    'timestamp' => time(),
    'service' => 'vps-api',
    'version' => '3.0',
    'checks' => []
];

// ========================================
// 1. REDIS HEALTH CHECK
// ========================================
try {
    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: 'redis',
        getenv('REDIS_PORT') ?: 6379
    );
    
    // Test Redis is responsive
    $pingResult = $redis->ping();
    
    if ($pingResult === true || $pingResult === '+PONG') {
        $health['checks']['redis'] = [
            'status' => 'ok',
            'connection' => 'connected',
            'response_time_ms' => round(microtime(true) * 1000)
        ];
        
        // Get queue depth
        $queueName = getenv('QUEUE_NAME') ?: 'video_compression_queue';
        $queueDepth = $redis->lLen($queueName);
        $processingQueue = $redis->lLen($queueName . ':processing');
        $deadLetterQueue = $redis->lLen($queueName . ':dead_letter');
        
        // Get stats
        $totalJobs = $redis->get('stats:total_jobs') ?: 0;
        $completed = $redis->get('stats:completed') ?: 0;
        $failed = $redis->get('stats:failed') ?: 0;
        
        $health['checks']['queue'] = [
            'status' => $queueDepth > 1000 ? 'warning' : 'ok',
            'pending_jobs' => (int)$queueDepth,
            'processing_jobs' => (int)$processingQueue,
            'dead_letter_jobs' => (int)$deadLetterQueue,
            'stats' => [
                'total_jobs' => (int)$totalJobs,
                'completed' => (int)$completed,
                'failed' => (int)$failed,
                'success_rate' => $totalJobs > 0 ? round(($completed / $totalJobs) * 100, 2) . '%' : '0%'
            ]
        ];
        
        // Alert if queue too deep
        if ($queueDepth > 1000) {
            $health['status'] = 'degraded';
            $health['checks']['queue']['warning'] = 'Queue depth exceeds 1000 jobs';
        }
        
        // Alert if too many failures
        if ($deadLetterQueue > 50) {
            $health['status'] = 'degraded';
            $health['checks']['queue']['warning'] = 'Dead letter queue has ' . $deadLetterQueue . ' failed jobs';
        }
        
    } else {
        throw new Exception('Redis ping failed');
    }
    
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['redis'] = [
        'status' => 'failed',
        'error' => $e->getMessage()
    ];
}

// ========================================
// 2. WORKER HEALTH CHECK
// ========================================
try {
    // Check if supervisor is running workers
    exec('supervisorctl status 2>&1', $output, $returnCode);
    
    $workersRunning = 0;
    $workersStopped = 0;
    $workerDetails = [];
    
    foreach ($output as $line) {
        // Parse supervisor status output
        // Expected format: "video-worker:video-worker_00   RUNNING   pid 123, uptime 1:23:45"
        if (strpos($line, 'video-worker') !== false) {
            $parts = preg_split('/\s+/', $line);
            $workerName = $parts[0] ?? 'unknown';
            $status = $parts[1] ?? 'UNKNOWN';
            
            $workerDetails[] = [
                'name' => $workerName,
                'status' => $status,
                'uptime' => isset($parts[5]) ? $parts[5] : 'N/A'
            ];
            
            if ($status === 'RUNNING') {
                $workersRunning++;
            } else {
                $workersStopped++;
            }
        }
    }
    
    $expectedWorkers = getenv('WORKER_COUNT') ?: 5;
    
    $health['checks']['workers'] = [
        'status' => $workersRunning >= ($expectedWorkers * 0.6) ? 'ok' : 'degraded',
        'running' => $workersRunning,
        'stopped' => $workersStopped,
        'expected' => (int)$expectedWorkers,
        'details' => $workerDetails
    ];
    
    // Set health status based on workers
    if ($workersRunning === 0) {
        $health['status'] = 'unhealthy';
        $health['checks']['workers']['error'] = 'No workers running';
    } elseif ($workersRunning < $expectedWorkers * 0.6) {
        $health['status'] = 'degraded';
        $health['checks']['workers']['warning'] = 'Less than 60% of workers running';
    }
    
} catch (Exception $e) {
    $health['checks']['workers'] = [
        'status' => 'unknown',
        'error' => $e->getMessage()
    ];
}

// ========================================
// 3. DISK SPACE CHECK
// ========================================
try {
    $mediaPath = getenv('MEDIA_BASE_PATH') ?: '/var/www/media';
    
    if (is_dir($mediaPath)) {
        $diskFree = disk_free_space($mediaPath);
        $diskTotal = disk_total_space($mediaPath);
        
        if ($diskFree !== false && $diskTotal !== false) {
            $diskPercent = ($diskFree / $diskTotal) * 100;
            $diskUsed = $diskTotal - $diskFree;
            
            $health['checks']['disk_space'] = [
                'status' => $diskPercent < 10 ? 'critical' : ($diskPercent < 20 ? 'warning' : 'ok'),
                'path' => $mediaPath,
                'free_gb' => round($diskFree / (1024 * 1024 * 1024), 2),
                'used_gb' => round($diskUsed / (1024 * 1024 * 1024), 2),
                'total_gb' => round($diskTotal / (1024 * 1024 * 1024), 2),
                'free_percent' => round($diskPercent, 2)
            ];
            
            // Alert on low disk space
            if ($diskPercent < 10) {
                $health['status'] = 'unhealthy';
                $health['checks']['disk_space']['error'] = 'Critical: Less than 10% disk space remaining';
            } elseif ($diskPercent < 20) {
                if ($health['status'] === 'healthy') {
                    $health['status'] = 'degraded';
                }
                $health['checks']['disk_space']['warning'] = 'Warning: Less than 20% disk space remaining';
            }
        }
    }
} catch (Exception $e) {
    $health['checks']['disk_space'] = [
        'status' => 'unknown',
        'error' => $e->getMessage()
    ];
}

// ========================================
// 4. FFMPEG CHECK
// ========================================
try {
    $ffmpegPath = getenv('FFMPEG_PATH') ?: '/usr/bin/ffmpeg';
    
    if (file_exists($ffmpegPath)) {
        exec("$ffmpegPath -version 2>&1", $ffmpegOutput, $ffmpegReturn);
        
        if ($ffmpegReturn === 0) {
            // Extract version from first line
            $versionLine = $ffmpegOutput[0] ?? '';
            preg_match('/ffmpeg version (\S+)/', $versionLine, $matches);
            $version = $matches[1] ?? 'unknown';
            
            $health['checks']['ffmpeg'] = [
                'status' => 'ok',
                'path' => $ffmpegPath,
                'version' => $version
            ];
        } else {
            throw new Exception('FFmpeg execution failed');
        }
    } else {
        throw new Exception('FFmpeg binary not found at ' . $ffmpegPath);
    }
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['ffmpeg'] = [
        'status' => 'failed',
        'error' => $e->getMessage()
    ];
}

// ========================================
// 5. API CONFIGURATION CHECK
// ========================================
$apiKeyConfigured = !empty(getenv('API_KEY')) && getenv('API_KEY') !== 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY';
$webhookSecretConfigured = !empty(getenv('WEBHOOK_SECRET')) && getenv('WEBHOOK_SECRET') !== 'CHANGE_ME_TO_A_SECURE_SECRET';

$health['checks']['configuration'] = [
    'status' => ($apiKeyConfigured && $webhookSecretConfigured) ? 'ok' : 'warning',
    'api_key_configured' => $apiKeyConfigured,
    'webhook_secret_configured' => $webhookSecretConfigured,
    'media_base_path' => getenv('MEDIA_BASE_PATH') ?: '/var/www/media/content',
    'media_base_url' => getenv('MEDIA_BASE_URL') ?: 'https://v.ogtemplate.com/content'
];

if (!$apiKeyConfigured || !$webhookSecretConfigured) {
    if ($health['status'] === 'healthy') {
        $health['status'] = 'degraded';
    }
    $health['checks']['configuration']['warning'] = 'Security credentials not properly configured';
}

// ========================================
// SET HTTP STATUS CODE BASED ON HEALTH
// ========================================
if ($health['status'] === 'healthy') {
    http_response_code(200);
} elseif ($health['status'] === 'degraded') {
    http_response_code(200); // Still operational
} else {
    http_response_code(503); // Service unavailable
}

echo json_encode($health, JSON_PRETTY_PRINT);
exit;
