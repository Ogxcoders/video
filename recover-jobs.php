<?php
/**
 * Job Recovery Script
 * Recovers stalled jobs from the processing queue back to pending queue
 * Run this after deployment or when jobs are stuck
 * 
 * Usage: php recover-jobs.php [--force]
 *   --force : Recover ALL processing jobs regardless of age
 *   (default: only recover jobs older than 30 minutes)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Job Recovery Script ===\n\n";

$autoloaderPaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];

$autoloaderLoaded = false;
foreach ($autoloaderPaths as $autoloaderPath) {
    if (file_exists($autoloaderPath)) {
        require_once $autoloaderPath;
        $autoloaderLoaded = true;
        echo "Autoloader loaded from: {$autoloaderPath}\n";
        break;
    }
}

if (!$autoloaderLoaded) {
    echo "WARNING: Composer autoloader not found. Trying direct Redis connection...\n";
}

require_once __DIR__ . '/RedisQueue.php';

$forceRecover = in_array('--force', $argv ?? []);

echo "Recovery mode: " . ($forceRecover ? "FORCE (all jobs)" : "Normal (30 min threshold)") . "\n\n";

try {
    $redisQueue = new RedisQueue([
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => getenv('REDIS_PORT') ?: 6379,
        'queue_name' => 'compression_queue',
        'log_file' => __DIR__ . '/logs/recovery.log'
    ]);
    
    if (!$redisQueue->isConnected()) {
        echo "ERROR: Cannot connect to Redis: " . $redisQueue->getLastError() . "\n";
        exit(1);
    }
    
    echo "Connected to Redis successfully.\n\n";
    
    $stats = $redisQueue->getStats();
    echo "Current Queue Status:\n";
    echo "  - Pending: " . ($stats['pending'] ?? 0) . "\n";
    echo "  - Processing: " . ($stats['processing'] ?? 0) . "\n";
    echo "  - Completed: " . ($stats['completed'] ?? 0) . "\n";
    echo "  - Failed: " . ($stats['failed'] ?? 0) . "\n\n";
    
    if (($stats['processing'] ?? 0) == 0) {
        echo "No jobs in processing queue. Nothing to recover.\n";
        exit(0);
    }
    
    echo "Recovering stalled jobs...\n\n";
    
    if ($forceRecover) {
        $result = $redisQueue->forceRecoverAllProcessingJobs();
    } else {
        $result = $redisQueue->recoverStalledJobs(30);
    }
    
    if ($result['success']) {
        echo "Recovery Complete!\n";
        echo "  - Jobs recovered: {$result['recovered']}\n";
        
        if (!empty($result['job_ids'])) {
            echo "  - Job IDs: " . implode(', ', $result['job_ids']) . "\n";
        }
        
        $newStats = $redisQueue->getStats();
        echo "\nUpdated Queue Status:\n";
        echo "  - Pending: " . ($newStats['pending'] ?? 0) . "\n";
        echo "  - Processing: " . ($newStats['processing'] ?? 0) . "\n";
        echo "  - Completed: " . ($newStats['completed'] ?? 0) . "\n";
        echo "  - Failed: " . ($newStats['failed'] ?? 0) . "\n";
    } else {
        echo "Recovery failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Recovery Complete ===\n";
