<?php
/**
 * Redis Health Check Endpoint
 * Provides detailed Redis status for monitoring and debugging
 * URL: https://v.ogtemplate.com/redis-health.php
 * 
 * Authentication: Requires API key via X-API-Key header or api_key query param
 */

header('Content-Type: application/json');

// Load config for API key
$config = require __DIR__ . '/config.php';

// API Key Authentication
$apiKey = $config['api_key'] ?? '';
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';

// Allow unauthenticated access only for basic health check (no sensitive data)
$authenticated = !empty($apiKey) && !empty($providedKey) && hash_equals($apiKey, $providedKey);

// If not authenticated, only return basic status
if (!$authenticated) {
    // Basic health check without sensitive queue information
    echo json_encode([
        'status' => 'ok',
        'message' => 'Health endpoint is accessible. Provide API key for detailed info.',
        'timestamp' => date('Y-m-d H:i:s'),
        'authenticated' => false
    ], JSON_PRETTY_PRINT);
    exit;
}

require_once __DIR__ . '/RedisQueue.php';

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'authenticated' => true,
    'redis' => []
];

// Check if Redis extension or Predis is available
$response['redis']['extension_loaded'] = extension_loaded('redis');
$response['redis']['predis_available'] = class_exists('Predis\Client');

if (!$response['redis']['extension_loaded'] && !$response['redis']['predis_available']) {
    $response['status'] = 'error';
    $response['message'] = 'No Redis client available (neither native extension nor Predis)';
    http_response_code(503);
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Try to connect to Redis
try {
    if (extension_loaded('redis')) {
        $redis = new Redis();
        $connected = $redis->connect('127.0.0.1', 6379, 2);
        
        if (!$connected) {
            throw new Exception('Connection failed');
        }
        
        $pong = $redis->ping();
        $response['redis']['ping'] = ($pong === '+PONG' || $pong === true) ? 'PONG' : 'FAILED';
        
        $info = $redis->info();
        $response['redis']['client'] = 'native';
    } else {
        $redis = new Predis\Client([
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 2
        ]);
        $redis->connect();
        
        $pong = $redis->ping();
        $response['redis']['ping'] = ($pong == 'PONG') ? 'PONG' : 'FAILED';
        
        $info = $redis->info();
        $response['redis']['client'] = 'predis';
    }
    
    $response['redis']['version'] = $info['redis_version'] ?? (isset($info['Server']['redis_version']) ? $info['Server']['redis_version'] : 'unknown');
    $response['redis']['uptime_seconds'] = $info['uptime_in_seconds'] ?? (isset($info['Server']['uptime_in_seconds']) ? $info['Server']['uptime_in_seconds'] : 0);
    $response['redis']['uptime_days'] = isset($info['uptime_in_days']) ? $info['uptime_in_days'] : (isset($info['Server']['uptime_in_days']) ? $info['Server']['uptime_in_days'] : 0);
    $response['redis']['used_memory'] = $info['used_memory_human'] ?? (isset($info['Memory']['used_memory_human']) ? $info['Memory']['used_memory_human'] : 'unknown');
    $response['redis']['connected_clients'] = $info['connected_clients'] ?? (isset($info['Clients']['connected_clients']) ? $info['Clients']['connected_clients'] : 0);
    $response['redis']['role'] = $info['role'] ?? (isset($info['Replication']['role']) ? $info['Replication']['role'] : 'unknown');
    
    // Test RedisQueue class
    $queue = new RedisQueue([
        'host' => '127.0.0.1',
        'port' => 6379,
        'queue_name' => 'compression_queue'
    ]);
    
    if ($queue->isConnected()) {
        $stats = $queue->getStats();
        $response['queue'] = [
            'connected' => true,
            'pending_jobs' => $stats['pending'] ?? 0,
            'processing_jobs' => $stats['processing'] ?? 0,
            'completed_jobs' => $stats['completed'] ?? 0,
            'failed_jobs' => $stats['failed'] ?? 0,
            'total_jobs' => $stats['total'] ?? 0
        ];
    } else {
        $response['queue'] = [
            'connected' => false,
            'error' => $queue->getLastError() ?? 'Unknown error'
        ];
    }
    
    $response['status'] = 'healthy';
    $response['message'] = 'Redis is running and queue system is operational';
    http_response_code(200);
    
    if (extension_loaded('redis')) {
        $redis->close();
    }
    
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = 'Redis connection failed: ' . $e->getMessage();
    $response['redis']['error'] = $e->getMessage();
    $response['redis']['connected'] = false;
    http_response_code(503);
}

echo json_encode($response, JSON_PRETTY_PRINT);
