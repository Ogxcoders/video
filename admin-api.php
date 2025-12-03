<?php
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/RedisQueue.php';

$adminPassword = $config['admin_password'] ?? 'admin123';
$providedPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? $_GET['password'] ?? '';
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$configApiKey = $config['api_key'] ?? '';
$action = $_GET['action'] ?? 'status';

$defaultPasswords = ['admin123', ''];
$defaultApiKeys = ['CHANGE_ME_TO_A_SECURE_RANDOM_KEY', ''];

$destructiveActions = ['recover', 'clear-processing', 'clear-all', 'clear-dead-letter'];
$requiresBothCredentials = in_array($action, $destructiveActions);

$passwordValid = !empty($providedPassword) && 
    !in_array($adminPassword, $defaultPasswords) && 
    hash_equals($adminPassword, $providedPassword);

$apiKeyValid = !empty($apiKey) && 
    !empty($configApiKey) && 
    !in_array($configApiKey, $defaultApiKeys) && 
    hash_equals($configApiKey, $apiKey);

$authenticated = false;
if ($requiresBothCredentials) {
    $authenticated = $passwordValid && $apiKeyValid;
    if (!$authenticated && ($passwordValid || $apiKeyValid)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Destructive actions require BOTH X-Admin-Password AND X-API-Key headers.',
            'action' => $action,
            'password_valid' => $passwordValid,
            'api_key_valid' => $apiKeyValid
        ]);
        exit;
    }
} else {
    $authenticated = $passwordValid || $apiKeyValid;
}

if (!$authenticated) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized. Provide X-Admin-Password or X-API-Key header (non-default values required).',
        'hints' => [
            'admin_password_is_default' => in_array($adminPassword, $defaultPasswords),
            'api_key_is_default' => in_array($configApiKey, $defaultApiKeys)
        ]
    ]);
    exit;
}
$queue = new RedisQueue($config['redis'] ?? []);

if (!$queue->isConnected()) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => 'Redis not connected: ' . $queue->getLastError()
    ]);
    exit;
}

try {
    switch ($action) {
        case 'status':
            $stats = $queue->getStats();
            $currentJob = $queue->getCurrentProcessingJob();
            $deadLetterJobs = $queue->getDeadLetterJobs(10);
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'queue_stats' => $stats,
                    'current_processing_job' => $currentJob,
                    'dead_letter_queue_count' => count($deadLetterJobs),
                    'dead_letter_preview' => array_slice($deadLetterJobs, 0, 5),
                    'wordpress_webhook_url' => $config['wordpress_webhook_url'] ?? 'NOT SET',
                    'api_key_configured' => !empty($config['api_key']) && $config['api_key'] !== 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY',
                    'allowed_domains' => $config['allowed_download_domains'] ?? []
                ]
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'recover':
            $threshold = isset($_GET['threshold']) ? (int)$_GET['threshold'] : 0;
            $result = $queue->recoverStalledJobs($threshold);
            
            echo json_encode([
                'status' => $result['success'] ? 'success' : 'error',
                'message' => $result['success'] 
                    ? "Recovered {$result['recovered']} stalled jobs" 
                    : ($result['error'] ?? 'Recovery failed'),
                'data' => $result
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'clear-processing':
            $result = $queue->forceRecoverAllProcessingJobs();
            
            echo json_encode([
                'status' => $result['success'] ? 'success' : 'error',
                'message' => $result['success']
                    ? "Moved {$result['recovered']} jobs from processing back to pending"
                    : ($result['error'] ?? 'Clear processing failed'),
                'data' => $result
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'clear-all':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['confirm'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Clear all requires POST method or ?confirm=true parameter (DANGER: deletes all queues)'
                ]);
                break;
            }
            
            $result = $queue->clearAll();
            
            echo json_encode([
                'status' => $result ? 'success' : 'error',
                'message' => $result ? 'All queues cleared' : 'Failed to clear queues'
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'failed-jobs':
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
            $failedJobs = $queue->getRecentFailedJobs($limit);
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'count' => count($failedJobs),
                    'jobs' => $failedJobs
                ]
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'completed-jobs':
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
            $completedJobs = $queue->getRecentCompletedJobs($limit);
            $avgTime = $queue->getAverageProcessingTime();
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'count' => count($completedJobs),
                    'average_processing_time_seconds' => $avgTime,
                    'jobs' => $completedJobs
                ]
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'dead-letter':
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
            $dlqJobs = $queue->getDeadLetterJobs($limit);
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'count' => count($dlqJobs),
                    'jobs' => $dlqJobs
                ]
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'clear-dead-letter':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['confirm'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Clear dead letter requires POST method or ?confirm=true parameter'
                ]);
                break;
            }
            
            $result = $queue->clearDeadLetterQueue();
            
            echo json_encode([
                'status' => $result['success'] ? 'success' : 'error',
                'message' => $result['success']
                    ? "Cleared {$result['cleared']} jobs from dead letter queue"
                    : ($result['error'] ?? 'Clear dead letter failed'),
                'data' => $result
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'test-webhook':
            $webhookUrl = $config['wordpress_webhook_url'] ?? '';
            
            if (empty($webhookUrl)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'WORDPRESS_WEBHOOK_URL not configured',
                    'env_check' => [
                        'WORDPRESS_WEBHOOK_URL' => getenv('WORDPRESS_WEBHOOK_URL') ?: 'not set in env',
                        'config_value' => $webhookUrl ?: 'empty'
                    ]
                ], JSON_PRETTY_PRINT);
                break;
            }
            
            $testPayload = [
                'test' => true,
                'postId' => 0,
                'status' => 'test',
                'message' => 'Webhook connectivity test from VPS API',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($testPayload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . ($config['api_key'] ?? '')
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            echo json_encode([
                'status' => $error ? 'error' : ($httpCode >= 200 && $httpCode < 300 ? 'success' : 'warning'),
                'message' => $error ?: "HTTP {$httpCode}",
                'data' => [
                    'webhook_url' => $webhookUrl,
                    'http_code' => $httpCode,
                    'curl_error' => $error ?: null,
                    'response' => $response ? json_decode($response, true) : null
                ]
            ], JSON_PRETTY_PRINT);
            break;
            
        default:
            echo json_encode([
                'status' => 'error',
                'message' => "Unknown action: {$action}",
                'available_actions' => [
                    'status' => 'Get queue statistics and configuration status',
                    'recover' => 'Recover stalled jobs (use ?threshold=30 for jobs older than 30 min)',
                    'clear-processing' => 'Move all jobs from processing back to pending queue (skips DLQ/completed jobs)',
                    'clear-all' => 'Clear all queues (DANGER - requires POST or ?confirm=true)',
                    'failed-jobs' => 'List recent failed jobs',
                    'completed-jobs' => 'List recent completed jobs with stats',
                    'dead-letter' => 'List jobs in dead letter queue',
                    'clear-dead-letter' => 'Clear all jobs from dead letter queue (requires POST or ?confirm=true)',
                    'test-webhook' => 'Test WordPress webhook connectivity'
                ]
            ], JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
