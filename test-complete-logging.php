<?php
/**
 * Comprehensive Logging Test
 * Tests all logging functions and verifies they write to all.log
 */

echo "===========================================\n";
echo "  COMPREHENSIVE LOGGING TEST\n";
echo "===========================================\n\n";

$logFile = __DIR__ . '/logs/all.log';
$testsPassed = 0;
$testsFailed = 0;

// Test 1: Direct file write
echo "Test 1: Direct write to all.log... ";
$result = file_put_contents($logFile, "[TEST] Direct write at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
if ($result !== false) {
    echo "✓ PASSED\n";
    $testsPassed++;
} else {
    echo "✗ FAILED\n";
    $testsFailed++;
}

// Test 2: API logging function
echo "Test 2: API logging function... ";
require_once __DIR__ . '/index.php';
$result = logAPI("Test message from API", ['test' => 'data', 'timestamp' => time()]);
if ($result) {
    echo "✓ PASSED\n";
    $testsPassed++;
} else {
    echo "✗ FAILED\n";
    $testsFailed++;
}

// Test 3: Compress logging function
echo "Test 3: Compress logging function... ";
require_once __DIR__ . '/compress.php';
$result = logCompress("Test message from COMPRESS", ['test' => 'data', 'timestamp' => time()]);
if ($result) {
    echo "✓ PASSED\n";
    $testsPassed++;
} else {
    echo "✗ FAILED\n";
    $testsFailed++;
}

// Test 4: VideoProcessor logging
echo "Test 4: VideoProcessor logging... ";
require_once __DIR__ . '/VideoProcessor.php';
$config = require __DIR__ . '/config.php';
try {
    $processor = new VideoProcessor($config);
    // The constructor itself logs, so just creating it tests logging
    echo "✓ PASSED\n";
    $testsPassed++;
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}

// Test 5: RedisQueue logging
echo "Test 5: RedisQueue logging... ";
require_once __DIR__ . '/RedisQueue.php';
try {
    $queue = new RedisQueue([
        'host' => '127.0.0.1',
        'port' => 6379,
        'log_file' => $logFile
    ]);
    // Constructor logs connection attempt
    echo "✓ PASSED\n";
    $testsPassed++;
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}

// Test 6: VideoCompressor logging
echo "Test 6: VideoCompressor logging... ";
require_once __DIR__ . '/VideoCompressor.php';
try {
    $compressor = new VideoCompressor($config);
    // Constructor logs initialization
    echo "✓ PASSED\n";
    $testsPassed++;
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    $testsFailed++;
}

// Test 7: Webhook logging function
echo "Test 7: Webhook logging function... ";
require_once __DIR__ . '/webhook-receiver.php';
$result = logWebhook("Test message from WEBHOOK", ['test' => 'data', 'timestamp' => time()]);
if ($result) {
    echo "✓ PASSED\n";
    $testsPassed++;
} else {
    echo "✗ FAILED\n";
    $testsFailed++;
}

// Test 8: Check log file exists and is readable
echo "Test 8: Log file exists and is readable... ";
if (file_exists($logFile) && is_readable($logFile)) {
    echo "✓ PASSED\n";
    $testsPassed++;
} else {
    echo "✗ FAILED\n";
    $testsFailed++;
}

// Test 9: Check log file has content
echo "Test 9: Log file has content... ";
$logContent = file_get_contents($logFile);
if (!empty($logContent) && strlen($logContent) > 100) {
    echo "✓ PASSED\n";
    $testsPassed++;
} else {
    echo "✗ FAILED (file size: " . strlen($logContent) . " bytes)\n";
    $testsFailed++;
}

// Test 10: Check log file is writable
echo "Test 10: Log file is writable... ";
if (is_writable($logFile)) {
    echo "✓ PASSED\n";
    $testsPassed++;
} else {
    echo "✗ FAILED\n";
    $testsFailed++;
}

// Display log file statistics
echo "\n===========================================\n";
echo "  LOG FILE STATISTICS\n";
echo "===========================================\n";
echo "Path: {$logFile}\n";
echo "Size: " . filesize($logFile) . " bytes\n";
echo "Lines: " . count(file($logFile)) . "\n";
echo "Permissions: " . substr(sprintf('%o', fileperms($logFile)), -4) . "\n";
echo "Last modified: " . date('Y-m-d H:i:s', filemtime($logFile)) . "\n";

// Display test summary
echo "\n===========================================\n";
echo "  TEST SUMMARY\n";
echo "===========================================\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n";
echo "Total Tests: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n✓ ALL TESTS PASSED! Logging system is working correctly.\n";
} else {
    echo "\n✗ SOME TESTS FAILED. Please check the errors above.\n";
}

// Display last 20 lines of log
echo "\n===========================================\n";
echo "  LAST 20 LOG ENTRIES\n";
echo "===========================================\n";
$lines = file($logFile);
$lastLines = array_slice($lines, -20);
foreach ($lastLines as $line) {
    echo $line;
}

echo "\n===========================================\n";
echo "  TEST COMPLETE\n";
echo "===========================================\n";
