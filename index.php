<?php
/**
 * VPS-API Homepage & Video Processing Endpoint
 * GET requests show API documentation
 * POST requests process videos (legacy endpoint)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

// Show API documentation on GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    logAPI("GET request for API documentation", [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    require __DIR__ . '/home.php';
    exit;
}

$config = require __DIR__ . '/config.php';

/**
 * Logging Helper Function
 */
function logAPI($message, $context = [], $level = 'INFO') {
    try {
        $logFile = __DIR__ . '/logs/all.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0777, true)) {
                error_log("[API] Failed to create log directory: {$logDir}");
                return false;
            }
            chmod($logDir, 0777);
        }
        
        if (!file_exists($logFile)) {
            if (!touch($logFile)) {
                error_log("[API] Failed to create log file: {$logFile}");
                return false;
            }
            chmod($logFile, 0666);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] [API] {$message}{$contextStr}\n";
        
        $result = file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log("[API] Failed to write to log file: {$logFile}");
            return false;
        }
        
        chmod($logFile, 0666);
        return true;
    } catch (Exception $e) {
        error_log("[API] Logging exception: " . $e->getMessage());
        return false;
    }
}

$allowedOrigins = $config['allowed_origins'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    logAPI("CORS preflight request", ['origin' => $origin]);
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logAPI("Invalid method rejected", [
        'method' => $_SERVER['REQUEST_METHOD'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed. Use POST for video processing or GET for API documentation.'
    ]);
    exit;
}

logAPI("Video processing request received", [
    'method' => 'POST',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
]);

if (empty($config['api_key']) || $config['api_key'] === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
    logAPI("API key not configured", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server configuration error: API key not configured'
    ]);
    exit;
}

$providedKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';

if ($providedKey !== $config['api_key']) {
    logAPI("Authentication failed", [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'key_provided' => !empty($providedKey)
    ]);
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing API key'
    ]);
    exit;
}

logAPI("Authentication successful", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logAPI("Invalid JSON in request", [
        'error' => json_last_error_msg(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid JSON in request body'
    ]);
    exit;
}

// Support both video_url and wpVideoUrl field names for compatibility
$videoUrl = $data['video_url'] ?? $data['wpVideoUrl'] ?? '';

if (empty($videoUrl)) {
    logAPI("Missing video_url field", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required field: video_url'
    ]);
    exit;
}

// Support both post_id and postId field names
$postId = isset($data['post_id']) ? (int)$data['post_id'] : (isset($data['postId']) ? (int)$data['postId'] : 0);

// Extract all job data from request
$wpMediaPath = $data['wpMediaPath'] ?? '';
$wpThumbnailPath = $data['wpThumbnailPath'] ?? '';
$wpThumbnailUrl = $data['wpThumbnailUrl'] ?? '';
$wpPostUrl = $data['wpPostUrl'] ?? '';
$year = isset($data['year']) ? (int)$data['year'] : 0;
$month = isset($data['month']) ? (int)$data['month'] : 0;

// If wpMediaPath is missing, extract from video URL
if (empty($wpMediaPath) && !empty($videoUrl)) {
    $parsedUrl = parse_url($videoUrl);
    if (isset($parsedUrl['path'])) {
        $wpMediaPath = $parsedUrl['path'];
    }
}

// If wpThumbnailPath is missing, extract from thumbnail URL (same as video handling)
if (empty($wpThumbnailPath) && !empty($wpThumbnailUrl)) {
    $parsedUrl = parse_url($wpThumbnailUrl);
    if (isset($parsedUrl['path'])) {
        $wpThumbnailPath = $parsedUrl['path'];
    }
}

// If year/month are missing, try to extract from path or use current date
if ($year <= 0 || $month <= 0) {
    $pathToCheck = !empty($wpMediaPath) ? $wpMediaPath : $videoUrl;
    if (preg_match('#/(\d{4})/(\d{1,2})/#', $pathToCheck, $matches)) {
        $extractedYear = (int)$matches[1];
        $extractedMonth = (int)$matches[2];
        if ($extractedYear >= 2000 && $extractedYear <= 2100 && $extractedMonth >= 1 && $extractedMonth <= 12) {
            if ($year <= 0) $year = $extractedYear;
            if ($month <= 0) $month = $extractedMonth;
        }
    }
    if ($year <= 0) $year = (int)date('Y');
    if ($month <= 0) $month = (int)date('m');
}

logAPI("Processing video request via queue", [
    'video_url' => $videoUrl,
    'post_id' => $postId,
    'wpMediaPath' => $wpMediaPath,
    'year' => $year,
    'month' => $month,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);

// Generate unique job ID
$timestamp = time();
$jobId = "job_{$postId}_{$timestamp}";

// Prepare job data for queue
$jobData = [
    'jobId' => $jobId,
    'postId' => $postId,
    'wpMediaPath' => $wpMediaPath,
    'wpVideoUrl' => $videoUrl,
    'wpThumbnailPath' => $wpThumbnailPath,
    'wpThumbnailUrl' => $wpThumbnailUrl,
    'wpPostUrl' => $wpPostUrl,
    'year' => $year,
    'month' => $month,
    'status' => 'pending',
    'createdAt' => date('Y-m-d H:i:s'),
    'updatedAt' => date('Y-m-d H:i:s')
];

try {
    require_once __DIR__ . '/RedisQueue.php';
    
    $redisQueue = new RedisQueue([
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => getenv('REDIS_PORT') ?: 6379,
        'queue_name' => 'compression_queue',
        'log_file' => __DIR__ . '/logs/all.log'
    ]);
    
    if ($redisQueue->isConnected()) {
        $enqueueResult = $redisQueue->enqueue($jobData);
        
        if ($enqueueResult) {
            $queueLength = $redisQueue->getQueueLength();
            
            logAPI("Job queued successfully", [
                'jobId' => $jobId,
                'postId' => $postId,
                'queue_length' => $queueLength
            ]);
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'message' => 'Video queued for processing',
                'jobId' => $jobId,
                'postId' => $postId,
                'queue_position' => $queueLength
            ]);
        } else {
            throw new Exception("Failed to add job to Redis queue");
        }
    } else {
        throw new Exception("Redis connection not available");
    }
    
} catch (Exception $e) {
    logAPI("Exception during job queueing", [
        'post_id' => $postId,
        'error' => $e->getMessage()
    ], 'ERROR');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
