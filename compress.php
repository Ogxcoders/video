<?php
/**
 * Compression API Endpoint
 * Receives video compression jobs from WordPress plugin and queues them
 * Endpoint: POST https://v.ogtemplate.com/api/compress
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
        error_log("[COMPRESS] Fatal error: {$error['message']} in {$error['file']}:{$error['line']}");
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
function logCompress($message, $context = [], $level = 'INFO') {
    try {
        $logFile = __DIR__ . '/logs/all.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0777, true)) {
                error_log("[COMPRESS] Failed to create log directory: {$logDir}");
                return false;
            }
            chmod($logDir, 0777);
        }
        
        if (!file_exists($logFile)) {
            if (!touch($logFile)) {
                error_log("[COMPRESS] Failed to create log file: {$logFile}");
                return false;
            }
            chmod($logFile, 0666);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] [COMPRESS] {$message}{$contextStr}\n";
        
        $result = file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log("[COMPRESS] Failed to write to log file: {$logFile}");
            return false;
        }
        
        chmod($logFile, 0666);
        return true;
    } catch (Exception $e) {
        error_log("[COMPRESS] Logging exception: " . $e->getMessage());
        return false;
    }
}

// Log incoming request
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

logCompress("Request received", [
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
    logCompress("Preflight request handled", ['origin' => $origin]);
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logCompress("Invalid method rejected", ['method' => $requestMethod]);
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// API Key Validation
if (empty($config['api_key']) || $config['api_key'] === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
    logCompress("API key not configured in server", ['ip' => $remoteAddr]);
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
    logCompress("Authentication failed", [
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

logCompress("Authentication successful", ['ip' => $remoteAddr]);

// Parse JSON Request
$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logCompress("Invalid JSON in request", [
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
logCompress("FULL REQUEST DATA RECEIVED", [
    'all_keys' => array_keys($data),
    'postId' => $data['postId'] ?? 'NOT_SET',
    'wpVideoUrl' => $data['wpVideoUrl'] ?? 'NOT_SET',
    'wpMediaPath' => $data['wpMediaPath'] ?? 'NOT_SET',
    'wpThumbnailUrl' => $data['wpThumbnailUrl'] ?? 'NOT_SET',
    'wpThumbnailPath' => $data['wpThumbnailPath'] ?? 'NOT_SET',
    'year' => $data['year'] ?? 'NOT_SET',
    'month' => $data['month'] ?? 'NOT_SET'
]);

// Validate Required Fields (wpThumbnailPath is optional)
$requiredFields = ['postId', 'wpMediaPath', 'year', 'month'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
        $missingFields[] = $field;
    }
}

if (!empty($missingFields)) {
    logCompress("Validation failed: missing fields", [
        'missing_fields' => $missingFields,
        'ip' => $remoteAddr
    ]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required fields: ' . implode(', ', $missingFields)
    ]);
    exit;
}

// Extract and Validate Data
$postId = (int)$data['postId'];
$wpMediaPath = trim($data['wpMediaPath']);
$wpVideoUrl = !empty($data['wpVideoUrl']) ? trim($data['wpVideoUrl']) : '';
$wpThumbnailPath = !empty($data['wpThumbnailPath']) ? trim($data['wpThumbnailPath']) : '';
$wpThumbnailUrl = !empty($data['wpThumbnailUrl']) ? trim($data['wpThumbnailUrl']) : '';
$year = (int)$data['year'];
$month = (int)$data['month'];

// Validate URLs if provided
if (!empty($wpVideoUrl) && !filter_var($wpVideoUrl, FILTER_VALIDATE_URL)) {
    logCompress("Validation failed: invalid wpVideoUrl", [
        'wpVideoUrl' => $wpVideoUrl,
        'ip' => $remoteAddr
    ]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid wpVideoUrl format'
    ]);
    exit;
}

// Validate that wpVideoUrl is provided when wpMediaPath is a remote/relative path
// The worker needs a downloadable URL to fetch the video
if (empty($wpVideoUrl)) {
    // Check if wpMediaPath looks like a local path that the VPS can access directly
    $mediaUploadsDir = $config['media_uploads_dir'] ?? __DIR__ . '/media/uploads';
    $localVideoPath = $mediaUploadsDir . '/' . ltrim(preg_replace('#^/?wp-content/uploads/?#', '', $wpMediaPath), '/');
    
    if (!file_exists($localVideoPath)) {
        logCompress("Validation failed: wpVideoUrl required for remote processing", [
            'wpMediaPath' => $wpMediaPath,
            'localVideoPath' => $localVideoPath,
            'exists' => false,
            'ip' => $remoteAddr
        ]);
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'wpVideoUrl is required when video file is not accessible locally on VPS'
        ]);
        exit;
    }
    
    logCompress("Local video file found, wpVideoUrl not required", [
        'localVideoPath' => $localVideoPath
    ]);
}

if (!empty($wpThumbnailUrl) && !filter_var($wpThumbnailUrl, FILTER_VALIDATE_URL)) {
    logCompress("Validation failed: invalid wpThumbnailUrl", [
        'wpThumbnailUrl' => $wpThumbnailUrl,
        'ip' => $remoteAddr
    ]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid wpThumbnailUrl format'
    ]);
    exit;
}

// If wpThumbnailPath is missing, extract from thumbnail URL (same as video handling)
if (empty($wpThumbnailPath) && !empty($wpThumbnailUrl)) {
    $parsedUrl = parse_url($wpThumbnailUrl);
    if (isset($parsedUrl['path'])) {
        $wpThumbnailPath = $parsedUrl['path'];
        logCompress("Extracted wpThumbnailPath from wpThumbnailUrl", [
            'wpThumbnailPath' => $wpThumbnailPath,
            'wpThumbnailUrl' => $wpThumbnailUrl
        ]);
    }
}

if ($postId <= 0) {
    logCompress("Validation failed: invalid postId", [
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

if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    logCompress("Validation failed: invalid date", [
        'year' => $year,
        'month' => $month,
        'ip' => $remoteAddr
    ]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid year or month values'
    ]);
    exit;
}

logCompress("Request validated successfully", [
    'postId' => $postId,
    'year' => $year,
    'month' => $month
]);

// Generate Unique Job ID
$timestamp = time();
$jobId = "job_{$postId}_{$timestamp}";

logCompress("Job ID generated", [
    'jobId' => $jobId,
    'postId' => $postId,
    'timestamp' => $timestamp
]);

// Prepare Job Data for Queue
$jobData = [
    'jobId' => $jobId,
    'postId' => $postId,
    'wpMediaPath' => $wpMediaPath,
    'wpVideoUrl' => $wpVideoUrl,
    'wpThumbnailPath' => $wpThumbnailPath,
    'wpThumbnailUrl' => $wpThumbnailUrl,
    'year' => $year,
    'month' => $month,
    'status' => 'pending',
    'createdAt' => date('Y-m-d H:i:s'),
    'updatedAt' => date('Y-m-d H:i:s')
];

logCompress("Job data prepared", [
    'jobId' => $jobId,
    'postId' => $postId,
    'wpMediaPath' => $wpMediaPath,
    'wpVideoUrl' => $wpVideoUrl,
    'wpThumbnailPath' => $wpThumbnailPath,
    'wpThumbnailUrl' => $wpThumbnailUrl
]);

// Attempt to Add to Redis Queue using RedisQueue class - with graceful error handling
try {
    require_once __DIR__ . '/RedisQueue.php';
} catch (Throwable $e) {
    logCompress("WARNING: RedisQueue.php load failed, using file fallback", [
        'error' => $e->getMessage()
    ], 'WARNING');
}

$queueAdded = false;
$queueError = null;
$queueMethod = null;

try {
    logCompress("Initializing RedisQueue", ['jobId' => $jobId]);
    
    // Initialize RedisQueue with config
    $redisQueue = new RedisQueue([
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => getenv('REDIS_PORT') ?: 6379,
        'queue_name' => 'compression_queue',
        'log_file' => __DIR__ . '/logs/all.log'
    ]);
    
    // Check if Redis is connected
    if ($redisQueue->isConnected()) {
        logCompress("RedisQueue connected successfully", ['jobId' => $jobId]);
        
        // Enqueue job with full status tracking
        $enqueueResult = $redisQueue->enqueue($jobData);
        
        if ($enqueueResult) {
            $queueAdded = true;
            $queueMethod = 'redis';
            $queueLength = $redisQueue->getQueueLength();
            
            logCompress("Job added to Redis queue via RedisQueue class", [
                'jobId' => $jobId,
                'postId' => $postId,
                'queue_length' => $queueLength
            ]);
        } else {
            $queueError = $redisQueue->getLastError() ?: "RedisQueue enqueue operation failed (queue full or internal error)";
            logCompress("RedisQueue enqueue failed", [
                'jobId' => $jobId,
                'error' => $queueError
            ]);
        }
    } else {
        $queueError = $redisQueue->getLastError() ?: "Redis server not connected or extension not loaded";
        logCompress("RedisQueue connection unavailable, falling back to file queue", [
            'jobId' => $jobId,
            'redis_extension_loaded' => extension_loaded('redis'),
            'error' => $queueError
        ]);
    }
    
    // Fallback to file-based queue if Redis didn't work
    if (!$queueAdded) {
        logCompress("Using file-based fallback queue", ['jobId' => $jobId]);
        
        $queueDir = __DIR__ . '/queue';
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0775, true);
            logCompress("Queue directory created", ['path' => $queueDir]);
        }
        
        $queueFile = $queueDir . '/' . $jobId . '.json';
        $writeResult = file_put_contents($queueFile, json_encode($jobData, JSON_PRETTY_PRINT));
        
        if ($writeResult !== false) {
            $queueAdded = true;
            $queueMethod = 'file';
            logCompress("Job added to file-based queue successfully", [
                'jobId' => $jobId,
                'postId' => $postId,
                'file' => $queueFile,
                'bytes' => $writeResult
            ]);
        } else {
            $queueError = "Failed to write queue file";
            logCompress("File-based queue failed", [
                'jobId' => $jobId,
                'file' => $queueFile,
                'error' => $queueError
            ]);
        }
    }
    
} catch (Exception $e) {
    $queueError = $e->getMessage();
    logCompress("Queue exception occurred", [
        'jobId' => $jobId,
        'error' => $queueError,
        'trace' => $e->getTraceAsString()
    ], 'ERROR');
    $queueAdded = false;
}

// Return Response
if ($queueAdded) {
    $response = [
        'status' => 'success',
        'message' => 'Compression job queued successfully',
        'jobId' => $jobId,
        'postId' => $postId,
        'queuedAt' => $jobData['createdAt'],
        'year' => $year,
        'month' => $month
    ];
    
    logCompress("Success response sent", [
        'jobId' => $jobId,
        'postId' => $postId,
        'queue_method' => $queueMethod,
        'http_code' => 200
    ]);
    
    http_response_code(200);
    echo json_encode($response);
} else {
    $response = [
        'status' => 'accepted',
        'message' => 'Job received but queue service unavailable. Job will be processed when queue is available.',
        'jobId' => $jobId,
        'postId' => $postId,
        'warning' => $queueError
    ];
    
    logCompress("Accepted response sent (queue unavailable)", [
        'jobId' => $jobId,
        'postId' => $postId,
        'error' => $queueError,
        'http_code' => 202
    ]);
    
    http_response_code(202);
    echo json_encode($response);
}

logCompress("Request completed", [
    'jobId' => $jobId,
    'postId' => $postId,
    'success' => $queueAdded
]);

exit;
