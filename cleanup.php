#!/usr/bin/env php
<?php
/**
 * Comprehensive Cleanup Script
 * Handles maintenance tasks:
 * 1. Old video files cleanup (90+ days)
 * 2. Temporary file cleanup (failed downloads)
 * 3. Empty directory removal
 * 4. Log file rotation
 * 
 * Usage: php cleanup.php [options]
 * Options:
 *   --dry-run       : Show what would be deleted without deleting
 *   --max-age=N     : Set max age in days (default: 90)
 *   --verbose       : Show detailed output
 * 
 * Example cron: 0 2 * * * php /var/www/html/cleanup.php >> /var/www/html/logs/cleanup.log 2>&1
 */

// Parse command line arguments
$options = getopt('', ['dry-run', 'max-age:', 'verbose']);
$dryRun = isset($options['dry-run']);
$maxAgeDays = isset($options['max-age']) ? intval($options['max-age']) : 90;
$verbose = isset($options['verbose']);

$config = require __DIR__ . '/config.php';

// Statistics
$stats = [
    'old_directories_removed' => 0,
    'temp_files_removed' => 0,
    'empty_directories_removed' => 0,
    'log_files_rotated' => 0,
    'space_freed_mb' => 0
];

echo "\n";
echo "==========================================\n";
echo "VIDEO PROCESSOR CLEANUP SCRIPT\n";
echo "==========================================\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE') . "\n";
echo "Max Age: {$maxAgeDays} days\n";
echo "==========================================\n\n";

// ========================================
// 1. CLEANUP OLD VIDEO DIRECTORIES
// ========================================
echo "Step 1: Cleaning up old video directories...\n";

$mediaBasePath = $config['media_base_path'];
$cutoffTime = time() - ($maxAgeDays * 24 * 60 * 60);

if (is_dir($mediaBasePath)) {
    // Find all year/month directories
    $yearDirs = glob($mediaBasePath . '/*', GLOB_ONLYDIR);
    
    foreach ($yearDirs as $yearDir) {
        if (!preg_match('/\/\d{4}$/', $yearDir)) {
            continue; // Skip non-year directories
        }
        
        $monthDirs = glob($yearDir . '/*', GLOB_ONLYDIR);
        
        foreach ($monthDirs as $monthDir) {
            if (!preg_match('/\/\d{2}$/', $monthDir)) {
                continue; // Skip non-month directories
            }
            
            $postDirs = glob($monthDir . '/*', GLOB_ONLYDIR);
            
            foreach ($postDirs as $postDir) {
                // Check directory age
                $dirAge = filemtime($postDir);
                
                if ($dirAge < $cutoffTime) {
                    // Calculate directory size
                    $dirSize = getDirSize($postDir);
                    $sizeMB = round($dirSize / (1024 * 1024), 2);
                    
                    if ($verbose) {
                        echo "  Found old directory: {$postDir} (Age: " . round((time() - $dirAge) / 86400) . " days, Size: {$sizeMB}MB)\n";
                    }
                    
                    if (!$dryRun) {
                        if (deleteDirectory($postDir)) {
                            $stats['old_directories_removed']++;
                            $stats['space_freed_mb'] += $sizeMB;
                            if ($verbose) {
                                echo "  ✓ Deleted: {$postDir}\n";
                            }
                        } else {
                            echo "  ✗ Failed to delete: {$postDir}\n";
                        }
                    } else {
                        $stats['old_directories_removed']++;
                        $stats['space_freed_mb'] += $sizeMB;
                    }
                }
            }
            
            // Remove empty month directories
            if (isDirEmpty($monthDir) && !$dryRun) {
                rmdir($monthDir);
                $stats['empty_directories_removed']++;
                if ($verbose) {
                    echo "  ✓ Removed empty directory: {$monthDir}\n";
                }
            }
        }
        
        // Remove empty year directories
        if (isDirEmpty($yearDir) && !$dryRun) {
            rmdir($yearDir);
            $stats['empty_directories_removed']++;
            if ($verbose) {
                echo "  ✓ Removed empty directory: {$yearDir}\n";
            }
        }
    }
}

echo "  Removed {$stats['old_directories_removed']} old directories\n";
echo "  Removed {$stats['empty_directories_removed']} empty directories\n";

// ========================================
// 2. CLEANUP TEMPORARY FILES
// ========================================
echo "\nStep 2: Cleaning up temporary files...\n";

// Clean temp files older than 1 hour
$tempLocations = [
    sys_get_temp_dir(),
    $mediaBasePath
];

