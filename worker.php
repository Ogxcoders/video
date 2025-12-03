#!/usr/bin/env php
<?php
/**
 * Background Worker Service
 * Monitors Redis queue and processes video compression jobs
 * 
 * Usage: php worker.php
 * Daemon: nohup php worker.php > /dev/null 2>&1 &
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Prevent running from web server
if (php_sapi_name() !== 'cli') {
    die("This script must be run from command line\n");
}

require_once __DIR__ . '/RedisQueue.php';
require_once __DIR__ . '/VideoCompressor.php';
require_once __DIR__ . '/ImageCompressor.php';
require_once __DIR__ . '/HLSConverter.php';

class BackgroundWorker {
    private $queue;
    private $compressor;
    private $imageCompressor;
    private $hlsConverter;
    private $config;
    private $running = true;
    private $logFile;
    private $processedCount = 0;
    private $failedCount = 0;
    private $startTime;
    private $lastQueueCheck = 0;
    private $iterationCount = 0;
    private $jobStartTimes = []; // Track start times per job for time estimation
    
    const MAX_RETRY_ATTEMPTS = 3; // Advanced: 3 retries with exponential backoff
    const RETRY_DELAY = 5; // base delay in seconds for exponential backoff
    const QUEUE_CHECK_INTERVAL = 30; // Log queue stats every 30 seconds
    const HEARTBEAT_INTERVAL = 300; // Log heartbeat every 5 minutes
    
    const ERROR_DURATION_TOO_LONG = 'DURATION_TOO_LONG';
    const ERROR_FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    const ERROR_INVALID_CODEC = 'INVALID_CODEC';
    const ERROR_VIDEO_CORRUPTED = 'VIDEO_CORRUPTED';
    const ERROR_FILE_NOT_FOUND = 'FILE_NOT_FOUND';
    const ERROR_INVALID_CONTAINER = 'INVALID_CONTAINER';
    
    private static $VALIDATION_ERROR_CODES = [
        'DURATION_TOO_LONG',
        'FILE_TOO_LARGE',
        'INVALID_CODEC',
        'VIDEO_CORRUPTED',
        'FILE_NOT_FOUND',
        'INVALID_CONTAINER'
    ];
    
    public function __construct() {
        // Initialize log file path and start time BEFORE any logging
        $this->logFile = __DIR__ . '/logs/all.log';
        $this->startTime = time();
        
        $this->log("===========================================");
        $this->log("  INITIALIZING BACKGROUND WORKER");
        $this->log("===========================================");
        
        $this->config = require __DIR__ . '/config.php';
        
        $this->log("DEBUG: Loading configuration", ['config_file' => __DIR__ . '/config.php']);
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
            chmod($logDir, 0777);
            $this->log("DEBUG: Created log directory", ['path' => $logDir]);
        } else {
            $this->log("DEBUG: Log directory exists", ['path' => $logDir]);
        }
        
        // Log system information
        $this->logSystemInfo();
        
        // Initialize Redis Queue using config values
        $redisHost = $this->config['redis']['host'] ?? getenv('REDIS_HOST') ?: '127.0.0.1';
        $redisPort = $this->config['redis']['port'] ?? (int)(getenv('REDIS_PORT') ?: 6379);
        $redisPassword = $this->config['redis']['password'] ?? getenv('REDIS_PASSWORD') ?: null;
        
        $this->log("DEBUG: Initializing Redis Queue connection", [
            'host' => $redisHost,
            'port' => $redisPort,
            'queue_name' => 'compression_queue'
        ]);
        
        $this->queue = new RedisQueue([
            'host' => $redisHost,
            'port' => $redisPort,
            'password' => $redisPassword,
            'queue_name' => 'compression_queue',
            'processing_queue' => 'compression_processing',
            'log_file' => $this->logFile
        ]);
        
        if ($this->queue->isConnected()) {
            $this->log("DEBUG: Redis Queue connected successfully");
            $this->logRedisInfo();
        } else {
            $this->log("ERROR: Redis Queue connection failed", ['error' => $this->queue->getLastError()], 'ERROR');
        }
        
        // Initialize Video Compressor
        $this->log("DEBUG: Initializing Video Compressor");
        $this->compressor = new VideoCompressor($this->config);
        $this->log("DEBUG: Video Compressor initialized");
        
        // Initialize Image Compressor
        $this->log("DEBUG: Initializing Image Compressor");
        $this->imageCompressor = new ImageCompressor($this->config);
        $this->log("DEBUG: Image Compressor initialized");
        
        // Initialize HLS Converter
        $this->log("DEBUG: Initializing HLS Converter");
        $this->hlsConverter = new HLSConverter($this->config);
        $this->log("DEBUG: HLS Converter initialized");
        
        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            $this->log("DEBUG: Signal handlers registered (SIGTERM, SIGINT)");
        } else {
            $this->log("WARNING: pcntl_signal not available - graceful shutdown limited", [], 'WARNING');
        }
        
        $this->log("DEBUG: Worker initialization complete");
    }
    
    /**
     * Start the worker daemon
     */
    public function start() {
        $this->log("===========================================");
        $this->log("  Background Worker Service Starting");
        $this->log("===========================================");
        
        // Check Redis connection
        if (!$this->queue->isConnected()) {
            $this->log("FATAL: Cannot connect to Redis - " . $this->queue->getLastError(), [], 'FATAL');
            $this->log("Worker cannot start without Redis connection", [], 'FATAL');
            $this->log("FATAL: Exiting with code 1", [], 'FATAL');
            exit(1);
        }
        
        $this->log("DEBUG: Redis connection verified");
        
        // Auto-recover stalled jobs on startup (jobs stuck in processing queue)
        $this->recoverStalledJobsOnStartup();
        
        // Get initial queue statistics
        $this->logQueueStats();
        
        $this->log("Worker ready to process jobs");
        $this->log("Press Ctrl+C to stop gracefully");
        $this->log("===========================================");
        $this->log("");
        
        $lastHeartbeat = time();
        
        // Main worker loop
        while ($this->running) {
            try {
                $this->iterationCount++;
                
                // Allow signal handling
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                // Check if we should stop
                if (!$this->running) {
                    $this->log("DEBUG: Shutdown signal received, stopping gracefully...");
                    break;
                }
                
                // Periodic queue monitoring
                $now = time();
                if ($now - $this->lastQueueCheck >= self::QUEUE_CHECK_INTERVAL) {
                    $this->logQueueStats();
                    $this->lastQueueCheck = $now;
                }
                
                // Periodic heartbeat
                if ($now - $lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
                    $this->logHeartbeat();
                    $lastHeartbeat = $now;
                }
                
                // Get next job from queue (10 second timeout)
                $this->log("DEBUG: Waiting for next job (timeout: 10s)...", ['iteration' => $this->iterationCount]);
                $job = $this->queue->dequeue(10);
                
                if ($job === null) {
                    // No job in Redis - check file queue fallback
                    $job = $this->getJobFromFileQueue();
                    
                    if ($job === null) {
                        // No job available in either queue, continue waiting
                        $this->log("DEBUG: No job available, continuing...", ['iteration' => $this->iterationCount]);
                        continue;
                    } else {
                        $this->log("DEBUG: Job picked from file queue fallback", [
                            'jobId' => $job['jobId'] ?? 'unknown',
                            'postId' => $job['postId'] ?? 'unknown'
                        ]);
                    }
                }
                
                $this->log("DEBUG: Job picked from queue", [
                    'jobId' => $job['jobId'] ?? 'unknown',
                    'postId' => $job['postId'] ?? 'unknown',
                    'iteration' => $this->iterationCount
                ]);
                
                // Process the job
                $this->processJob($job);
                
            } catch (Exception $e) {
                $this->log("ERROR: Worker loop exception: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'iteration' => $this->iterationCount
                ], 'ERROR');
                
                // Sleep briefly before retrying to prevent rapid error loops
                $this->log("DEBUG: Sleeping 5 seconds before retry due to error...");
                sleep(5);
            }
        }
        
        $this->shutdown();
    }
    
    /**
     * Auto-recover stalled jobs on worker startup
     * Jobs that were left in "processing" state due to worker crash/restart
     */
    private function recoverStalledJobsOnStartup() {
        $this->log("===========================================");
        $this->log("  Checking for stalled jobs to recover...");
        $this->log("===========================================");
        
        try {
            $stats = $this->queue->getStats();
            $processingCount = $stats['processing'] ?? 0;
            $pendingCount = $stats['pending'] ?? 0;
            
            $this->log("DEBUG: Queue state before recovery", [
                'pending' => $pendingCount,
                'processing' => $processingCount
            ]);
            
            if ($processingCount > 0) {
                $this->log("INFO: Found {$processingCount} jobs stuck in processing queue");
                
                // Recover all stalled jobs (move from processing back to pending)
                $result = $this->queue->forceRecoverAllProcessingJobs();
                
                if ($result && isset($result['recovered'])) {
                    $recoveredCount = $result['recovered'];
                    $this->log("SUCCESS: Recovered {$recoveredCount} stalled jobs", [
                        'jobs_recovered' => $recoveredCount,
                        'failed' => $result['failed'] ?? 0
                    ]);
                } else {
                    $this->log("INFO: No jobs needed recovery or recovery not available");
                }
                
                // Log updated queue state
                $newStats = $this->queue->getStats();
                $this->log("DEBUG: Queue state after recovery", [
                    'pending' => $newStats['pending'] ?? 0,
                    'processing' => $newStats['processing'] ?? 0
                ]);
            } else {
                $this->log("INFO: No stalled jobs found in processing queue");
            }
        } catch (Exception $e) {
            $this->log("WARNING: Failed to recover stalled jobs: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ], 'WARNING');
        }
        
        $this->log("");
    }
    
    /**
     * Get job from file-based queue fallback
     * Checks /queue/*.json files for jobs when Redis is unavailable
     * 
     * @return array|null Job data or null if no jobs available
     */
    private function getJobFromFileQueue() {
        $queueDir = __DIR__ . '/queue';
        
        if (!is_dir($queueDir)) {
            return null;
        }
        
        $queueFiles = glob($queueDir . '/*.json');
        
        if (empty($queueFiles)) {
            return null;
        }
        
        // Sort by modification time (oldest first - FIFO)
        usort($queueFiles, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Try to get the first available job
        foreach ($queueFiles as $queueFile) {
            $jobData = @json_decode(file_get_contents($queueFile), true);
            
            if ($jobData === null) {
                $this->log("WARNING: Failed to parse job file", ['file' => basename($queueFile)], 'WARNING');
                // Delete corrupted file
                @unlink($queueFile);
                continue;
            }
            
            // Check if job is already being processed (check for .processing marker)
            $processingMarker = $queueFile . '.processing';
            if (file_exists($processingMarker)) {
                // Check if marker is stale (older than 10 minutes)
                if (time() - filemtime($processingMarker) < 600) {
                    continue; // Skip this job, it's being processed
                }
                // Stale marker, remove it
                @unlink($processingMarker);
            }
            
            // Create processing marker
            touch($processingMarker);
            
            // Delete the queue file (we're now processing it)
            @unlink($queueFile);
            
            $this->log("INFO: Retrieved job from file queue", [
                'jobId' => $jobData['jobId'] ?? 'unknown',
                'postId' => $jobData['postId'] ?? 'unknown',
                'file' => basename($queueFile)
            ]);
            
            // Remove marker after getting job
            @unlink($processingMarker);
            
            return $jobData;
        }
        
        return null;
    }
    
    /**
     * Process a single job
     */
    private function processJob($job) {
        $jobId = $job['jobId'] ?? 'unknown';
        $postId = $job['postId'] ?? 'unknown';
        
        $this->log("");
        $this->log("================================================");
        $this->log("  PROCESSING JOB: {$jobId}");
        $this->log("================================================");
        $this->log("DEBUG: Job status changed to 'processing'", [
            'jobId' => $jobId,
            'postId' => $postId
        ]);
        
        $startTime = microtime(true);
        
        // Task 13: Track job start time for time estimation
        $this->jobStartTimes[$jobId] = $startTime;
        
        // Task 13: Update processing started timestamp in Redis
        $this->queue->updateJobData($jobId, [
            '_processing_started_at' => date('Y-m-d H:i:s')
        ]);
        
        // Task 13: Send 0% progress - Job started
        $this->updateJobProgress($jobId, 0, 'started');
        $this->sendProgressWebhook($job, 0, 'started');
        
        try {
            // ALWAYS use VideoCompressor path - extract missing fields from videoUrl if needed
            // This ensures consistent output paths (/media/content/YYYY/MM/POST_ID/) and thumbnail processing
            
            $videoUrl = $job['wpVideoUrl'] ?? $job['videoUrl'] ?? '';
            $wpMediaPath = $job['wpMediaPath'] ?? '';
            $year = !empty($job['year']) ? (int)$job['year'] : 0;
            $month = !empty($job['month']) ? (int)$job['month'] : 0;
            $fieldsExtracted = false;
            
            // If wpMediaPath is missing but we have a video URL, extract it
            if (empty($wpMediaPath) && !empty($videoUrl)) {
                $parsedUrl = parse_url($videoUrl);
                if (isset($parsedUrl['path'])) {
                    $wpMediaPath = $parsedUrl['path'];
                    $job['wpMediaPath'] = $wpMediaPath;
                    $fieldsExtracted = true;
                    $this->log("WARNING: Extracted wpMediaPath from videoUrl", [
                        'jobId' => $jobId,
                        'wpMediaPath' => $wpMediaPath,
                        'videoUrl' => $videoUrl
                    ], 'WARNING');
                }
            }
            
            // If year/month are missing, try to extract from URL or path
            if ($year <= 0 || $month <= 0) {
                $pathToCheck = !empty($wpMediaPath) ? $wpMediaPath : $videoUrl;
                
                // Match patterns like /2024/11/ or /2024/12/ in the path or URL
                if (preg_match('#/(\d{4})/(\d{1,2})/#', $pathToCheck, $matches)) {
                    $extractedYear = (int)$matches[1];
                    $extractedMonth = (int)$matches[2];
                    
                    if ($extractedYear >= 2000 && $extractedYear <= 2100 && $extractedMonth >= 1 && $extractedMonth <= 12) {
                        if ($year <= 0) {
                            $year = $extractedYear;
                            $job['year'] = $year;
                            $fieldsExtracted = true;
                        }
                        if ($month <= 0) {
                            $month = $extractedMonth;
                            $job['month'] = $month;
                            $fieldsExtracted = true;
                        }
                        $this->log("WARNING: Extracted year/month from path", [
                            'jobId' => $jobId,
                            'year' => $year,
                            'month' => $month,
                            'source' => $pathToCheck
                        ], 'WARNING');
                    }
                }
                
                // If still not found, use current date as fallback
                if ($year <= 0) {
                    $year = (int)date('Y');
                    $job['year'] = $year;
                    $fieldsExtracted = true;
                }
                if ($month <= 0) {
                    $month = (int)date('m');
                    $job['month'] = $month;
                    $fieldsExtracted = true;
                }
                
                if ($fieldsExtracted) {
                    $this->log("WARNING: Using fallback/extracted year/month for job", [
                        'jobId' => $jobId,
                        'year' => $year,
                        'month' => $month
                    ], 'WARNING');
                }
            }
            
            // Ensure wpVideoUrl is set for VideoCompressor
            if (empty($job['wpVideoUrl']) && !empty($videoUrl)) {
                $job['wpVideoUrl'] = $videoUrl;
            }
            
            // Check if we now have all required fields for VideoCompressor
            $hasFullFormat = !empty($job['wpMediaPath']) && !empty($job['year']) && !empty($job['month']);
            
            if (!$hasFullFormat) {
                // Still missing required data - this shouldn't happen after extraction
                throw new Exception("Invalid job format: could not extract required fields (wpMediaPath, year, month) from job data or videoUrl");
            }
            
            // Log if we had to extract fields (useful for debugging)
            if ($fieldsExtracted) {
                $this->log("INFO: Job processed with extracted fields - using VideoCompressor path", [
                    'jobId' => $jobId,
                    'wpMediaPath' => $job['wpMediaPath'],
                    'year' => $job['year'],
                    'month' => $job['month'],
                    'videoUrl' => $videoUrl
                ]);
            }
            
            // ALWAYS use VideoCompressor (preferred path with correct URL structure)
            $this->log("DEBUG: Processing job with VideoCompressor (full format)", [
                'jobId' => $jobId,
                'wpMediaPath' => $job['wpMediaPath'],
                'year' => $job['year'],
                'month' => $job['month'],
                'fieldsExtracted' => $fieldsExtracted
            ]);
            
            // Validate job data
            $this->log("DEBUG: Validating job data...", ['jobId' => $jobId]);
            $this->validateJobData($job);
            $this->log("DEBUG: Job data validation passed", ['jobId' => $jobId]);
            
            // Task 13: Send 25% progress - Video validation complete
            $this->updateJobProgress($jobId, 25, 'validating');
            $this->sendProgressWebhook($job, 25, 'validating');
            
            // Log comprehensive job details
            $this->log("DEBUG: Job details", [
                'jobId' => $jobId,
                'postId' => $postId,
                'wpMediaPath' => $job['wpMediaPath'] ?? 'unknown',
                'wpThumbnailPath' => $job['wpThumbnailPath'] ?? 'not provided',
                'wpThumbnailUrl' => $job['wpThumbnailUrl'] ?? 'not provided',
                'wpVideoUrl' => $job['wpVideoUrl'] ?? 'not provided',
                'wpPostUrl' => $job['wpPostUrl'] ?? 'not provided',
                'year' => $job['year'] ?? 'unknown',
                'month' => $job['month'] ?? 'unknown'
            ]);
            
            // Task 13: Send 50% progress - Compression starting
            $this->updateJobProgress($jobId, 50, 'compressing');
            $this->sendProgressWebhook($job, 50, 'compressing');
            
            // Compress the video
            $this->log("DEBUG: Starting video compression...", ['jobId' => $jobId]);
            $result = $this->compressor->compressVideo($job);
            
            // Compress thumbnail if video compression succeeded and thumbnail is provided (path OR url)
            $hasThumbnailData = !empty($job['wpThumbnailPath']) || !empty($job['wpThumbnailUrl']);
            $this->log("DEBUG: Thumbnail check", [
                'jobId' => $jobId,
                'hasThumbnailData' => $hasThumbnailData,
                'wpThumbnailPath' => $job['wpThumbnailPath'] ?? '(empty)',
                'wpThumbnailUrl' => $job['wpThumbnailUrl'] ?? '(empty)',
                'videoSuccess' => $result['success']
            ]);
            
            if ($result['success'] && $hasThumbnailData) {
                $this->log("DEBUG: Starting thumbnail compression...", [
                    'jobId' => $jobId,
                    'wpThumbnailPath' => $job['wpThumbnailPath'] ?? '(empty)',
                    'wpThumbnailUrl' => $job['wpThumbnailUrl'] ?? '(empty)'
                ]);
                $imageResult = $this->imageCompressor->compressImage($job);
                
                if ($imageResult['success']) {
                    if (isset($imageResult['skipped'])) {
                        $this->log("DEBUG: Thumbnail compression skipped (existing file)", [
                            'jobId' => $jobId,
                            'reason' => $imageResult['message'] ?? 'unknown',
                            'webp_url' => $imageResult['urls']['thumbnail_webp'] ?? 'not available'
                        ]);
                    } else {
                        $this->log("DEBUG: Thumbnail compression completed", [
                            'jobId' => $jobId,
                            'webp_url' => $imageResult['urls']['thumbnail_webp'] ?? 'not available'
                        ]);
                    }
                    
                    // Add thumbnail data to result (for both new and skipped)
                    if (!empty($imageResult['urls']['thumbnail_webp'])) {
                        $result['thumbnail'] = [
                            'success' => true,
                            'skipped' => isset($imageResult['skipped']),
                            'webp_url' => $imageResult['urls']['thumbnail_webp'] ?? null,
                            'webp_path' => $imageResult['paths']['thumbnail_webp'] ?? null,
                            'original_size' => $imageResult['stats']['original_size'] ?? 0,
                            'webp_size' => $imageResult['stats']['webp_size'] ?? 0,
                            'compression_ratio' => $imageResult['stats']['compression_ratio'] ?? 0,
                            'dimensions' => $imageResult['dimensions'] ?? []
                        ];
                    }
                } else {
                    $this->log("WARNING: Thumbnail compression failed", [
                        'jobId' => $jobId,
                        'error' => $imageResult['error'] ?? 'unknown',
                        'imageResult' => json_encode($imageResult)
                    ], 'WARNING');
                }
            } else if (!$hasThumbnailData) {
                $this->log("DEBUG: No thumbnail data in job - skipping thumbnail compression", [
                    'jobId' => $jobId,
                    'job_keys' => array_keys($job)
                ]);
            }
            
            // Convert to HLS if video compression succeeded (Task 11)
            if ($result['success'] && !empty($result['paths'])) {
                // Task 13: Send 75% progress - Compression complete, starting HLS
                $this->updateJobProgress($jobId, 75, 'converting_hls');
                $this->sendProgressWebhook($job, 75, 'converting_hls');
                
                // Debug: Log which MP4 files exist for HLS conversion
                $mp4FilesStatus = [];
                foreach (['480p', '360p', '240p', '144p'] as $quality) {
                    $mp4Path = $result['paths']["compressed_{$quality}"] ?? null;
                    $mp4FilesStatus[$quality] = [
                        'path' => $mp4Path,
                        'exists' => $mp4Path && file_exists($mp4Path),
                        'size' => ($mp4Path && file_exists($mp4Path)) ? filesize($mp4Path) : 0
                    ];
                }
                
                $this->log("DEBUG: Starting HLS conversion - MP4 files status", [
                    'jobId' => $jobId,
                    'mp4_files' => $mp4FilesStatus
                ]);
                
                $hlsResult = $this->hlsConverter->convertToHLS(
                    $result['paths'],
                    $job['postId'],
                    $job['year'],
                    $job['month']
                );
                
                if ($hlsResult['success']) {
                    $this->log("DEBUG: HLS conversion completed", [
                        'jobId' => $jobId,
                        'hls_master_url' => $hlsResult['hls_master_url'],
                        'qualities' => $hlsResult['qualities']
                    ]);
                    
                    // Add HLS data to result
                    $result['hls'] = [
                        'success' => true,
                        'master_url' => $hlsResult['hls_master_url'],
                        'urls' => $hlsResult['hls_urls'],
                        'qualities' => $hlsResult['qualities'],
                        'segment_duration' => $hlsResult['segment_duration']
                    ];
                    
                    // Add HLS master URL to urls array for backward compatibility
                    $result['urls']['hls_master'] = $hlsResult['hls_master_url'];
                } else {
                    $this->log("WARNING: HLS conversion failed", [
                        'jobId' => $jobId,
                        'error' => $hlsResult['error'] ?? 'unknown'
                    ], 'WARNING');
                }
            }
            
            $this->log("DEBUG: Video processing completed", [
                'jobId' => $jobId,
                'success' => $result['success']
            ]);
            
            if ($result['success']) {
                // Task 13: Send 100% progress - All complete
                $this->updateJobProgress($jobId, 100, 'completed');
                $this->sendProgressWebhook($job, 100, 'completed');
                
                // Mark job as completed
                $this->log("DEBUG: Marking job as completed in Redis...", ['jobId' => $jobId]);
                $markResult = $this->queue->markCompleted($jobId, $result);
                
                if ($markResult) {
                    $this->log("DEBUG: Job status updated to 'completed' in Redis", ['jobId' => $jobId]);
                } else {
                    $this->log("WARNING: Failed to mark job as completed in Redis", ['jobId' => $jobId], 'WARNING');
                }
                
                $elapsed = microtime(true) - $startTime;
                $this->processedCount++;
                
                $this->log("SUCCESS: Job completed successfully", [
                    'jobId' => $jobId,
                    'postId' => $postId,
                    'time' => number_format($elapsed, 2) . 's',
                    'compression_ratio' => $result['stats']['compression_ratio'] . '%',
                    'original_size' => $this->formatBytes($result['stats']['original_size']),
                    'compressed_size' => $this->formatBytes($result['stats']['compressed_size']),
                    'duration' => $result['stats']['duration'] . 's'
                ]);
                
                // Send webhook to WordPress
                $this->log("DEBUG: Sending webhook to WordPress...", ['jobId' => $jobId]);
                $this->sendWebhook($job, $result);
                
            } else {
                // Job failed - check if we should retry with exponential backoff
                $error = $result['error'] ?? 'Unknown error';
                $errorCode = $result['error_code'] ?? null;
                $isValidationError = $result['validation_error'] ?? false;
                $attempts = $this->getJobAttempts($jobId);
                $elapsed = microtime(true) - $startTime;
                
                $this->log("DEBUG: Job failed, checking retry eligibility", [
                    'jobId' => $jobId,
                    'attempts' => $attempts,
                    'max_retries' => self::MAX_RETRY_ATTEMPTS,
                    'error' => $error,
                    'error_code' => $errorCode,
                    'is_validation_error' => $isValidationError
                ]);
                
                if ($isValidationError || $this->isValidationError($errorCode)) {
                    $this->log("INFO: Validation error detected - skipping retries (permanent failure)", [
                        'jobId' => $jobId,
                        'postId' => $postId,
                        'error_code' => $errorCode,
                        'error' => $error
                    ]);
                    
                    $dlqResult = $this->queue->moveToDeadLetterQueue($jobId, $error);
                    
                    if (!$dlqResult) {
                        $this->log("WARNING: Failed to move validation error to DLQ, marking as failed", [
                            'jobId' => $jobId
                        ], 'WARNING');
                        $this->queue->markFailed($jobId, $error);
                    }
                    
                    $this->failedCount++;
                    
                    $this->log("ERROR: Job failed due to validation error (no retry)", [
                        'jobId' => $jobId,
                        'postId' => $postId,
                        'error_code' => $errorCode,
                        'error' => $error,
                        'time' => number_format($elapsed, 2) . 's',
                        'in_dead_letter_queue' => $dlqResult
                    ], 'ERROR');
                    
                    $this->sendValidationFailureWebhook($job, $error, $errorCode, $result['video_info'] ?? null, $elapsed);
                    
                    // Task 14: Send email notification for critical failures
                    $this->sendFailureNotification($job, $error, 0);
                    
                } elseif ($attempts < self::MAX_RETRY_ATTEMPTS) {
                    // Advanced retry logic with exponential backoff
                    // MAX_RETRY_ATTEMPTS=3 means allow 3 retries, so we retry if attempts < MAX
                    $backoffDelay = $this->calculateBackoffDelay($attempts);
                    
                    $this->log("INFO: Scheduling retry #" . ($attempts + 1) . " of " . self::MAX_RETRY_ATTEMPTS . " allowed", [
                        'jobId' => $jobId,
                        'postId' => $postId,
                        'current_attempt' => $attempts,
                        'backoff_delay' => $backoffDelay . 's',
                        'delay_formula' => self::RETRY_DELAY . ' * 3^' . ($attempts) . ' = ' . $backoffDelay
                    ]);
                    
                    // Wait with exponential backoff before retrying
                    $this->log("DEBUG: Sleeping for {$backoffDelay} seconds (exponential backoff)...", [
                        'jobId' => $jobId
                    ]);
                    sleep($backoffDelay);
                    
                    // Re-queue the job for retry
                    $this->queue->requeue($job);
                    
                    $this->log("INFO: Job re-queued for retry with exponential backoff", [
                        'jobId' => $jobId,
                        'next_attempt' => $attempts + 1
                    ]);
                    
                } else {
                    // Max retries reached - move to Dead Letter Queue
                    $this->log("DEBUG: Max retries exhausted, moving job to Dead Letter Queue", [
                        'jobId' => $jobId,
                        'attempts' => $attempts,
                        'max_retries' => self::MAX_RETRY_ATTEMPTS
                    ]);
                    
                    // Clean up any partially processed files
                    $this->log("DEBUG: Cleaning up partially processed files...", ['jobId' => $jobId]);
                    $cleanupResult = $this->cleanupFailedJob($job);
                    
                    if ($cleanupResult['removed_count'] > 0) {
                        $this->log("INFO: Cleanup removed partial files", [
                            'jobId' => $jobId,
                            'files_removed' => $cleanupResult['removed_count']
                        ]);
                    }
                    
                    // Move to Dead Letter Queue
                    $dlqResult = $this->queue->moveToDeadLetterQueue($jobId, $error);
                    
                    if ($dlqResult) {
                        $this->log("INFO: Job moved to Dead Letter Queue", [
                            'jobId' => $jobId,
                            'attempts' => $attempts
                        ]);
                    } else {
                        $this->log("WARNING: Failed to move job to Dead Letter Queue, marking as failed instead", [
                            'jobId' => $jobId
                        ], 'WARNING');
                        $this->queue->markFailed($jobId, $error);
                    }
                    
                    $this->failedCount++;
                    
                    $this->log("ERROR: Job failed permanently (max retries exceeded, moved to DLQ)", [
                        'jobId' => $jobId,
                        'postId' => $postId,
                        'error' => $error,
                        'attempts' => $attempts,
                        'time' => number_format($elapsed, 2) . 's',
                        'cleanup_files' => $cleanupResult['removed_count'] ?? 0,
                        'in_dead_letter_queue' => $dlqResult
                    ], 'ERROR');
                    
                    // Send enhanced failure webhook to WordPress with full details
                    $this->log("DEBUG: Sending detailed failure webhook to WordPress...", ['jobId' => $jobId]);
                    $this->sendFailureWebhook($job, $error, $attempts, $elapsed, $dlqResult);
                    
                    // Task 14: Send email notification for critical failures
                    $this->sendFailureNotification($job, $error, $attempts);
                }
            }
            
        } catch (Exception $e) {
            $elapsed = microtime(true) - $startTime;
            $attempts = $this->getJobAttempts($jobId);
            
            $this->log("ERROR: Exception while processing job: " . $e->getMessage(), [
                'jobId' => $jobId,
                'postId' => $postId,
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ], 'ERROR');
            
            // Clean up any partially processed files
            $this->log("DEBUG: Cleaning up files after exception...", ['jobId' => $jobId]);
            $cleanupResult = $this->cleanupFailedJob($job);
            
            // Move to Dead Letter Queue (exceptions are typically non-recoverable)
            $this->log("DEBUG: Moving job to Dead Letter Queue due to exception...", ['jobId' => $jobId]);
            $dlqResult = $this->queue->moveToDeadLetterQueue($jobId, $e->getMessage());
            
            if (!$dlqResult) {
                $this->log("WARNING: Failed to move to DLQ, marking as failed instead", ['jobId' => $jobId], 'WARNING');
                $this->queue->markFailed($jobId, $e->getMessage());
            }
            
            $this->failedCount++;
            
            // Send enhanced failure webhook to WordPress
            $this->log("DEBUG: Sending failure webhook to WordPress due to exception...", ['jobId' => $jobId]);
            $this->sendFailureWebhook($job, $e->getMessage(), $attempts, $elapsed, $dlqResult);
            
            // Task 14: Send email notification for critical failures
            $this->sendFailureNotification($job, $e->getMessage(), $attempts);
        }
        
        // Log statistics after each job
        $this->log("DEBUG: Current worker statistics:");
        $this->logStats();
        $this->log("================================================");
        $this->log("");
    }
    
    /**
     * Validate job data with strict type checking
     */
    private function validateJobData($job) {
        $required = ['jobId', 'postId', 'wpMediaPath', 'year', 'month'];
        
        foreach ($required as $field) {
            if (!isset($job[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
        
        // Strict validation - must be exact integers
        if (!ctype_digit((string)$job['postId']) || $job['postId'] <= 0) {
            throw new Exception("Invalid postId: must be positive integer, got: {$job['postId']}");
        }
        
        if (!ctype_digit((string)$job['year']) || $job['year'] < 2000 || $job['year'] > 2100) {
            throw new Exception("Invalid year: must be 2000-2100, got: {$job['year']}");
        }
        
        if (!ctype_digit((string)$job['month']) || $job['month'] < 1 || $job['month'] > 12) {
            throw new Exception("Invalid month: must be 1-12, got: {$job['month']}");
        }
        
        // Validate wpMediaPath
        if (empty($job['wpMediaPath']) || !is_string($job['wpMediaPath'])) {
            throw new Exception("Invalid wpMediaPath: must be non-empty string");
        }
        
        // Decode and check for path traversal attempts
        $decodedPath = rawurldecode(rawurldecode($job['wpMediaPath']));
        if (preg_match('#(\.\.)|(%2e%2e)|(%252e)|(\x00)#i', $decodedPath)) {
            throw new Exception("Invalid wpMediaPath: contains forbidden sequences");
        }
    }
    
    /**
     * Send webhook to WordPress with results (including all quality URLs)
     */
    private function sendWebhook($job, $result) {
        $webhookUrl = $this->config['wordpress_webhook_url'] ?? null;
        
        if (empty($webhookUrl) || $webhookUrl === 'not set') {
            $this->log("DEBUG: Webhook skipped - URL not configured", [
                'jobId' => $job['jobId'],
                'config_value' => $webhookUrl
            ], 'WARNING');
            return;
        }
        
        $this->log("DEBUG: Preparing multi-quality webhook payload...", [
            'jobId' => $job['jobId'],
            'url' => $webhookUrl
        ]);
        
        // Get all quality URLs from result
        $compressed480p = $result['urls']['compressed_480p'] ?? '';
        $compressed360p = $result['urls']['compressed_360p'] ?? '';
        $compressed240p = $result['urls']['compressed_240p'] ?? '';
        $compressed144p = $result['urls']['compressed_144p'] ?? '';
        
        // HLS master URL (Task 11 - adaptive streaming)
        $hlsMasterUrl = $result['hls']['master_url'] ?? $result['urls']['hls_master'] ?? '';
        
        // Fallback for HLS URL (use HLS master if available, otherwise 480p)
        $hlsUrl = $hlsMasterUrl ?: $compressed480p 
            ?: ($result['hls_url'] ?? '');
        
        // Get thumbnail WebP URL if available - check multiple possible locations
        $thumbnailWebpUrl = null;
        
        // Primary: Check result['thumbnail']['webp_url']
        if (!empty($result['thumbnail']['webp_url'])) {
            $thumbnailWebpUrl = $result['thumbnail']['webp_url'];
            $this->log("DEBUG: Thumbnail URL found in result['thumbnail']['webp_url']", [
                'jobId' => $job['jobId'],
                'thumbnailUrl' => $thumbnailWebpUrl
            ]);
        }
        // Fallback: Check result['thumbnail']['urls']['thumbnail_webp']
        elseif (!empty($result['thumbnail']['urls']['thumbnail_webp'])) {
            $thumbnailWebpUrl = $result['thumbnail']['urls']['thumbnail_webp'];
            $this->log("DEBUG: Thumbnail URL found in result['thumbnail']['urls']['thumbnail_webp']", [
                'jobId' => $job['jobId'],
                'thumbnailUrl' => $thumbnailWebpUrl
            ]);
        }
        // Fallback: Check result['urls']['thumbnail_webp']
        elseif (!empty($result['urls']['thumbnail_webp'])) {
            $thumbnailWebpUrl = $result['urls']['thumbnail_webp'];
            $this->log("DEBUG: Thumbnail URL found in result['urls']['thumbnail_webp']", [
                'jobId' => $job['jobId'],
                'thumbnailUrl' => $thumbnailWebpUrl
            ]);
        }
        // No thumbnail URL found
        else {
            $this->log("DEBUG: No thumbnail WebP URL found in result", [
                'jobId' => $job['jobId'],
                'has_thumbnail_key' => isset($result['thumbnail']),
                'thumbnail_structure' => isset($result['thumbnail']) ? array_keys($result['thumbnail']) : 'not set'
            ], 'WARNING');
        }
        
        $payload = [
            'jobId' => $job['jobId'],
            'postId' => $job['postId'],
            'status' => 'completed',
            // All quality URLs (MP4)
            'compressed480pUrl' => $compressed480p,
            'compressed360pUrl' => $compressed360p,
            'compressed240pUrl' => $compressed240p,
            'compressed144pUrl' => $compressed144p,
            // Alternative field names for compatibility
            'compressed_video_480p' => $compressed480p,
            'compressed_video_360p' => $compressed360p,
            'compressed_video_240p' => $compressed240p,
            'compressed_video_144p' => $compressed144p,
            // HLS master playlist URL (Task 11)
            'hlsMasterUrl' => $hlsMasterUrl,
            'hls_master_url' => $hlsMasterUrl,
            'hls_url' => $hlsUrl,
            // HLS quality playlists
            'hls_480p' => $result['hls']['urls']['480p'] ?? '',
            'hls_360p' => $result['hls']['urls']['360p'] ?? '',
            'hls_240p' => $result['hls']['urls']['240p'] ?? '',
            'hls_144p' => $result['hls']['urls']['144p'] ?? '',
            // Thumbnail
            'compressedThumbnailWebp' => $thumbnailWebpUrl,
            'thumbnail_webp' => $thumbnailWebpUrl,
            // Stats
            'original_size' => $result['stats']['original_size'] ?? 0,
            'compressed_size' => $result['stats']['compressed_size'] ?? 0,
            'compression_ratio' => $result['stats']['compression_ratio'] ?? 0,
            'duration' => $result['stats']['duration'] ?? 0,
            'processing_time' => $result['stats']['processing_time'] ?? 0,
            'completed_at' => date('Y-m-d H:i:s'),
            'quality_stats' => $result['stats']['quality_stats'] ?? null,
            'thumbnail_stats' => isset($result['thumbnail']) ? [
                'original_size' => $result['thumbnail']['original_size'] ?? 0,
                'webp_size' => $result['thumbnail']['webp_size'] ?? 0,
                'compression_ratio' => $result['thumbnail']['compression_ratio'] ?? 0,
                'dimensions' => $result['thumbnail']['dimensions'] ?? null
            ] : null,
            // HLS info
            'hls_info' => isset($result['hls']) ? [
                'qualities' => $result['hls']['qualities'] ?? [],
                'segment_duration' => $result['hls']['segment_duration'] ?? 6
            ] : null
        ];
        
        $this->log("DEBUG: Multi-quality webhook payload prepared", [
            'jobId' => $job['jobId'],
            'postId' => $job['postId'],
            'qualities_available' => [
                '480p' => !empty($compressed480p),
                '360p' => !empty($compressed360p),
                '240p' => !empty($compressed240p),
                '144p' => !empty($compressed144p)
            ],
            'hls_master_url' => $hlsMasterUrl ?: 'not available',
            'thumbnail_webp' => $thumbnailWebpUrl ?? 'not available',
            'payload_size' => strlen(json_encode($payload)) . ' bytes'
        ]);
        
        $this->log("DEBUG: Sending HTTP POST request to WordPress...", [
            'url' => $webhookUrl,
            'timeout' => 30
        ]);
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . ($this->config['api_key'] ?? '')
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $elapsed = microtime(true) - $startTime;
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->log("ERROR: Webhook request failed", [
                'jobId' => $job['jobId'],
                'error' => $error,
                'time' => number_format($elapsed, 2) . 's'
            ], 'ERROR');
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $this->log("DEBUG: Webhook sent successfully", [
                'jobId' => $job['jobId'],
                'httpCode' => $httpCode,
                'time' => number_format($elapsed, 2) . 's',
                'response_size' => strlen($response) . ' bytes'
            ]);
        } else {
            $this->log("WARNING: Webhook returned non-success status", [
                'jobId' => $job['jobId'],
                'httpCode' => $httpCode,
                'response' => substr($response, 0, 500),
                'time' => number_format($elapsed, 2) . 's'
            ], 'WARNING');
        }
    }
    
    /**
     * Get job attempt count from Redis
     */
    private function getJobAttempts($jobId) {
        try {
            $jobStatus = $this->queue->getJobStatus($jobId);
            return isset($jobStatus['attempts']) ? (int)$jobStatus['attempts'] : 1;
        } catch (Exception $e) {
            $this->log("WARNING: Could not get job attempts", [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ], 'WARNING');
            return 1;
        }
    }
    
    /**
     * Calculate exponential backoff delay for retries
     * Uses base_delay * 3^(attempts-1) formula:
     * - Attempt 1: 5 seconds
     * - Attempt 2: 15 seconds (5 * 3)
     * - Attempt 3: 45 seconds (5 * 3 * 3)
     * 
     * @param int $attempts Current attempt number (1-based)
     * @return int Delay in seconds
     */
    private function calculateBackoffDelay($attempts) {
        return self::RETRY_DELAY * pow(3, $attempts - 1);
    }
    
    /**
     * Check if error code is a validation error (permanent failure, no retry)
     * Task 15: Validation errors should not be retried
     * 
     * @param string|null $errorCode Error code from compression result
     * @return bool True if this is a validation error
     */
    private function isValidationError($errorCode) {
        if ($errorCode === null) {
            return false;
        }
        return in_array($errorCode, self::$VALIDATION_ERROR_CODES);
    }
    
    /**
     * Send validation failure webhook to WordPress
     * Task 15: Sends immediate failure webhook with validation error details
     * 
     * @param array $job Job data
     * @param string $error Error message
     * @param string|null $errorCode Validation error code
     * @param array|null $videoInfo Video metadata from validation
     * @param float $processingTime Processing time before failure in seconds
     */
    private function sendValidationFailureWebhook($job, $error, $errorCode, $videoInfo, $processingTime = 0) {
        $webhookUrl = $this->config['wordpress_webhook_url'] ?? null;
        
        if (empty($webhookUrl) || $webhookUrl === 'not set') {
            $this->log("DEBUG: Validation failure webhook skipped - URL not configured", [
                'jobId' => $job['jobId'],
                'error_code' => $errorCode
            ], 'WARNING');
            return;
        }
        
        $this->log("DEBUG: Preparing validation failure webhook payload...", [
            'jobId' => $job['jobId'],
            'error_code' => $errorCode
        ]);
        
        $payload = [
            'jobId' => $job['jobId'],
            'postId' => $job['postId'],
            'status' => 'failed',
            'error' => $error,
            'error_code' => $errorCode,
            'error_type' => 'validation',
            'is_validation_error' => true,
            'can_retry' => false,
            'failed_at' => date('Y-m-d H:i:s'),
            'processing_time_seconds' => round($processingTime, 2),
            'validation_details' => [
                'error_code' => $errorCode,
                'error_message' => $error,
                'video_info' => $videoInfo ? [
                    'duration' => $videoInfo['duration'] ?? null,
                    'file_size_mb' => $videoInfo['file_size_mb'] ?? null,
                    'video_codec' => $videoInfo['video_codec'] ?? null,
                    'container' => $videoInfo['container'] ?? null,
                    'resolution' => $videoInfo['resolution'] ?? null,
                    'corrupted' => $videoInfo['corrupted'] ?? null
                ] : null,
                'limits' => [
                    'max_duration_seconds' => VideoCompressor::MAX_VIDEO_DURATION,
                    'max_file_size_mb' => VideoCompressor::MAX_VIDEO_SIZE_MB,
                    'allowed_codecs' => VideoCompressor::ALLOWED_CODECS,
                    'allowed_containers' => VideoCompressor::ALLOWED_CONTAINERS
                ]
            ]
        ];
        
        $this->log("DEBUG: Validation failure webhook payload prepared", [
            'jobId' => $job['jobId'],
            'postId' => $job['postId'],
            'error_code' => $errorCode,
            'has_video_info' => $videoInfo !== null
        ]);
        
        $this->log("DEBUG: Sending validation failure webhook to WordPress...", [
            'url' => $webhookUrl,
            'timeout' => 30
        ]);
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . ($this->config['api_key'] ?? '')
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $requestStartTime = microtime(true);
        $response = curl_exec($ch);
        $elapsed = microtime(true) - $requestStartTime;
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->log("ERROR: Validation failure webhook request failed", [
                'jobId' => $job['jobId'],
                'error' => $curlError,
                'time' => number_format($elapsed, 2) . 's'
            ], 'ERROR');
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $this->log("DEBUG: Validation failure webhook sent successfully", [
                'jobId' => $job['jobId'],
                'httpCode' => $httpCode,
                'time' => number_format($elapsed, 2) . 's'
            ]);
        } else {
            $this->log("WARNING: Validation failure webhook returned non-success status", [
                'jobId' => $job['jobId'],
                'httpCode' => $httpCode,
                'response' => substr($response, 0, 500),
                'time' => number_format($elapsed, 2) . 's'
            ], 'WARNING');
        }
    }
    
    /**
     * Cleanup partially processed files for a failed job
     * Removes partially compressed videos, incomplete HLS segments, and temp files
     * 
     * @param array $job Job data
     * @return array Cleanup result with list of removed files
     */
    private function cleanupFailedJob($job) {
        $jobId = $job['jobId'] ?? 'unknown';
        $postId = $job['postId'] ?? 'unknown';
        $year = $job['year'] ?? date('Y');
        $month = $job['month'] ?? date('m');
        
        $this->log("DEBUG: Starting cleanup for failed job", [
            'jobId' => $jobId,
            'postId' => $postId
        ]);
        
        $removedFiles = [];
        $errors = [];
        
        $videosDir = $this->config['videos_dir'] ?? __DIR__ . '/videos';
        $hlsDir = $this->config['hls_dir'] ?? __DIR__ . '/hls';
        
        $videoPatterns = [
            "{$videosDir}/{$year}/{$month}/{$postId}_*.mp4",
            "{$videosDir}/{$year}/{$month}/{$postId}_*.mp4.tmp",
            "{$videosDir}/{$year}/{$month}/{$postId}_*.part"
        ];
        
        foreach ($videoPatterns as $pattern) {
            $files = glob($pattern);
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (@unlink($file)) {
                            $removedFiles[] = $file;
                            $this->log("DEBUG: Removed partial video file", ['file' => $file]);
                        } else {
                            $errors[] = "Failed to remove: {$file}";
                            $this->log("WARNING: Failed to remove partial video file", ['file' => $file], 'WARNING');
                        }
                    }
                }
            }
        }
        
        $hlsJobDir = "{$hlsDir}/{$postId}";
        if (is_dir($hlsJobDir)) {
            $hlsFiles = glob("{$hlsJobDir}/*.ts");
            $playlistFiles = glob("{$hlsJobDir}/*.m3u8");
            
            $allHlsFiles = array_merge($hlsFiles ?: [], $playlistFiles ?: []);
            
            foreach ($allHlsFiles as $file) {
                if (is_file($file)) {
                    if (@unlink($file)) {
                        $removedFiles[] = $file;
                    } else {
                        $errors[] = "Failed to remove: {$file}";
                    }
                }
            }
            
            if (is_dir($hlsJobDir) && count(glob("{$hlsJobDir}/*")) === 0) {
                if (@rmdir($hlsJobDir)) {
                    $removedFiles[] = $hlsJobDir;
                    $this->log("DEBUG: Removed empty HLS directory", ['dir' => $hlsJobDir]);
                }
            }
        }
        
        $tempPatterns = [
            sys_get_temp_dir() . "/ffmpeg_{$jobId}_*",
            sys_get_temp_dir() . "/video_{$postId}_*"
        ];
        
        foreach ($tempPatterns as $pattern) {
            $files = glob($pattern);
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (@unlink($file)) {
                            $removedFiles[] = $file;
                        }
                    }
                }
            }
        }
        
        $this->log("DEBUG: Cleanup completed for failed job", [
            'jobId' => $jobId,
            'files_removed' => count($removedFiles),
            'errors' => count($errors)
        ]);
        
        return [
            'success' => empty($errors),
            'removed_files' => $removedFiles,
            'removed_count' => count($removedFiles),
            'errors' => $errors
        ];
    }
    
    /**
     * Send failure webhook to WordPress
     * Notifies WordPress that video compression has failed with detailed information
     * 
     * @param array $job Job data
     * @param string $error Error message
     * @param int $attempts Number of retry attempts made (default 0)
     * @param float $processingTime Processing time before failure in seconds (default 0)
     * @param bool $movedToDeadLetter Whether job was moved to dead letter queue (default false)
     */
    private function sendFailureWebhook($job, $error, $attempts = 0, $processingTime = 0, $movedToDeadLetter = false) {
        $webhookUrl = $this->config['wordpress_webhook_url'] ?? null;
        
        if (empty($webhookUrl) || $webhookUrl === 'not set') {
            $this->log("DEBUG: Failure webhook skipped - URL not configured", [
                'jobId' => $job['jobId'],
                'config_value' => $webhookUrl
            ], 'WARNING');
            return;
        }
        
        $this->log("DEBUG: Preparing enhanced failure webhook payload...", [
            'jobId' => $job['jobId']
        ]);
        
        $payload = [
            'jobId' => $job['jobId'],
            'postId' => $job['postId'],
            'status' => 'failed',
            'error' => $error,
            'failed_at' => date('Y-m-d H:i:s'),
            'retry_attempts' => $attempts,
            'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
            'processing_time_seconds' => round($processingTime, 2),
            'moved_to_dead_letter_queue' => $movedToDeadLetter,
            'failure_details' => [
                'final_error' => $error,
                'total_attempts' => $attempts,
                'exhausted_retries' => $attempts >= self::MAX_RETRY_ATTEMPTS,
                'in_dead_letter_queue' => $movedToDeadLetter,
                'can_be_retried' => !$movedToDeadLetter
            ]
        ];
        
        $this->log("DEBUG: Enhanced failure webhook payload prepared", [
            'jobId' => $job['jobId'],
            'postId' => $job['postId'],
            'error' => substr($error, 0, 100),
            'attempts' => $attempts,
            'processing_time' => round($processingTime, 2) . 's',
            'moved_to_dlq' => $movedToDeadLetter
        ]);
        
        $this->log("DEBUG: Sending failure webhook to WordPress...", [
            'url' => $webhookUrl,
            'timeout' => 30
        ]);
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . ($this->config['api_key'] ?? '')
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $requestStartTime = microtime(true);
        $response = curl_exec($ch);
        $elapsed = microtime(true) - $requestStartTime;
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->log("ERROR: Failure webhook request failed", [
                'jobId' => $job['jobId'],
                'error' => $curlError,
                'time' => number_format($elapsed, 2) . 's'
            ], 'ERROR');
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $this->log("DEBUG: Failure webhook sent successfully", [
                'jobId' => $job['jobId'],
                'httpCode' => $httpCode,
                'time' => number_format($elapsed, 2) . 's'
            ]);
        } else {
            $this->log("WARNING: Failure webhook returned non-success status", [
                'jobId' => $job['jobId'],
                'httpCode' => $httpCode,
                'response' => substr($response, 0, 500),
                'time' => number_format($elapsed, 2) . 's'
            ], 'WARNING');
        }
    }
    
    /**
     * Calculate estimated time remaining for a job based on elapsed time and progress
     * 
     * @param string $jobId Job ID
     * @param int $progress Current progress percentage (0-100)
     * @return int|null Estimated remaining seconds, or null if cannot calculate
     */
    private function calculateEstimatedTimeRemaining($jobId, $progress) {
        if ($progress <= 0 || $progress >= 100) {
            return null;
        }
        
        $startTime = $this->jobStartTimes[$jobId] ?? null;
        if (!$startTime) {
            return null;
        }
        
        $elapsed = microtime(true) - $startTime;
        
        // Calculate estimated total time based on current progress
        // Use a weighted average that gives more weight to recent progress
        $estimatedTotalTime = ($elapsed / $progress) * 100;
        $estimatedRemaining = max(0, $estimatedTotalTime - $elapsed);
        
        return (int) ceil($estimatedRemaining);
    }
    
    /**
     * Format seconds into human-readable time
     * 
     * @param int $seconds Number of seconds
     * @return string Formatted time string (e.g., "1m 30s", "45s")
     */
    private function formatTimeRemaining($seconds) {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        if ($remainingSeconds > 0) {
            return "{$minutes}m {$remainingSeconds}s";
        }
        return "{$minutes}m";
    }
    
    /**
     * Send progress webhook to WordPress
     * Notifies WordPress about processing progress at key milestones
     * 
     * @param array $job Job data
     * @param int $progress Progress percentage (0-100)
     * @param string $stage Current processing stage (e.g., 'downloading', 'compressing', 'converting_hls')
     */
    private function sendProgressWebhook($job, $progress, $stage) {
        $webhookUrl = $this->config['wordpress_webhook_url'] ?? null;
        
        if (empty($webhookUrl) || $webhookUrl === 'not set') {
            $this->log("DEBUG: Progress webhook skipped - URL not configured", [
                'jobId' => $job['jobId'],
                'progress' => $progress,
                'stage' => $stage
            ]);
            return;
        }
        
        $jobId = $job['jobId'] ?? 'unknown';
        $postId = $job['postId'] ?? 'unknown';
        
        // Task 13: Calculate estimated time remaining
        $estimatedRemaining = $this->calculateEstimatedTimeRemaining($jobId, $progress);
        $estimatedRemainingFormatted = $estimatedRemaining !== null 
            ? $this->formatTimeRemaining($estimatedRemaining) 
            : null;
        
        $this->log("DEBUG: Sending progress webhook", [
            'jobId' => $jobId,
            'progress' => $progress . '%',
            'stage' => $stage,
            'estimated_remaining' => $estimatedRemainingFormatted ?? 'calculating...'
        ]);
        
        $payload = [
            'postId' => $postId,
            'jobId' => $jobId,
            'status' => 'processing',
            'progress' => $progress,
            'stage' => $stage,
            'estimated_remaining_seconds' => $estimatedRemaining,
            'estimated_remaining_formatted' => $estimatedRemainingFormatted,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . ($this->config['api_key'] ?? '')
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $elapsed = microtime(true) - $startTime;
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->log("WARNING: Progress webhook request failed", [
                'jobId' => $jobId,
                'error' => $curlError,
                'progress' => $progress . '%',
                'time' => number_format($elapsed, 2) . 's'
            ], 'WARNING');
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $this->log("DEBUG: Progress webhook sent successfully", [
                'jobId' => $jobId,
                'progress' => $progress . '%',
                'httpCode' => $httpCode,
                'time' => number_format($elapsed, 2) . 's'
            ]);
        } else {
            $this->log("WARNING: Progress webhook returned non-success status", [
                'jobId' => $jobId,
                'httpCode' => $httpCode,
                'progress' => $progress . '%',
                'time' => number_format($elapsed, 2) . 's'
            ], 'WARNING');
        }
    }
    
    /**
     * Update job progress in Redis
     * 
     * @param string $jobId Job ID
     * @param int $progress Progress percentage (0-100)
     * @param string $stage Current processing stage
     */
    private function updateJobProgress($jobId, $progress, $stage) {
        try {
            // Task 13: Calculate estimated time remaining
            $estimatedRemaining = $this->calculateEstimatedTimeRemaining($jobId, $progress);
            
            $updateData = [
                '_progress' => $progress,
                '_stage' => $stage,
                '_progress_updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Include estimated remaining time if available
            if ($estimatedRemaining !== null) {
                $updateData['_estimated_remaining'] = $estimatedRemaining;
                $updateData['_estimated_remaining_formatted'] = $this->formatTimeRemaining($estimatedRemaining);
            }
            
            $this->queue->updateJobData($jobId, $updateData);
            
            $this->log("DEBUG: Job progress updated in Redis", [
                'jobId' => $jobId,
                'progress' => $progress . '%',
                'stage' => $stage,
                'estimated_remaining' => $estimatedRemaining !== null ? $this->formatTimeRemaining($estimatedRemaining) : 'N/A'
            ]);
            
            // Clean up job start time tracking when job completes
            if ($progress >= 100 && isset($this->jobStartTimes[$jobId])) {
                unset($this->jobStartTimes[$jobId]);
            }
        } catch (Exception $e) {
            $this->log("WARNING: Failed to update job progress in Redis", [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ], 'WARNING');
        }
    }
    
    /**
     * Send email notification for critical failures (Task 14)
     * 
     * @param array $job Job data
     * @param string $error Error message
     * @param int $attempts Number of retry attempts made
     */
    private function sendFailureNotification($job, $error, $attempts = 0) {
        $notifyEmail = $this->config['notify_email'] ?? '';
        $emailEnabled = $this->config['email_notifications_enabled'] ?? false;
        
        if (!$emailEnabled || empty($notifyEmail)) {
            $this->log("DEBUG: Email notification skipped", [
                'jobId' => $job['jobId'] ?? 'unknown',
                'reason' => $emailEnabled ? 'No email configured' : 'Notifications disabled'
            ]);
            return;
        }
        
        $jobId = $job['jobId'] ?? 'unknown';
        $postId = $job['postId'] ?? 'unknown';
        $wpPostUrl = $job['wpPostUrl'] ?? '';
        $emailFrom = $this->config['email_from'] ?? 'noreply@localhost';
        $baseUrl = $this->config['base_url'] ?? 'https://v.ogtemplate.com';
        
        $this->log("DEBUG: Sending failure email notification", [
            'jobId' => $jobId,
            'to' => $notifyEmail
        ]);
        
        $subject = "[Video Compression CRITICAL] Job Failed: {$jobId}";
        
        $message = "CRITICAL: Video Compression Job Failed\n";
        $message .= "========================================\n\n";
        $message .= "Job ID: {$jobId}\n";
        $message .= "WordPress Post ID: {$postId}\n";
        if ($wpPostUrl) {
            $message .= "WordPress Post URL: {$wpPostUrl}\n";
        }
        $message .= "Failed At: " . date('Y-m-d H:i:s') . "\n";
        $message .= "Retry Attempts: {$attempts} / " . self::MAX_RETRY_ATTEMPTS . "\n\n";
        $message .= "Error Message:\n";
        $message .= "---------------\n";
        $message .= $error . "\n\n";
        $message .= "This job has been moved to the dead letter queue after exhausting all retry attempts.\n\n";
        $message .= "You can view the processing dashboard at:\n";
        $message .= "{$baseUrl}/dashboard.php\n\n";
        $message .= "---\n";
        $message .= "Video Compression Worker\n";
        $message .= "Server: " . gethostname() . "\n";
        
        $headers = [
            'From: ' . $emailFrom,
            'Reply-To: ' . $emailFrom,
            'X-Mailer: PHP/' . phpversion(),
            'Content-Type: text/plain; charset=UTF-8',
            'X-Priority: 1 (Highest)',
            'X-MSMail-Priority: High',
            'Importance: High'
        ];
        
        $sent = @mail($notifyEmail, $subject, $message, implode("\r\n", $headers));
        
        if ($sent) {
            $this->log("DEBUG: Failure email notification sent successfully", [
                'jobId' => $jobId,
                'to' => $notifyEmail
            ]);
        } else {
            $this->log("WARNING: Failed to send failure email notification", [
                'jobId' => $jobId,
                'to' => $notifyEmail,
                'error' => error_get_last()['message'] ?? 'Unknown error'
            ], 'WARNING');
        }
    }
    
    /**
     * Log system information on startup
     */
    private function logSystemInfo() {
        $this->log("DEBUG: System Information", [
            'php_version' => PHP_VERSION,
            'php_sapi' => php_sapi_name(),
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'redis_extension' => extension_loaded('redis') ? phpversion('redis') : 'NOT LOADED',
            'curl_extension' => extension_loaded('curl') ? 'loaded' : 'NOT LOADED',
            'ffmpeg_path' => $this->config['ffmpeg_binary'] ?? 'not configured',
            'pid' => getmypid(),
            'hostname' => gethostname()
        ]);
    }
    
    /**
     * Log Redis server information
     */
    private function logRedisInfo() {
        try {
            $stats = $this->queue->getStats();
            
            $this->log("DEBUG: Redis Server Information", [
                'connected' => $stats['connected'] ?? false,
                'redis_version' => $stats['redis_version'] ?? 'unknown',
                'uptime' => isset($stats['uptime_seconds']) ? 
                    floor($stats['uptime_seconds'] / 3600) . 'h ' . 
                    floor(($stats['uptime_seconds'] % 3600) / 60) . 'm' : 
                    'unknown',
                'memory_used' => $stats['used_memory_human'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            $this->log("WARNING: Could not retrieve Redis info", ['error' => $e->getMessage()], 'WARNING');
        }
    }
    
    /**
     * Log current queue statistics
     */
    private function logQueueStats() {
        try {
            $stats = $this->queue->getStats();
            
            $this->log("DEBUG: Queue Statistics", [
                'pending_jobs' => $stats['pending'] ?? 0,
                'processing_jobs' => $stats['processing'] ?? 0,
                'completed_total' => $stats['completed'] ?? 0,
                'failed_total' => $stats['failed'] ?? 0,
                'total_jobs' => $stats['total'] ?? 0
            ]);
        } catch (Exception $e) {
            $this->log("WARNING: Could not retrieve queue stats", ['error' => $e->getMessage()], 'WARNING');
        }
    }
    
    /**
     * Log periodic heartbeat with worker health info
     */
    private function logHeartbeat() {
        $uptime = time() - $this->startTime;
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        $this->log("HEARTBEAT: Worker is alive and running", [
            'uptime' => "{$hours}h {$minutes}m",
            'iterations' => $this->iterationCount,
            'processed' => $this->processedCount,
            'failed' => $this->failedCount,
            'memory_current' => $this->formatBytes($memoryUsage),
            'memory_peak' => $this->formatBytes($memoryPeak),
            'pid' => getmypid()
        ]);
        
        // Also log queue stats with heartbeat
        $this->logQueueStats();
    }
    
    /**
     * Log worker statistics
     */
    private function logStats() {
        $uptime = time() - $this->startTime;
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        
        $totalJobs = $this->processedCount + $this->failedCount;
        
        $this->log("  Worker Statistics", [
            'uptime' => "{$hours}h {$minutes}m",
            'iterations' => $this->iterationCount,
            'processed' => $this->processedCount,
            'failed' => $this->failedCount,
            'total' => $totalJobs,
            'success_rate' => $totalJobs > 0 ? 
                round(($this->processedCount / $totalJobs) * 100, 1) . '%' : 
                'N/A'
        ]);
    }
    
    /**
     * Handle shutdown signals
     */
    public function handleSignal($signal) {
        $signalNames = [
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT'
        ];
        
        $signalName = $signalNames[$signal] ?? "SIGNAL_{$signal}";
        
        $this->log("DEBUG: Received shutdown signal", [
            'signal' => $signal,
            'signal_name' => $signalName,
            'pid' => getmypid()
        ]);
        
        $this->running = false;
    }
    
    /**
     * Graceful shutdown
     */
    private function shutdown() {
        $this->log("");
        $this->log("===========================================");
        $this->log("  GRACEFUL SHUTDOWN INITIATED");
        $this->log("===========================================");
        
        $this->log("DEBUG: Stopping worker gracefully...");
        
        // Check for any jobs still in processing queue
        try {
            $stats = $this->queue->getStats();
            $processingCount = $stats['processing'] ?? 0;
            
            if ($processingCount > 0) {
                $this->log("WARNING: Worker stopped with jobs still in processing queue", [
                    'processing_jobs' => $processingCount
                ], 'WARNING');
                $this->log("INFO: These jobs will be automatically recovered on worker restart");
            } else {
                $this->log("DEBUG: No jobs in processing queue");
            }
        } catch (Exception $e) {
            $this->log("WARNING: Could not check processing queue on shutdown", [
                'error' => $e->getMessage()
            ], 'WARNING');
        }
        
        // Log final statistics
        $this->log("DEBUG: Final Statistics:");
        $this->logStats();
        $this->logQueueStats();
        
        $this->log("===========================================");
        $this->log("  Worker stopped gracefully");
        $this->log("  PID: " . getmypid());
        $this->log("  Timestamp: " . date('Y-m-d H:i:s'));
        $this->log("===========================================");
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Log message to file
     */
    private function log($message, $context = [], $level = 'INFO') {
        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0777, true)) {
                    error_log("[WORKER] Failed to create log directory: {$logDir}");
                    echo "[ERROR] Failed to create log directory\n";
                    return false;
                }
                chmod($logDir, 0777);
            }
            
            if (!file_exists($this->logFile)) {
                if (!touch($this->logFile)) {
                    error_log("[WORKER] Failed to create log file: {$this->logFile}");
                    echo "[ERROR] Failed to create log file\n";
                    return false;
                }
                chmod($this->logFile, 0666);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
            $logMessage = "[{$timestamp}] [{$level}] [WORKER] {$message}{$contextStr}\n";
            
            $result = file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log("[WORKER] Failed to write to log file: {$this->logFile}");
                echo "[ERROR] Failed to write to log file\n";
                return false;
            }
            
            chmod($this->logFile, 0666);
            
            // Also output to console
            echo $logMessage;
            return true;
        } catch (Exception $e) {
            error_log("[WORKER] Logging exception: " . $e->getMessage());
            echo "[ERROR] Logging exception: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Start the worker
$worker = new BackgroundWorker();
$worker->start();
