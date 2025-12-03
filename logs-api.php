<?php
/**
 * Complete Logs API
 * Provides complete access to all.log file
 * Supports viewing all logs and downloading the complete file
 * 
 * SECURITY: Requires API key authentication
 */

// Load configuration for API key
$config = require __DIR__ . '/config.php';

// Authenticate request
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';

if (empty($config['api_key']) || $config['api_key'] === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server configuration error: API key not configured']);
    exit;
}

if ($providedKey !== $config['api_key']) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Unauthorized',
        'message' => 'Valid API key required. Provide via X-API-Key header or api_key parameter.'
    ]);
    exit;
}

$logFile = __DIR__ . '/logs/all.log';
$action = $_GET['action'] ?? 'view';

// Handle download request
if ($action === 'download') {
    if (!file_exists($logFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'Log file not found']);
        exit;
    }
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="all-logs-' . date('Y-m-d-H-i-s') . '.log"');
    header('Content-Length: ' . filesize($logFile));
    readfile($logFile);
    exit;
}

// Handle view request
$response = [
    'success' => false,
    'logs' => [],
    'total_lines' => 0,
    'file_size' => 0,
    'last_modified' => null,
    'file_exists' => file_exists($logFile)
];

if (!file_exists($logFile)) {
    $response['message'] = 'Log file not created yet - no logs available';
    echo json_encode($response);
    exit;
}

try {
    $response['file_size'] = filesize($logFile);
    $response['last_modified'] = date('Y-m-d H:i:s', filemtime($logFile));
    
    // Check if requesting all logs
    $viewAll = isset($_GET['all']) && $_GET['all'] === 'true';
    
    if ($viewAll) {
        // Read all lines from the file
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $response['logs'] = $lines;
        $response['total_lines'] = count($lines);
    } else {
        // Default behavior - return last N lines (for backward compatibility)
        $linesToShow = isset($_GET['lines']) ? min((int)$_GET['lines'], 1000) : 100;
        
        $file = new SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key() + 1;
        
        $startLine = max(0, $totalLines - $linesToShow);
        
        $file->seek($startLine);
        $logs = [];
        while (!$file->eof()) {
            $line = trim($file->current());
            if (!empty($line)) {
                $logs[] = $line;
            }
            $file->next();
        }
        
        $response['logs'] = $logs;
        $response['total_lines'] = $totalLines;
        $response['showing_lines'] = count($logs);
    }
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['message'] = 'Error reading log file: ' . $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);
