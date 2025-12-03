<?php
/**
 * Compress API Health Check
 * URL: https://v.ogtemplate.com/check-compress-api.php
 * Tests the compress endpoint and shows configuration
 */

header('Content-Type: application/json');

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'compress_api' => []
];

// Check if compress.php exists
$compressFile = __DIR__ . '/compress.php';
$response['compress_api']['file_exists'] = file_exists($compressFile);
$response['compress_api']['file_path'] = $compressFile;

if (file_exists($compressFile)) {
    $response['compress_api']['file_size'] = filesize($compressFile);
    $response['compress_api']['file_modified'] = date('Y-m-d H:i:s', filemtime($compressFile));
}

// Check configuration
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    
    $response['configuration'] = [
        'api_key_set' => !empty($config['api_key']) && $config['api_key'] !== 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY',
        'base_url' => $config['base_url'] ?? 'not set',
        'allowed_origins' => $config['allowed_origins'] ?? [],
        'log_file' => $config['log_file'] ?? __DIR__ . '/logs/all.log',
        'wordpress_webhook_url' => $config['wordpress_webhook_url'] ?? 'not set'
    ];
    
    // Don't expose actual API key
    if ($response['configuration']['api_key_set']) {
        $response['configuration']['api_key_preview'] = substr($config['api_key'], 0, 8) . '...' . substr($config['api_key'], -8);
    }
} else {
    $response['configuration'] = ['error' => 'config.php not found'];
}

// Check logs directory
$logsDir = __DIR__ . '/logs';
$response['logs'] = [
    'directory_exists' => is_dir($logsDir),
    'directory_path' => $logsDir
];

if (is_dir($logsDir)) {
    $response['logs']['writable'] = is_writable($logsDir);
    $response['logs']['files'] = [];
    
    $logFiles = glob($logsDir . '/*.log');
    foreach ($logFiles as $logFile) {
        $response['logs']['files'][] = [
            'name' => basename($logFile),
            'size' => filesize($logFile),
            'modified' => date('Y-m-d H:i:s', filemtime($logFile)),
            'lines' => count(file($logFile))
        ];
    }
} else {
    $response['logs']['message'] = 'Logs directory will be created on first API call';
}

// Check Redis connection
require_once __DIR__ . '/RedisQueue.php';
$queue = new RedisQueue();

$response['redis'] = [
    'connected' => $queue->isConnected(),
    'error' => $queue->getLastError()
];

if ($queue->isConnected()) {
    $stats = $queue->getStats();
    $response['redis']['queue_stats'] = [
        'pending' => $stats['pending'] ?? 0,
        'processing' => $stats['processing'] ?? 0,
        'completed' => $stats['completed'] ?? 0,
        'failed' => $stats['failed'] ?? 0
    ];
}

// Check recent WordPress requests (from Apache logs if available)
$response['recent_activity'] = [
    'message' => 'Check Docker logs for recent POST requests to /compress.php or /index.php'
];

// Recommendations
$response['recommendations'] = [];

if (!$response['configuration']['api_key_set']) {
    $response['recommendations'][] = '[WARNING] Set API_KEY in environment variables';
}

if (!$response['redis']['connected']) {
    $response['recommendations'][] = '[WARNING] Redis not connected - jobs will use file-based fallback';
}

if (empty($response['logs']['files'])) {
    $response['recommendations'][] = '[INFO] No log files yet - compress API has not been called';
}

echo json_encode($response, JSON_PRETTY_PRINT);
