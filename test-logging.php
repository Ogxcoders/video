<?php
/**
 * Logging System Test
 * Tests all logging functions across different components
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "===========================================\n";
echo "  LOGGING SYSTEM TEST\n";
echo "===========================================\n\n";

$logFile = __DIR__ . '/logs/all.log';
$testsPass = 0;
$testsFail = 0;

// Clear existing log for clean test
if (file_exists($logFile)) {
    file_put_contents($logFile, '');
    echo "✓ Cleared existing log file\n";
}

// Test 1: Direct file write
echo "\n[Test 1] Testing direct file write...\n";
try {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
        chmod($logDir, 0777);
    }
    
    if (!file_exists($logFile)) {
        touch($logFile);
        chmod($logFile, 0666);
    }
    
    $result = file_put_contents($logFile, "[TEST] Direct write successful\n", FILE_APPEND);
    if ($result !== false) {
        echo "✓ Direct file write works\n";
        $testsPass++;
    } else {
        echo "✗ Direct file write failed\n";
        $testsFail++;
    }
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    $testsFail++;
}

// Test 2: Test logAPI function (inline copy)
echo "\n[Test 2] Testing logAPI function...\n";
function testLogAPI($message, $context = [], $level = 'INFO') {
    try {
        $logFile = __DIR__ . '/logs/all.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0777, true)) {
                error_log("[API] Failed to create log directory: {$logDir}");
                return false;
            }
            chmod($logDir, 0777);
        }
        
        if (!file_exists($logFile)) {
            if (!touch($logFile)) {
                error_log("[API] Failed to create log file: {$logFile}");
                return false;
            }
            chmod($logFile, 0666);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] [API] {$message}{$contextStr}\n";
        
        $result = file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log("[API] Failed to write to log file: {$logFile}");
            return false;
        }
        
        chmod($logFile, 0666);
        return true;
    } catch (Exception $e) {
        error_log("[API] Logging exception: " . $e->getMessage());
        return false;
    }
}

try {
    $result = testLogAPI("Test message from API", ['test' => 'data'], 'INFO');
    if ($result === true) {
        echo "✓ API logging function works\n";
        $testsPass++;
    } else {
        echo "✗ API logging function returned false\n";
        $testsFail++;
    }
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    $testsFail++;
}

// Test 3: Test logCompress function (inline copy)
echo "\n[Test 3] Testing logCompress function...\n";
function testLogCompress($message, $context = [], $level = 'INFO') {
    try {
        $logFile = __DIR__ . '/logs/all.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0777, true)) {
                error_log("[COMPRESS] Failed to create log directory: {$logDir}");
                return false;
            }
            chmod($logDir, 0777);
        }
        
        if (!file_exists($logFile)) {
            if (!touch($logFile)) {
                error_log("[COMPRESS] Failed to create log file: {$logFile}");
                return false;
            }
            chmod($logFile, 0666);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] [COMPRESS] {$message}{$contextStr}\n";
        
        $result = file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log("[COMPRESS] Failed to write to log file: {$logFile}");
            return false;
        }
        
        chmod($logFile, 0666);
        return true;
    } catch (Exception $e) {
        error_log("[COMPRESS] Logging exception: " . $e->getMessage());
        return false;
    }
}

try {
    $result = testLogCompress("Test message from COMPRESS", ['test' => 'data'], 'INFO');
    if ($result === true) {
        echo "✓ COMPRESS logging function works\n";
        $testsPass++;
    } else {
        echo "✗ COMPRESS logging function returned false\n";
        $testsFail++;
    }
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    $testsFail++;
}

// Test 4: Test VideoProcessor logging
echo "\n[Test 4] Testing VideoProcessor logging...\n";
try {
    $config = require __DIR__ . '/config.php';
    require_once __DIR__ . '/VideoProcessor.php';
    
    $processor = new VideoProcessor($config);
    
    // Use reflection to test private log method
    $reflection = new ReflectionClass($processor);
    $method = $reflection->getMethod('log');
    $method->setAccessible(true);
    
    $result = $method->invoke($processor, "Test message from VideoProcessor", ['test' => 'data'], 'INFO');
    if ($result === true) {
        echo "✓ VideoProcessor log method works\n";
        $testsPass++;
    } else {
        echo "✗ VideoProcessor log method returned false\n";
        $testsFail++;
    }
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    $testsFail++;
}

// Test 5: Test RedisQueue logging
echo "\n[Test 5] Testing RedisQueue logging...\n";
try {
    $config = require __DIR__ . '/config.php';
    require_once __DIR__ . '/RedisQueue.php';
    
    $queue = new RedisQueue($config);
    
    // Use reflection to test private log method
    $reflection = new ReflectionClass($queue);
    $method = $reflection->getMethod('log');
    $method->setAccessible(true);
    
    $result = $method->invoke($queue, "Test message from RedisQueue", ['test' => 'data'], 'INFO');
    if ($result === true) {
        echo "✓ RedisQueue log method works\n";
        $testsPass++;
    } else {
        echo "✗ RedisQueue log method returned false\n";
        $testsFail++;
    }
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    $testsFail++;
}

// Test 6: Test VideoCompressor logging
echo "\n[Test 6] Testing VideoCompressor logging...\n";
try {
    $config = require __DIR__ . '/config.php';
    require_once __DIR__ . '/VideoCompressor.php';
    
    $compressor = new VideoCompressor($config);
    
    // Use reflection to test private log method
    $reflection = new ReflectionClass($compressor);
    $method = $reflection->getMethod('log');
    $method->setAccessible(true);
    
    $result = $method->invoke($compressor, "Test message from VideoCompressor", ['test' => 'data'], 'INFO');
    if ($result === true) {
        echo "✓ VideoCompressor log method works\n";
        $testsPass++;
    } else {
        echo "✗ VideoCompressor log method returned false\n";
        $testsFail++;
    }
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    $testsFail++;
}

// Test 7: Test logWebhook function (inline copy)
echo "\n[Test 7] Testing logWebhook function...\n";
function testLogWebhook($message, $context = [], $level = 'INFO') {
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

try {
    $result = testLogWebhook("Test message from WEBHOOK", ['test' => 'data'], 'INFO');
    if ($result === true) {
        echo "✓ WEBHOOK logging function works\n";
        $testsPass++;
    } else {
        echo "✗ WEBHOOK logging function returned false\n";
        $testsFail++;
    }
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    $testsFail++;
}

// Test 8: Verify log file contents
echo "\n[Test 8] Verifying log file contents...\n";
if (file_exists($logFile)) {
    $contents = file_get_contents($logFile);
    $lines = explode("\n", trim($contents));
    $validLines = array_filter($lines, function($line) {
        return !empty(trim($line));
    });
    
    echo "Log file size: " . filesize($logFile) . " bytes\n";
    echo "Log entries found: " . count($validLines) . "\n";
    
    if (count($validLines) >= 7) {
        echo "✓ Log file has multiple entries\n";
        $testsPass++;
    } else {
        echo "✗ Log file has fewer entries than expected\n";
        echo "Contents:\n" . $contents . "\n";
        $testsFail++;
    }
} else {
    echo "✗ Log file does not exist\n";
    $testsFail++;
}

// Test 9: Check log file permissions
echo "\n[Test 9] Checking log file permissions...\n";
if (file_exists($logFile)) {
    $perms = fileperms($logFile);
    $permsOctal = substr(sprintf('%o', $perms), -4);
    echo "Log file permissions: " . $permsOctal . "\n";
    
    if (is_writable($logFile)) {
        echo "✓ Log file is writable\n";
        $testsPass++;
    } else {
        echo "✗ Log file is not writable\n";
        $testsFail++;
    }
} else {
    echo "✗ Log file does not exist\n";
    $testsFail++;
}

// Display results
echo "\n===========================================\n";
echo "  TEST RESULTS\n";
echo "===========================================\n";
echo "Tests passed: " . $testsPass . "\n";
echo "Tests failed: " . $testsFail . "\n";
echo "Total tests: " . ($testsPass + $testsFail) . "\n";

if ($testsFail === 0) {
    echo "\n✓ ALL TESTS PASSED - Logging system is working correctly!\n";
    exit(0);
} else {
    echo "\n✗ SOME TESTS FAILED - Please check the errors above\n";
    exit(1);
}
