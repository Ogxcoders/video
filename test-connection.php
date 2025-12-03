<?php
/**
 * Simple Connection & Authentication Test
 * Quick smoke test for API connectivity
 * 
 * Usage: 
 *   Browser: https://v.ogtemplate.com/test-connection.php
 *   CLI: php test-connection.php
 */

// Determine if running from CLI or web
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    header('Content-Type: application/json');
}

$config = require __DIR__ . '/config.php';

// Build response
$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => 'success',
    'message' => 'Connection test successful',
    'server_info' => [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
        'document_root' => __DIR__
    ]
];

// Test Redis connection
$redisConnected = false;
$redisError = null;

if (extension_loaded('redis')) {
    try {
        require_once __DIR__ . '/RedisQueue.php';
        $queue = new RedisQueue();
        $redisConnected = $queue->isConnected();
        $redisError = $queue->getLastError();
        
        if ($redisConnected) {
            $stats = $queue->getStats();
            $response['redis'] = [
                'connected' => true,
                'version' => $stats['redis_version'] ?? 'unknown',
                'uptime_seconds' => $stats['uptime_seconds'] ?? 0,
                'queue_pending' => $stats['pending'] ?? 0
            ];
        } else {
            $response['redis'] = [
                'connected' => false,
                'error' => $redisError ?: 'Connection failed'
            ];
        }
    } catch (Exception $e) {
        $response['redis'] = [
            'connected' => false,
            'error' => $e->getMessage()
        ];
    }
} else {
    $response['redis'] = [
        'connected' => false,
        'error' => 'Redis extension not loaded'
    ];
}

// Test FFmpeg
$ffmpegPath = $config['ffmpeg_binary'] ?? '/usr/bin/ffmpeg';
exec(escapeshellarg($ffmpegPath) . ' -version 2>&1', $output, $returnCode);
$response['ffmpeg'] = [
    'installed' => ($returnCode === 0),
    'path' => $ffmpegPath,
    'version' => ($returnCode === 0) ? trim(explode("\n", implode("\n", $output))[0]) : 'Not found'
];

// Test directories
$response['directories'] = [
    'videos' => [
        'path' => $config['videos_dir'] ?? __DIR__ . '/videos',
        'exists' => is_dir($config['videos_dir'] ?? __DIR__ . '/videos'),
        'writable' => is_writable($config['videos_dir'] ?? __DIR__ . '/videos')
    ],
    'hls' => [
        'path' => $config['hls_dir'] ?? __DIR__ . '/hls',
        'exists' => is_dir($config['hls_dir'] ?? __DIR__ . '/hls'),
        'writable' => is_writable($config['hls_dir'] ?? __DIR__ . '/hls')
    ],
    'logs' => [
        'path' => __DIR__ . '/logs',
        'exists' => is_dir(__DIR__ . '/logs'),
        'writable' => is_writable(__DIR__ . '/logs')
    ]
];

// Test API key configuration
$apiKeyConfigured = !empty($config['api_key']) && 
                    $config['api_key'] !== 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY';

$response['configuration'] = [
    'api_key_set' => $apiKeyConfigured,
    'base_url' => $config['base_url'] ?? 'not set',
    'debug_mode' => $config['debug'] ?? false
];

// Check authentication if API key provided
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $providedKey = $_SERVER['HTTP_X_API_KEY'];
    
    if (!$apiKeyConfigured) {
        http_response_code(500);
        $response['status'] = 'error';
        $response['message'] = 'API key not configured on server';
    } elseif ($providedKey !== $config['api_key']) {
        http_response_code(401);
        $response['status'] = 'error';
        $response['message'] = 'Invalid API key';
    } else {
        $response['authentication'] = 'valid';
    }
}

// Health status
$allGood = $redisConnected && 
           $response['ffmpeg']['installed'] && 
           $response['directories']['videos']['writable'] &&
           $response['directories']['hls']['writable'] &&
           $apiKeyConfigured;

$response['health'] = $allGood ? 'healthy' : 'degraded';

// Output
if ($isCLI) {
    echo "==========================================\n";
    echo "  Connection Test Results\n";
    echo "==========================================\n\n";
    
    echo "Status: {$response['status']}\n";
    echo "Health: {$response['health']}\n\n";
    
    echo "Redis: " . ($redisConnected ? "✓ Connected" : "✗ Not connected") . "\n";
    if (!$redisConnected && $redisError) {
        echo "  Error: $redisError\n";
    }
    
    echo "FFmpeg: " . ($response['ffmpeg']['installed'] ? "✓ Installed" : "✗ Not found") . "\n";
    echo "API Key: " . ($apiKeyConfigured ? "✓ Configured" : "⚠️  Using default") . "\n\n";
    
    echo "For detailed output, use: php run-tests.php\n\n";
} else {
    echo json_encode($response, JSON_PRETTY_PRINT);
}

// Exit with appropriate code for CLI
if ($isCLI) {
    exit($allGood ? 0 : 1);
}
