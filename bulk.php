<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/RedisQueue.php';

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log("Bulk processing endpoint called");

// CORS headers
header('Access-Control-Allow-Origin: ' . (getenv('ALLOWED_ORIGINS') ?: '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

/**
 * Check rate limit
 */
function checkRateLimit($ip) {
    try {
        $redis = new Redis();
        $redis->connect(getenv('REDIS_HOST') ?: 'redis', getenv('REDIS_PORT') ?: 6379);
        
        $key = "rate_limit:bulk:{$ip}";
        $count = $redis->incr($key);
        
        if ($count === 1) {
            $redis->expire($key, 3600); // 1 hour window
        }
        
        // Max 5 bulk requests per hour
        if ($count > 5) {
            http_response_code(429);
            header('Retry-After: 3600');
            die(json_encode([
                'error' => 'Rate limit exceeded',
                'message' => 'Maximum 5 bulk requests per hour',
                'retry_after' => 3600
            ]));
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Rate limit check failed: " . $e->getMessage());
        return true; // Allow on failure
    }
}

/**
 * Validate job data
 */
function validateJobData($job, $index) {
    $errors = [];
    
    // Validate post_id
    if (empty($job['post_id']) || !is_numeric($job['post_id'])) {
        $errors[] = "Invalid or missing post_id at index {$index}";
    }
    
    // Validate video_url
    if (empty($job['video_url']) || !filter_var($job['video_url'], FILTER_VALIDATE_URL)) {
        $errors[] = "Invalid or missing video_url at index {$index}";
    } else {
        // Check video file extension
        $allowedVideoExts = ['mp4', 'mov', 'avi', 'webm', 'mkv'];
        $ext = strtolower(pathinfo(parse_url($job['video_url'], PHP_URL_PATH), PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowedVideoExts)) {
            $errors[] = "Invalid video format at index {$index}. Allowed: " . implode(', ', $allowedVideoExts);
        }
    }
    
    // Validate thumbnail_url
    if (empty($job['thumbnail_url']) || !filter_var($job['thumbnail_url'], FILTER_VALIDATE_URL)) {
        $errors[] = "Invalid or missing thumbnail_url at index {$index}";
    } else {
        // Check image file extension
        $allowedImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo(parse_url($job['thumbnail_url'], PHP_URL_PATH), PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowedImageExts)) {
            $errors[] = "Invalid thumbnail format at index {$index}. Allowed: " . implode(', ', $allowedImageExts);
        }
    }
    
    return $errors;
}

try {
    // Validate API key
    validateApiKey();
    
    // Check rate limit
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    checkRateLimit($clientIp);
    
    // Get request body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        die(json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]));
    }
    
    // Validate request structure
    if (!isset($data['videos']) || !is_array($data['videos'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Missing or invalid "videos" array']));
    }
    
    $videos = $data['videos'];
    $totalVideos = count($videos);
    
    // Validate video count
    if ($totalVideos === 0) {
        http_response_code(400);
        die(json_encode(['error' => 'Empty videos array']));
    }
    
    if ($totalVideos > 10000) {
        http_response_code(400);
        die(json_encode(['error' => 'Maximum 10,000 videos per request']));
    }
    
    // Validate each job
    $allErrors = [];
    foreach ($videos as $index => $job) {
        $errors = validateJobData($job, $index);
        if (!empty($errors)) {
            $allErrors = array_merge($allErrors, $errors);
        }
    }
    
    // Return validation errors
    if (!empty($allErrors)) {
        http_response_code(400);
        die(json_encode([
            'error' => 'Validation failed',
            'validation_errors' => $allErrors,
            'total_errors' => count($allErrors)
        ]));
    }
    
    // Prepare jobs for queue
    $webhookUrl = getenv('WORDPRESS_WEBHOOK_URL') ?: 'https://ogtemplate.com/wp-json/cvp/v1/webhook';
    
    $jobs = array_map(function($video) use ($webhookUrl) {
        return [
            'post_id' => (int)$video['post_id'],
            'video_url' => $video['video_url'],
            'thumbnail_url' => $video['thumbnail_url'],
            'webhook_url' => $webhookUrl
        ];
    }, $videos);
    
    // Add jobs to queue
    $queue = new RedisQueue();
    $result = $queue->addBulkJobs($jobs);
    
    // Log request
    error_log("Bulk processing: {$totalVideos} videos queued in batch {$result['batch_id']} from IP {$clientIp}");
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => "{$totalVideos} videos queued for processing",
        'batch_id' => $result['batch_id'],
        'total_jobs' => $result['total'],
        'job_ids' => $result['job_ids'],
        'estimated_completion' => calculateEstimatedCompletion($totalVideos)
    ]);
    
} catch (Exception $e) {
    error_log("Bulk processing error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'Failed to queue jobs. Please try again later.'
    ]);
}

/**
 * Calculate estimated completion time
 * 
 * @param int $jobCount - Number of jobs
 * @return string - Human-readable estimate
 */
function calculateEstimatedCompletion($jobCount) {
    $workerCount = (int)(getenv('WORKER_COUNT') ?: 5);
    $avgProcessingTime = 120; // 2 minutes per video
    
    $totalSeconds = ($jobCount / $workerCount) * $avgProcessingTime;
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    
    if ($hours > 0) {
        return "{$hours} hours {$minutes} minutes";
    } else {
        return "{$minutes} minutes";
    }
}
?>
