<?php
/**
 * Stats Endpoint - Queue and System Statistics
 * Provides real-time monitoring of processing queue
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/RedisQueue.php';

// CORS headers
header('Access-Control-Allow-Origin: ' . (getenv('ALLOWED_ORIGINS') ?: '*'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

/**
 * Validate API key
 */
function validateApiKey() {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $validKey = getenv('API_KEY');
    
    if (empty($validKey)) {
        http_response_code(500);
        die(json_encode(['error' => 'API key not configured on server']));
    }
    
    if (!hash_equals($validKey, $providedKey)) {
        http_response_code(401);
        die(json_encode(['error' => 'Invalid API key']));
    }
    
    return true;
}

try {
    // Validate API key
    validateApiKey();
    
    // Initialize queue
    $queue = new RedisQueue();
    
    // Get queue statistics
    $queueStats = $queue->getStats();
    
    // Get batch ID if provided
    $batchId = $_GET['batch_id'] ?? null;
    $batchStats = null;
    
    if ($batchId) {
        $batchStats = $queue->getBatchStats($batchId);
    }
    
    // Calculate additional metrics
    $totalJobs = $queueStats['total'];
    $completedJobs = $queueStats['completed'];
    $failedJobs = $queueStats['failed'];
    $pendingJobs = $queueStats['pending'];
    $processingJobs = $queueStats['processing'];
    
    $successRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 2) : 0;
    $failureRate = $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0;
    
    // Estimate completion time for pending jobs
    $workerCount = (int)(getenv('WORKER_COUNT') ?: 5);
    $avgProcessingTime = 120; // 2 minutes per video
    
    if ($pendingJobs > 0) {
        $estimatedSeconds = ($pendingJobs / $workerCount) * $avgProcessingTime;
        $estimatedHours = floor($estimatedSeconds / 3600);
        $estimatedMinutes = floor(($estimatedSeconds % 3600) / 60);
        
        if ($estimatedHours > 0) {
            $estimatedCompletion = "{$estimatedHours}h {$estimatedMinutes}m";
        } else {
            $estimatedCompletion = "{$estimatedMinutes}m";
        }
    } else {
        $estimatedCompletion = "0m";
    }
    
    // Build response
    $response = [
        'status' => 'success',
        'timestamp' => time(),
        'queue_stats' => [
            'pending' => $pendingJobs,
            'processing' => $processingJobs,
            'completed' => $completedJobs,
            'failed' => $failedJobs,
            'total' => $totalJobs,
            'queue_length' => $queueStats['queue_length'],
            'processing_count' => $queueStats['processing_count'],
            'dead_letter_count' => $queueStats['dead_letter_count']
        ],
        'metrics' => [
            'success_rate' => $successRate,
            'failure_rate' => $failureRate,
            'estimated_completion' => $estimatedCompletion
        ],
        'system' => [
            'worker_count' => $workerCount,
            'avg_processing_time' => $avgProcessingTime . 's'
        ]
    ];
    
    // Add batch stats if requested
    if ($batchStats) {
        $batchTotal = $batchStats['total'];
        $batchCompleted = $batchStats['completed'];
        $batchFailed = $batchStats['failed'];
        $batchPending = $batchTotal - $batchCompleted - $batchFailed;
        
        $batchProgress = $batchTotal > 0 ? round((($batchCompleted + $batchFailed) / $batchTotal) * 100, 2) : 0;
        
        $response['batch_stats'] = [
            'batch_id' => $batchId,
            'total' => $batchTotal,
            'completed' => $batchCompleted,
            'failed' => $batchFailed,
            'pending' => $batchPending,
            'progress' => $batchProgress,
            'created_at' => $batchStats['created_at']
        ];
    }
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Stats endpoint error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'Failed to retrieve statistics'
    ]);
}
?>
