<?php
/**
 * Queue-Based Video Compression API Endpoint
 * 
 * This endpoint accepts video URLs from WordPress and adds them to Redis queue.
 * It's a hybrid approach combining:
 * - index.php's video URL acceptance
 * - compress.php's Redis queue functionality
 * 
 * Endpoint: POST https://v.ogtemplate.com/queue-compress.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        error_log("[QUEUE-API] Fatal error: {$error['message']} in {$error['file']}:{$error['line']}");
        echo json_encode([
            'status' => 'error',
            'message' => 'Server error. Please ensure VPS code is up to date.',
            'code' => 'FATAL_ERROR'
        ]);
    }
});

$config = require __DIR__ . '/config.php';

/**
 * Logging Helper Function
 */
function logQueueAPI($message, $context = [], $level = 'INFO') {
    try {
        $logFile = __DIR__ . '/logs/all.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
            chmod($logDir, 0777);
        }
        
        if (!file_exists($logFile)) {
            touch($logFile);
            chmod($logFile, 0666);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] [QUEUE-API] {$message}{$contextStr}\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        chmod($logFile, 0666);
        return true;
    } catch (Exception $e) {
        error_log("[QUEUE-API] Logging exception: " . $e->getMessage());
        return false;
    }
}

// Log incoming request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

logQueueAPI("Request received", [
    'method' => $requestMethod,
    'uri' => $requestUri,
    'ip' => $remoteAddr
]);

// CORS Configuration
$allowedOrigins = $config['allowed_origins'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    logQueueAPI("Preflight request handled", ['origin' => $origin]);
    http_response_code(200);
    exit;
}

// Handle GET requests (for connection testing)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    logQueueAPI("Connection test request", ['ip' => $remoteAddr]);
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'message' => 'Queue API endpoint is ready',
        'endpoint' => 'queue-compress.php',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logQueueAPI("Invalid method rejected", ['method' => $requestMethod]);
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed. Use POST for video processing, GET for connection test.'
    ]);
    exit;
}

// API Key Validation
if (empty($config['api_key']) || $config['api_key'] === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
    logQueueAPI("API key not configured in server", ['ip' => $remoteAddr]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server configuration error: API key not configured'
    ]);
    exit;
}

$providedKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
$keyProvided = !empty($providedKey);

if ($providedKey !== $config['api_key']) {
    logQueueAPI("Authentication failed", [
        'ip' => $remoteAddr,
        'key_provided' => $keyProvided,
        'key_valid' => false
    ]);
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing API key'
    ]);
    exit;
}

logQueueAPI("Authentication successful", ['ip' => $remoteAddr]);

// Parse Request Body
$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logQueueAPI("Invalid JSON received", [
        'error' => json_last_error_msg(),
        'ip' => $remoteAddr
    ]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid JSON in request body'
    ]);
    exit;
}

// Log ALL received data for debugging (critical for understanding what WordPress sends)
logQueueAPI("FULL REQUEST DATA RECEIVED", [
    'all_keys' => array_keys($data),
    'postId' => $data['postId'] ?? 'NOT_SET',
    'wpVideoUrl' => $data['wpVideoUrl'] ?? 'NOT_SET',
    'wpMediaPath' => $data['wpMediaPath'] ?? 'NOT_SET',
    'wpThumbnailUrl' => $data['wpThumbnailUrl'] ?? 'NOT_SET',
    'wpThumbnailPath' => $data['wpThumbnailPath'] ?? 'NOT_SET',
    'year' => $data['year'] ?? 'NOT_SET',
    'month' => $data['month'] ?? 'NOT_SET'
]);

// Validate Required Fields - Support both new format (postId) and legacy format (post_id/video_url)
$postId = isset($data['postId']) ? (int)$data['postId'] : (isset($data['post_id']) ? (int)$data['post_id'] : 0);
$wpVideoUrl = $data['wpVideoUrl'] ?? $data['video_url'] ?? '';
$wpMediaPath = $data['wpMediaPath'] ?? '';
$wpPostUrl = $data['wpPostUrl'] ?? '';
$year = isset($data['year']) ? (int)$data['year'] : 0;
$month = isset($data['month']) ? (int)$data['month'] : 0;
$wpThumbnailPath = $data['wpThumbnailPath'] ?? '';
$wpThumbnailUrl = $data['wpThumbnailUrl'] ?? '';

if (empty($wpVideoUrl) && empty($wpMediaPath)) {
    logQueueAPI("Missing video URL or media path", ['ip' => $remoteAddr]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required field: wpVideoUrl or wpMediaPath'
    ]);
    exit;
}

