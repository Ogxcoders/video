<?php
/**
 * Connection Test Endpoint
 * Tests API connectivity and authentication
 */

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';

$allowedOrigins = $config['allowed_origins'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$providedKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';

if (empty($config['api_key']) || $config['api_key'] === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'API key not configured on server'
    ]);
    exit;
}

if ($providedKey !== $config['api_key']) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or missing API key'
    ]);
    exit;
}

exec(escapeshellarg($config['ffmpeg_binary']) . ' -version 2>&1', $output, $returnCode);
$ffmpegInstalled = ($returnCode === 0);

http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'Connection successful',
    'server_info' => [
        'php_version' => PHP_VERSION,
        'ffmpeg_installed' => $ffmpegInstalled,
        'ffmpeg_path' => $config['ffmpeg_binary'],
        'videos_dir_writable' => is_writable($config['videos_dir']),
        'hls_dir_writable' => is_writable($config['hls_dir'])
    ]
]);
