<?php
/**
 * Log Viewer for Compression API
 * URL: https://v.ogtemplate.com/view-logs.php
 * Shows recent compress.log and redis-queue.log entries
 */

header('Content-Type: application/json');

// Security: Basic authentication (optional - remove in production)
$allowedIPs = ['127.0.0.1', '::1']; // Add your IP here if needed
// Uncomment to restrict access:
// if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
//     http_response_code(403);
//     echo json_encode(['error' => 'Access denied']);
//     exit;
// }

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'logs' => []
];

// Define log files - all logs now consolidated into all.log
$logFiles = [
    'all' => __DIR__ . '/logs/all.log'
];

// Get number of lines to show (default 50, max 500)
$lines = isset($_GET['lines']) ? min((int)$_GET['lines'], 500) : 50;

// Get specific log type
$logType = isset($_GET['type']) ? $_GET['type'] : 'all';

foreach ($logFiles as $key => $logFile) {
    // Skip if specific type requested and this isn't it
    if ($logType !== 'all' && $logType !== $key) {
        continue;
    }
    
    $response['logs'][$key] = [
        'file' => $logFile,
        'exists' => file_exists($logFile),
        'entries' => []
    ];
    
    if (file_exists($logFile)) {
        $response['logs'][$key]['size'] = filesize($logFile);
        $response['logs'][$key]['modified'] = date('Y-m-d H:i:s', filemtime($logFile));
        
        // Read last N lines efficiently
        $file = new SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key() + 1;
        
        $startLine = max(0, $totalLines - $lines);
        
        $file->seek($startLine);
        while (!$file->eof()) {
            $line = trim($file->current());
            if (!empty($line)) {
                $response['logs'][$key]['entries'][] = $line;
            }
            $file->next();
        }
        
        $response['logs'][$key]['total_lines'] = $totalLines;
        $response['logs'][$key]['showing_lines'] = count($response['logs'][$key]['entries']);
        
    } else {
        $response['logs'][$key]['message'] = 'Log file not created yet - no requests logged';
    }
}

// Add statistics from unified log
$response['stats'] = [
    'api_calls' => 0,
    'compress_api_calls' => 0,
    'redis_operations' => 0,
    'worker_operations' => 0,
    'errors' => 0
];

// Count operations from unified all.log
if (!empty($response['logs']['all']['entries'])) {
    $allEntries = $response['logs']['all']['entries'];
    
    $response['stats']['api_calls'] = count(array_filter($allEntries, 
        function($line) { return strpos($line, '[API]') !== false; }
    ));
    
    $response['stats']['compress_api_calls'] = count(array_filter($allEntries, 
        function($line) { return strpos($line, '[COMPRESS]') !== false && strpos($line, 'Request received') !== false; }
    ));
    
    $response['stats']['redis_operations'] = count(array_filter($allEntries, 
        function($line) { return strpos($line, '[REDIS-QUEUE]') !== false; }
    ));
    
    $response['stats']['worker_operations'] = count(array_filter($allEntries, 
        function($line) { return strpos($line, '[WORKER]') !== false; }
    ));
    
    $response['stats']['errors'] = count(array_filter($allEntries, 
        function($line) { return strpos($line, '[ERROR]') !== false || strpos($line, 'failed') !== false; }
    ));
}

echo json_encode($response, JSON_PRETTY_PRINT);
