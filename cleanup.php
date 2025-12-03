<?php
/**
 * Cleanup Script for Old Videos
 * Run this via cron to automatically clean up old files
 * 
 * Example cron: 0 2 * * * php /path/to/cleanup.php
 */

require __DIR__ . '/VideoProcessor.php';

$config = require __DIR__ . '/config.php';
$processor = new VideoProcessor($config);

echo "Starting cleanup process...\n";
$processor->cleanupOldVideos();
echo "Cleanup completed.\n";