if ($postId <= 0) {
    logQueueAPI("Invalid postId", [
        'postId' => $postId,
        'ip' => $remoteAddr
    ]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid postId: must be a positive integer'
    ]);
    exit;
}

// Try to extract year/month from URL or path if not provided
if ($year <= 0 || $month <= 0) {
    $pathToCheck = !empty($wpMediaPath) ? $wpMediaPath : $wpVideoUrl;
    
    // Match patterns like /2024/11/ or /2024/12/ in the path or URL
    if (preg_match('#/(\d{4})/(\d{1,2})/#', $pathToCheck, $matches)) {
        $extractedYear = (int)$matches[1];
        $extractedMonth = (int)$matches[2];
        
        if ($extractedYear >= 2000 && $extractedYear <= 2100 && $extractedMonth >= 1 && $extractedMonth <= 12) {
            if ($year <= 0) $year = $extractedYear;
            if ($month <= 0) $month = $extractedMonth;
            logQueueAPI("Extracted year/month from path", [
                'year' => $year,
                'month' => $month,
                'source' => $pathToCheck
            ]);
        }
    }
    
    // If still not found, use current date as fallback
    if ($year <= 0) $year = (int)date('Y');
    if ($month <= 0) $month = (int)date('m');
    logQueueAPI("Using fallback year/month", ['year' => $year, 'month' => $month]);
}

// Convert wpVideoUrl to wpMediaPath if wpMediaPath is empty but we have a URL
if (empty($wpMediaPath) && !empty($wpVideoUrl)) {
    // Extract path from URL (e.g., https://example.com/wp-content/uploads/2024/11/video.mp4)
    $parsedUrl = parse_url($wpVideoUrl);
    if (isset($parsedUrl['path'])) {
        $wpMediaPath = $parsedUrl['path'];
        logQueueAPI("Extracted wpMediaPath from wpVideoUrl", [
            'wpMediaPath' => $wpMediaPath,
            'wpVideoUrl' => $wpVideoUrl
        ]);
    }
}

// Convert wpThumbnailUrl to wpThumbnailPath if wpThumbnailPath is empty but we have a URL
// (Same logic as videos - extract path from thumbnail URL)
if (empty($wpThumbnailPath) && !empty($wpThumbnailUrl)) {
    // Extract path from URL (e.g., https://example.com/wp-content/uploads/2024/11/thumb.webp)
    $parsedUrl = parse_url($wpThumbnailUrl);
    if (isset($parsedUrl['path'])) {
        $wpThumbnailPath = $parsedUrl['path'];
        logQueueAPI("Extracted wpThumbnailPath from wpThumbnailUrl", [
            'wpThumbnailPath' => $wpThumbnailPath,
            'wpThumbnailUrl' => $wpThumbnailUrl
        ]);
    }
}

// Now we should always have valid data
$hasFullFormat = !empty($wpMediaPath) && $year > 0 && $month > 0;

logQueueAPI("Request validated", [
    'postId' => $postId,
    'wpVideoUrl' => $wpVideoUrl,
    'wpMediaPath' => $wpMediaPath,
    'year' => $year,
    'month' => $month,
    'hasFullFormat' => $hasFullFormat
]);

// Generate Job ID
$timestamp = time();
$jobId = "job_{$postId}_{$timestamp}";

logQueueAPI("Job ID generated", [
    'jobId' => $jobId,
    'postId' => $postId,
    'timestamp' => $timestamp
]);

// Prepare Job Data for Queue
// Full format is compatible with the worker's VideoCompressor (compress.php format)
$jobData = [
    'jobId' => $jobId,
    'postId' => $postId,
    'status' => 'pending',
    'createdAt' => date('Y-m-d H:i:s'),
    'updatedAt' => date('Y-m-d H:i:s')
];

// ALWAYS use full format now that we extract year/month and wpMediaPath from URL
// This ensures VideoCompressor is used instead of legacy VideoProcessor
$jobData['wpMediaPath'] = $wpMediaPath;
$jobData['wpVideoUrl'] = $wpVideoUrl;
$jobData['wpPostUrl'] = $wpPostUrl;
$jobData['year'] = $year;
$jobData['month'] = $month;

// Add thumbnail fields if available (check for EITHER path OR url)
if (!empty($wpThumbnailPath) || !empty($wpThumbnailUrl)) {
    $jobData['wpThumbnailPath'] = $wpThumbnailPath;
    $jobData['wpThumbnailUrl'] = $wpThumbnailUrl;
    
    // Log thumbnail data for debugging
    logQueueAPI("Thumbnail data included in job", [
        'jobId' => $jobId,
        'wpThumbnailPath' => $wpThumbnailPath ?: '(empty)',
        'wpThumbnailUrl' => $wpThumbnailUrl ?: '(empty)'
    ]);
}