foreach ($tempLocations as $location) {
    if (!is_dir($location)) {
        continue;
    }
    
    // Find temp files (temp_*, thumb_*, *.tmp)
    $tempPatterns = [
        'temp_*',
        'thumb_*',
        '*.tmp',
        '*.part'
    ];
    
    foreach ($tempPatterns as $pattern) {
        $tempFiles = glob($location . '/' . $pattern);
        
        foreach ($tempFiles as $tempFile) {
            if (!is_file($tempFile)) {
                continue;
            }
            
            $fileAge = time() - filemtime($tempFile);
            
            // Delete files older than 1 hour
            if ($fileAge > 3600) {
                $fileSize = filesize($tempFile);
                $sizeMB = round($fileSize / (1024 * 1024), 2);
                
                if ($verbose) {
                    echo "  Found temp file: " . basename($tempFile) . " (Age: " . round($fileAge / 60) . " minutes, Size: {$sizeMB}MB)\n";
                }
                
                if (!$dryRun) {
                    if (unlink($tempFile)) {
                        $stats['temp_files_removed']++;
                        $stats['space_freed_mb'] += $sizeMB;
                        if ($verbose) {
                            echo "  ✓ Deleted: " . basename($tempFile) . "\n";
                        }
                    }
                } else {
                    $stats['temp_files_removed']++;
                    $stats['space_freed_mb'] += $sizeMB;
                }
            }
        }
    }
}

echo "  Removed {$stats['temp_files_removed']} temporary files\n";

// ========================================
// 3. ROTATE LOG FILES
// ========================================
echo "\nStep 3: Rotating log files...\n";

$logDir = dirname($config['log_file']);
$maxLogSizeMB = 100; // Rotate logs larger than 100MB

if (is_dir($logDir)) {
    $logFiles = glob($logDir . '/*.log');
    
    foreach ($logFiles as $logFile) {
        if (!is_file($logFile)) {
            continue;
        }
        
        $logSize = filesize($logFile);
        $logSizeMB = round($logSize / (1024 * 1024), 2);
        
        if ($logSizeMB > $maxLogSizeMB) {
            if ($verbose) {
                echo "  Found large log: " . basename($logFile) . " ({$logSizeMB}MB)\n";
            }
            
            if (!$dryRun) {
                // Rotate: api.log -> api.log.1, api.log.1 -> api.log.2, etc.
                $baseName = $logFile;
                
                // Delete .log.5 if exists
                if (file_exists($baseName . '.5')) {
                    unlink($baseName . '.5');
                }
                
                // Rotate existing backups
                for ($i = 4; $i >= 1; $i--) {
                    if (file_exists($baseName . '.' . $i)) {
                        rename($baseName . '.' . $i, $baseName . '.' . ($i + 1));
                    }
                }
                
                // Rotate current log
                rename($logFile, $logFile . '.1');
                touch($logFile); // Create new empty log
                chmod($logFile, 0644);
                
                $stats['log_files_rotated']++;
                
                if ($verbose) {
                    echo "  ✓ Rotated: " . basename($logFile) . "\n";
                }
            } else {
                $stats['log_files_rotated']++;
            }
        }
    }
}

echo "  Rotated {$stats['log_files_rotated']} log files\n";

// ========================================
// 4. REDIS CLEANUP (OPTIONAL)
// ========================================
echo "\nStep 4: Cleaning up Redis data...\n";

try {
    $redis = new Redis();
    $redis->connect(
        $config['redis_host'],
        $config['redis_port']
    );
    
    // Remove old completed jobs (older than 7 days)
    $allKeys = $redis->keys('job:*');
    $removedJobs = 0;
    $cutoffTimestamp = time() - (7 * 24 * 60 * 60);
    
    foreach ($allKeys as $key) {
        $jobData = $redis->hGet($key, 'data');
        
        if ($jobData) {
            $job = json_decode($jobData, true);
            
            if (isset($job['status']) && $job['status'] === 'completed') {
                if (isset($job['updated_at']) && $job['updated_at'] < $cutoffTimestamp) {
                    if ($verbose) {
                        echo "  Found old completed job: {$key}\n";
                    }
                    
                    if (!$dryRun) {
                        $redis->del($key);
                        $removedJobs++;
                    } else {
                        $removedJobs++;
                    }
                }
            }
        }
    }
    
    echo "  Removed {$removedJobs} old completed jobs from Redis\n";
    
} catch (Exception $e) {
    echo "  ✗ Redis cleanup failed: " . $e->getMessage() . "\n";
}

// ========================================
// FINAL REPORT
// ========================================
echo "\n==========================================\n";
echo "CLEANUP SUMMARY\n";
echo "==========================================\n";
echo "Old directories removed: {$stats['old_directories_removed']}\n";
echo "Temporary files removed: {$stats['temp_files_removed']}\n";
echo "Empty directories removed: {$stats['empty_directories_removed']}\n";
echo "Log files rotated: {$stats['log_files_rotated']}\n";
echo "Space freed: " . round($stats['space_freed_mb'], 2) . " MB\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n\n";

if ($dryRun) {
    echo "NOTE: This was a DRY RUN. No files were actually deleted.\n";
    echo "Run without --dry-run to perform actual cleanup.\n\n";
}

exit(0);

// ========================================
// HELPER FUNCTIONS
// ========================================

/**
 * Calculate directory size recursively
 */
function getDirSize($dir) {
    $size = 0;
    
    if (!is_dir($dir)) {
        return 0;
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    
    return $size;
}

/**
 * Delete directory recursively
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        return rmdir($dir);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if directory is empty
 */
function isDirEmpty($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $handle = opendir($dir);
    while (false !== ($entry = readdir($handle))) {
        if ($entry != '.' && $entry != '..') {
            closedir($handle);
            return false;
        }
    }
    closedir($handle);
    return true;
}
