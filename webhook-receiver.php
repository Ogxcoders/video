<?php
/**
 * WordPress Webhook Receiver (For Testing)
 * Simulates WordPress receiving compression completion webhook
 * 
 * In production, this endpoint would be on the WordPress site
 * URL: https://ogtemplate.com/wp-json/compression/v1/webhook
 */

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';

/**
 * Logging Helper Function
 */
function logWebhook($message, $context = [], $level = 'INFO') {
    try {
        $logFile = __DIR__ . '/logs/all.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0777, true)) {
                error_log("[WEBHOOK] Failed to create log directory: {$logDir}");
                return false;
            }
            chmod($logDir, 0777);
        }
        
        if (!file_exists($logFile)) {
            if (!touch($logFile)) {
                error_log("[WEBHOOK] Failed to create log file: {$logFile}");
                return false;
            }
            chmod($logFile, 0666);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] [WEBHOOK] {$message}{$contextStr}\n";
        
        $result = file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log("[WEBHOOK] Failed to write to log file: {$logFile}");
            return false;
        }
        
        chmod($logFile, 0666);
        return true;
    } catch (Exception $e) {
        error_log("[WEBHOOK] Logging exception: " . $e->getMessage());
        return false;
    }
}

// CORS
$allowedOrigins = $config['allowed_origins'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    logWebhook("CORS preflight request", ['origin' => $origin]);
    http_response_code(200);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logWebhook("Invalid method rejected", ['method' => $_SERVER['REQUEST_METHOD']], 'WARNING');
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    logWebhook("Invalid JSON payload received", [], 'ERROR');
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid JSON payload'
    ]);
    exit;
}

// Log the webhook
logWebhook("Webhook received", [
    'jobId' => $data['jobId'] ?? null,
    'postId' => $data['postId'] ?? null,
    'status' => $data['status'] ?? 'unknown'
]);

// Simulate WordPress post meta update
$response = [
    'status' => 'success',
    'message' => 'Webhook received and processed',
    'data' => [
        'postId' => $data['postId'] ?? null,
        'jobId' => $data['jobId'] ?? null,
        'updated_meta' => [
            '_compression_status' => $data['status'] ?? 'unknown',
            '_compressed_video_480p' => $data['compressed_video_480p'] ?? '',
            '_original_file_size' => $data['original_size'] ?? 0,
            '_compressed_file_size' => $data['compressed_size'] ?? 0,
            '_compression_ratio' => $data['compression_ratio'] ?? 0,
            '_video_duration' => $data['duration'] ?? 0,
            '_processing_completed_at' => $data['completed_at'] ?? date('Y-m-d H:i:s')
        ]
    ],
    'timestamp' => date('Y-m-d H:i:s')
];

logWebhook("Webhook processed successfully", [
    'jobId' => $data['jobId'] ?? null,
    'postId' => $data['postId'] ?? null
]);

// In production, this would actually update WordPress post meta:
// update_post_meta($postId, '_compression_status', 'completed');
// update_post_meta($postId, '_compressed_video_480p', $compressed_url);
// etc.

echo json_encode($response, JSON_PRETTY_PRINT);