// Also include videoUrl for backward compatibility with older worker versions
if (!empty($wpVideoUrl)) {
    $jobData['videoUrl'] = $wpVideoUrl;
}

logQueueAPI("Job data prepared", [
    'jobId' => $jobId,
    'postId' => $postId,
    'format' => 'full (VideoCompressor)',
    'wpMediaPath' => $wpMediaPath ?: 'not set',
    'wpVideoUrl' => $wpVideoUrl ?: 'not set',
    'year' => $year,
    'month' => $month,
    'hasThumbnail' => !empty($wpThumbnailPath) || !empty($wpThumbnailUrl),
    'wpThumbnailPath' => $wpThumbnailPath ?: 'not set',
    'wpThumbnailUrl' => $wpThumbnailUrl ?: 'not set'
]);

// Add to Redis Queue - with graceful error handling
try {
    require_once __DIR__ . '/RedisQueue.php';
} catch (Throwable $e) {
    logQueueAPI("WARNING: RedisQueue.php load failed, using file fallback", [
        'error' => $e->getMessage()
    ], 'WARNING');
}

$queueAdded = false;
$queueError = null;
$queueMethod = null;

try {
    logQueueAPI("Initializing RedisQueue", ['jobId' => $jobId]);
    
    // Initialize RedisQueue
    $redisQueue = new RedisQueue([
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => getenv('REDIS_PORT') ?: 6379,
        'queue_name' => 'compression_queue',
        'log_file' => __DIR__ . '/logs/all.log'
    ]);
    
    // Check Redis connection
    if ($redisQueue->isConnected()) {
        logQueueAPI("RedisQueue connected successfully", ['jobId' => $jobId]);
        
        // Enqueue job
        $enqueueResult = $redisQueue->enqueue($jobData);
        
        if ($enqueueResult) {
            $queueAdded = true;
            $queueMethod = 'redis';
            $queueLength = $redisQueue->getQueueLength();
            
            logQueueAPI("Job added to Redis queue", [
                'jobId' => $jobId,
                'postId' => $postId,
                'queue_length' => $queueLength,
                'queue_method' => 'redis'
            ]);
        } else {
            $queueError = $redisQueue->getLastError() ?: "Enqueue operation failed";
            logQueueAPI("RedisQueue enqueue failed", [
                'jobId' => $jobId,
                'error' => $queueError
            ], 'ERROR');
        }
    } else {
        $queueError = $redisQueue->getLastError() ?: "Redis not connected";
        logQueueAPI("Redis connection failed, trying file fallback", [
            'jobId' => $jobId,
            'error' => $queueError
        ], 'WARNING');
    }
    
    // Fallback to file-based queue if Redis failed
    if (!$queueAdded) {
        logQueueAPI("Using file-based fallback queue", ['jobId' => $jobId]);
        
        $queueDir = __DIR__ . '/queue';
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0775, true);
            logQueueAPI("Queue directory created", ['path' => $queueDir]);
        }
        
        $queueFile = $queueDir . '/' . $jobId . '.json';
        $writeResult = file_put_contents($queueFile, json_encode($jobData, JSON_PRETTY_PRINT));
        
        if ($writeResult !== false) {
            $queueAdded = true;
            $queueMethod = 'file';
            logQueueAPI("Job added to file queue", [
                'jobId' => $jobId,
                'postId' => $postId,
                'file' => $queueFile,
                'bytes' => $writeResult,
                'queue_method' => 'file'
            ]);
        } else {
            $queueError = "Failed to write queue file";
            logQueueAPI("File queue failed", [
                'jobId' => $jobId,
                'file' => $queueFile,
                'error' => $queueError
            ], 'ERROR');
        }
    }
    
} catch (Exception $e) {
    $queueError = $e->getMessage();
    logQueueAPI("Queue exception occurred", [
        'jobId' => $jobId,
        'error' => $queueError,
        'trace' => $e->getTraceAsString()
    ], 'ERROR');
}

// Send Response
if ($queueAdded) {
    logQueueAPI("Job queued successfully", [
        'jobId' => $jobId,
        'postId' => $postId,
        'queue_method' => $queueMethod,
        'status' => 'success'
    ]);
    
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Video queued for processing',
        'jobId' => $jobId,
        'queue_method' => $queueMethod,
        'post_id' => $postId
    ]);
} else {
    logQueueAPI("Job queue failed", [
        'jobId' => $jobId,
        'postId' => $postId,
        'error' => $queueError,
        'status' => 'error'
    ], 'ERROR');
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to queue video for processing: ' . $queueError,
        'jobId' => $jobId
    ]);
}
