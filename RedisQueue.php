<?php
/**
 * Redis Queue Manager
 * Handles video compression job queue with status tracking and persistence
 * Supports both native Redis extension and Predis library
 * 
 * IMPORTANT: This file gracefully handles missing Composer autoloader.
 * - If native Redis extension is loaded, Predis is not needed
 * - If neither is available, file-based fallback is used
 */

// Try to load Composer autoloader from various paths (gracefully handle missing)
// NOTE: Use relative paths only - avoid hardcoded absolute paths like /var/www/html
$autoloaderPaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];

$autoloaderLoaded = false;
$predisAvailable = false;

// Only try to load autoloader if native Redis is NOT available
// This prevents unnecessary file system checks when we don't need Predis
if (!extension_loaded('redis')) {
    foreach ($autoloaderPaths as $autoloaderPath) {
        if (file_exists($autoloaderPath) && is_readable($autoloaderPath)) {
            try {
                require_once $autoloaderPath;
                $autoloaderLoaded = true;
                $predisAvailable = class_exists('Predis\Client');
                break;
            } catch (Exception $e) {
                error_log("[REDIS-QUEUE] Failed to load autoloader from {$autoloaderPath}: " . $e->getMessage());
            }
        }
    }
    
    if (!$autoloaderLoaded) {
        error_log("[REDIS-QUEUE] WARNING: Native Redis extension not loaded and Composer autoloader not found. Predis will not be available.");
    } elseif (!$predisAvailable) {
        error_log("[REDIS-QUEUE] WARNING: Autoloader loaded but Predis library not installed.");
    }
}

class RedisQueue {
    private $redis;
    private $queueName;
    private $processingQueue;
    private $config;
    private $connected = false;
    private $lastError = null;
    private $usePredis = false;
    
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_DEAD_LETTER = 'dead_letter';
    
    /**
     * Initialize Redis Queue
     * 
     * @param array $config Configuration array
     */
    public function __construct($config = []) {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 2.5,
            'password' => null,
            'database' => 0,
            'queue_name' => 'compression_queue',
            'processing_queue' => 'compression_processing',
            'log_file' => __DIR__ . '/logs/all.log'
        ], $config);
        
        $this->queueName = $this->config['queue_name'];
        $this->processingQueue = $this->config['processing_queue'];
        
        $this->connect();
    }
    
    /**
     * Connect to Redis server (supports native extension or Predis)
     */
    private function connect() {
        try {
            if (extension_loaded('redis')) {
                return $this->connectNative();
            } else if (class_exists('Predis\Client')) {
                return $this->connectPredis();
            } else {
                $this->lastError = "No Redis client available (neither native extension nor Predis)";
                $this->log($this->lastError, [], 'ERROR');
                return false;
            }
        } catch (Exception $e) {
            $this->lastError = "Redis connection exception: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            $this->connected = false;
            return false;
        }
    }
    
    /**
     * Connect using native PHP Redis extension
     */
    private function connectNative() {
        $this->redis = new Redis();
        $this->usePredis = false;
        
        $connected = $this->redis->pconnect(
            $this->config['host'],
            $this->config['port'],
            $this->config['timeout']
        );
        
        if (!$connected) {
            $this->lastError = "Failed to connect to Redis at {$this->config['host']}:{$this->config['port']}";
            $this->log($this->lastError, [], 'ERROR');
            return false;
        }
        
        if ($this->config['password']) {
            $this->redis->auth($this->config['password']);
        }
        
        $this->redis->select($this->config['database']);
        $this->redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
        
        $this->connected = true;
        $this->lastError = null;
        $this->log("Connected to Redis successfully (native extension)");
        
        return true;
    }
    
    /**
     * Connect using Predis library
     */
    private function connectPredis() {
        $this->usePredis = true;
        
        $options = [
            'scheme' => 'tcp',
            'host'   => $this->config['host'],
            'port'   => $this->config['port'],
            'database' => $this->config['database'],
            'timeout' => $this->config['timeout'],
            'read_write_timeout' => 0,
        ];
        
        if ($this->config['password']) {
            $options['password'] = $this->config['password'];
        }
        
        $this->redis = new Predis\Client($options);
        $this->redis->connect();
        
        $this->connected = true;
        $this->lastError = null;
        $this->log("Connected to Redis successfully (Predis)");
        
        return true;
    }
    
    /**
     * Check if connected to Redis
     */
    public function isConnected() {
        if (!$this->connected) {
            return false;
        }
        
        try {
            $pong = $this->redis->ping();
            if ($this->usePredis) {
                return $pong == 'PONG';
            }
            return $pong === '+PONG' || $pong === true || $pong === 'PONG';
        } catch (Exception $e) {
            $this->connected = false;
            $this->lastError = "Redis ping failed: " . $e->getMessage();
            return false;
        }
    }
    
    /**
     * Wrapper methods to handle differences between native Redis and Predis
     * Native Redis uses camelCase (lPush, hMSet), Predis uses lowercase (lpush, hmset)
     */
    private function redisLpush($key, $value) {
        if ($this->usePredis) {
            return $this->redis->lpush($key, $value);
        }
        return $this->redis->lPush($key, $value);
    }
    
    private function redisHmset($key, $hash) {
        if ($this->usePredis) {
            return $this->redis->hmset($key, $hash);
        }
        return $this->redis->hMSet($key, $hash);
    }
    
    private function redisBrpop($keys, $timeout) {
        if ($this->usePredis) {
            $result = $this->redis->brpop($keys, $timeout);
            return $result;
        }
        return $this->redis->brPop($keys, $timeout);
    }
    
    private function redisLlen($key) {
        if ($this->usePredis) {
            return $this->redis->llen($key);
        }
        return $this->redis->lLen($key);
    }
    
    private function redisHincrby($key, $field, $value) {
        if ($this->usePredis) {
            return $this->redis->hincrby($key, $field, $value);
        }
        return $this->redis->hIncrBy($key, $field, $value);
    }
    
    private function redisSadd($key, $member) {
        if ($this->usePredis) {
            return $this->redis->sadd($key, $member);
        }
        return $this->redis->sAdd($key, $member);
    }
    
    private function redisSmembers($key) {
        if ($this->usePredis) {
            return $this->redis->smembers($key);
        }
        return $this->redis->sMembers($key);
    }
    
    private function redisHgetall($key) {
        if ($this->usePredis) {
            return $this->redis->hgetall($key);
        }
        return $this->redis->hGetAll($key);
    }
    
    private function redisSrem($key, $member) {
        if ($this->usePredis) {
            return $this->redis->srem($key, $member);
        }
        return $this->redis->sRem($key, $member);
    }
    
    private function redisLindex($key, $index) {
        if ($this->usePredis) {
            return $this->redis->lindex($key, $index);
        }
        return $this->redis->lIndex($key, $index);
    }
    
    private function redisScard($key) {
        if ($this->usePredis) {
            return $this->redis->scard($key);
        }
        return $this->redis->sCard($key);
    }
    
    private function redisLrem($key, $count, $value) {
        if ($this->usePredis) {
            return $this->redis->lrem($key, $count, $value);
        }
        return $this->redis->lRem($key, $value, $count);
    }
    
    private function redisLrange($key, $start, $stop) {
        if ($this->usePredis) {
            return $this->redis->lrange($key, $start, $stop);
        }
        return $this->redis->lRange($key, $start, $stop);
    }
    
    private function redisRpush($key, $value) {
        if ($this->usePredis) {
            return $this->redis->rpush($key, $value);
        }
        return $this->redis->rPush($key, $value);
    }
    
    /**
     * Acquire a lock for a job ID using SETNX (atomic operation)
     * This prevents race conditions when multiple requests try to enqueue the same job
     * 
     * Lock is persistent (no TTL) until explicitly released via releaseJobLock()
     * This ensures long-running video processing jobs don't have their locks expire
     * 
     * @param string $jobId Job ID to lock
     * @return bool True if lock acquired, false if job is already locked/queued
     */
    private function acquireJobLock($jobId) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $lockKey = "job_lock:{$jobId}";
            
            // Use SETNX (SET if Not eXists) for atomic lock acquisition
            // Lock is persistent until explicitly released - no TTL to avoid
            // premature expiration during long video processing jobs
            if ($this->usePredis) {
                $result = $this->redis->setnx($lockKey, time());
            } else {
                $result = $this->redis->setnx($lockKey, time());
            }
            
            if ($result) {
                // Lock acquired (no TTL - lock persists until explicit release)
                $this->log("Job lock acquired (persistent)", ['jobId' => $jobId]);
                return true;
            }
            
            $this->log("Job lock already held", ['jobId' => $jobId]);
            return false;
            
        } catch (Exception $e) {
            $this->log("Error acquiring job lock: " . $e->getMessage(), ['jobId' => $jobId], 'ERROR');
            return false;
        }
    }
    
    /**
     * Release job lock when job is completed or failed
     * 
     * @param string $jobId Job ID to unlock
     */
    public function releaseJobLock($jobId) {
        if (!$this->isConnected()) {
            return;
        }
        
        try {
            $lockKey = "job_lock:{$jobId}";
            $this->redis->del($lockKey);
            $this->log("Job lock released", ['jobId' => $jobId]);
        } catch (Exception $e) {
            $this->log("Error releasing job lock: " . $e->getMessage(), ['jobId' => $jobId], 'WARNING');
        }
    }
    
    /**
     * Check if a job already exists in queues (informational check, not atomic)
     * For atomic duplicate prevention, use acquireJobLock() instead
     * 
     * @param string $jobId Job ID to check
     * @return bool True if job exists in pending or processing queue
     */
    public function jobExistsInQueues($jobId) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            // Check if lock exists (most reliable indicator)
            $lockKey = "job_lock:{$jobId}";
            if ($this->redis->exists($lockKey)) {
                $this->log("Job has active lock", ['jobId' => $jobId]);
                return true;
            }
            
            // Check job status hash
            $jobInfo = $this->redisHgetall("job:{$jobId}");
            if (!empty($jobInfo)) {
                $status = $jobInfo['status'] ?? '';
                if (in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING])) {
                    $this->log("Job already exists with status: {$status}", ['jobId' => $jobId]);
                    return true;
                }
            }
            
            return false;
        } catch (Exception $e) {
            $this->log("Error checking job existence: " . $e->getMessage(), ['jobId' => $jobId], 'ERROR');
            return false;
        }
    }
    
    /**
     * Add job to queue (FIFO - First In First Out)
     * Uses atomic lock + status hash check to prevent duplicate jobs from race conditions
     * 
     * @param array $jobData Job data including jobId, postId, etc.
     * @param bool $allowDuplicate If true, skip duplicate check (for retries after failure)
     * @return bool Success status
     */
    public function enqueue($jobData, $allowDuplicate = false) {
        if (!$this->isConnected()) {
            $this->lastError = "Redis not connected - Host: {$this->config['host']}:{$this->config['port']}, Extension loaded: " . (extension_loaded('redis') ? 'yes' : 'no');
            $this->log("Cannot enqueue: " . $this->lastError, [], 'ERROR');
            return false;
        }
        
        try {
            $jobId = $jobData['jobId'] ?? 'unknown';
            
            // Use atomic lock to prevent duplicates (unless explicitly allowed for retries)
            if (!$allowDuplicate) {
                if (!$this->acquireJobLock($jobId)) {
                    $this->log("Duplicate job rejected (lock exists): {$jobId}", [
                        'jobId' => $jobId,
                        'postId' => $jobData['postId'] ?? null
                    ], 'WARNING');
                    $this->lastError = "Job {$jobId} already exists or is being processed";
                    return false;
                }
                
                // Secondary safeguard: Check job status hash after acquiring lock
                // This catches edge cases where lock was orphaned but job is still active
                $jobInfo = $this->redisHgetall("job:{$jobId}");
                if (!empty($jobInfo)) {
                    $status = $jobInfo['status'] ?? '';
                    if (in_array($status, [self::STATUS_PENDING, self::STATUS_PROCESSING])) {
                        $this->log("Duplicate job rejected (status check): {$jobId} has status {$status}", [
                            'jobId' => $jobId,
                            'postId' => $jobData['postId'] ?? null,
                            'existing_status' => $status
                        ], 'WARNING');
                        // Release the lock we just acquired since we're not proceeding
                        $this->releaseJobLock($jobId);
                        $this->lastError = "Job {$jobId} already in {$status} state";
                        return false;
                    }
                }
            }
            
            // Add job to main queue (LPUSH = add to head, RPOP = remove from tail = FIFO)
            $queueLength = $this->redisLpush($this->queueName, json_encode($jobData));
            
            // Store job details in hash for status tracking
            $this->redisHmset("job:{$jobId}", [
                'data' => json_encode($jobData),
                'status' => self::STATUS_PENDING,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'attempts' => 0
            ]);
            
            // Set expiry on job data (30 days)
            $this->redis->expire("job:{$jobId}", 30 * 24 * 60 * 60);
            
            $this->log("Job enqueued: {$jobId}, Queue length: {$queueLength}", [
                'jobId' => $jobId,
                'postId' => $jobData['postId'] ?? null,
                'queue_length' => $queueLength
            ]);
            
            $this->lastError = null; // Clear error on success
            return true;
            
        } catch (Exception $e) {
            $this->lastError = "Redis enqueue exception: " . $e->getMessage();
            $this->log("Enqueue error: " . $e->getMessage(), [
                'jobId' => $jobId ?? 'unknown',
                'error' => $e->getMessage()
            ], 'ERROR');
            return false;
        }
    }
    
    /**
     * Get next job from queue (blocking operation)
     * 
     * @param int $timeout Timeout in seconds (0 = wait forever)
     * @return array|null Job data or null if timeout
     */
    public function dequeue($timeout = 10) {
        if (!$this->isConnected()) {
            $this->log("Cannot dequeue: Not connected to Redis", [], 'ERROR');
            return null;
        }
        
        try {
            // Log queue check
            $queueLength = $this->redisLlen($this->queueName);
            $this->log("Checking queue for jobs", [
                'queue_length' => $queueLength,
                'timeout' => $timeout . 's'
            ]);
            
            // BRPOP = Blocking Right Pop (FIFO with LPUSH)
            $result = $this->redisBrpop([$this->queueName], $timeout);
            
            if (!$result) {
                $this->log("Queue timeout: No jobs available", [
                    'timeout' => $timeout . 's',
                    'queue_name' => $this->queueName
                ]);
                return null;  // Timeout, no jobs available
            }
            
            $jobData = json_decode($result[1], true);
            $jobId = $jobData['jobId'] ?? 'unknown';
            
            // Move to processing queue
            $this->redisLpush($this->processingQueue, json_encode($jobData));
            
            // Update job status
            $this->setJobStatus($jobId, self::STATUS_PROCESSING);
            
            // Increment attempts counter
            $this->redisHincrby("job:{$jobId}", 'attempts', 1);
            
            $this->log("Job dequeued: {$jobId}", [
                'jobId' => $jobId,
                'postId' => $jobData['postId'] ?? null
            ]);
            
            return $jobData;
            
        } catch (Exception $e) {
            $this->log("Dequeue error: " . $e->getMessage(), [], 'ERROR');
            return null;
        }
    }
    
    /**
     * Mark job as completed
     * 
     * @param string $jobId Job ID
     * @param array $result Optional result data
     * @return bool Success status
     */
    public function markCompleted($jobId, $result = []) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            // Update job status
            $this->redisHmset("job:{$jobId}", [
                'status' => self::STATUS_COMPLETED,
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'result' => json_encode($result)
            ]);
            
            // Remove from processing queue
            $this->removeFromProcessing($jobId);
            
            // Release job lock to allow re-processing if needed
            $this->releaseJobLock($jobId);
            
            // Add to completed set (for statistics)
            $this->redisSadd('completed_jobs', $jobId);
            
            $this->log("Job completed: {$jobId}", [
                'jobId' => $jobId
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Mark completed error: " . $e->getMessage(), [], 'ERROR');
            return false;
        }
    }
    
    /**
     * Re-queue a job for retry
     * 
     * @param array $jobData Job data to re-queue
     * @return bool Success status
     */
    public function requeue($jobData) {
        if (!$this->isConnected()) {
            $this->log("Cannot requeue: Not connected to Redis", [], 'ERROR');
            return false;
        }
        
        try {
            $jobId = $jobData['jobId'] ?? 'unknown';
            
            // Remove from processing queue first
            $this->removeFromProcessing($jobId);
            
            // Add back to main queue (at the end for fair retry)
            $queueLength = $this->redisRpush($this->queueName, json_encode($jobData));
            
            // Update job status back to pending
            $this->redisHmset("job:{$jobId}", [
                'status' => self::STATUS_PENDING,
                'updated_at' => date('Y-m-d H:i:s'),
                'requeued_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->log("Job requeued for retry: {$jobId}", [
                'jobId' => $jobId,
                'postId' => $jobData['postId'] ?? null,
                'queue_length' => $queueLength
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Requeue error: " . $e->getMessage(), [
                'jobId' => $jobData['jobId'] ?? 'unknown',
                'error' => $e->getMessage()
            ], 'ERROR');
            return false;
        }
    }
    
    /**
     * Mark job as failed
     * 
     * @param string $jobId Job ID
     * @param string $error Error message
     * @return bool Success status
     */
    public function markFailed($jobId, $error = '') {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            // Update job status
            $this->redisHmset("job:{$jobId}", [
                'status' => self::STATUS_FAILED,
                'failed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'error' => $error
            ]);
            
            // Remove from processing queue
            $this->removeFromProcessing($jobId);
            
            // Release job lock to allow re-submission
            $this->releaseJobLock($jobId);
            
            // Add to failed set
            $this->redisSadd('failed_jobs', $jobId);
            
            $this->log("Job failed: {$jobId}", [
                'jobId' => $jobId,
                'error' => $error
            ], 'ERROR');
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Mark failed error: " . $e->getMessage(), [], 'ERROR');
            return false;
        }
    }
    
    /**
     * Remove job from processing queue
     */
    private function removeFromProcessing($jobId) {
        try {
            $processingItems = $this->redisLrange($this->processingQueue, 0, -1);
            
            foreach ($processingItems as $item) {
                $data = json_decode($item, true);
                
                if (isset($data['jobId']) && $data['jobId'] === $jobId) {
                    $this->redisLrem($this->processingQueue, 1, $item);
                    break;
                }
            }
        } catch (Exception $e) {
            $this->log("Remove from processing error: " . $e->getMessage(), [], 'ERROR');
        }
    }
    
    /**
     * Get job status
     * 
     * @param string $jobId Job ID
     * @return array|null Job info or null if not found
     */
    public function getJobStatus($jobId) {
        if (!$this->isConnected()) {
            return null;
        }
        
        try {
            $jobInfo = $this->redisHgetall("job:{$jobId}");
            
            if (empty($jobInfo)) {
                return null;
            }
            
            return $jobInfo;
            
        } catch (Exception $e) {
            $this->log("Get job status error: " . $e->getMessage(), [], 'ERROR');
            return null;
        }
    }
    
    /**
     * Set job status
     */
    private function setJobStatus($jobId, $status) {
        try {
            $this->redisHmset("job:{$jobId}", [
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->log("Set job status error: " . $e->getMessage(), [], 'ERROR');
        }
    }
    
    /**
     * Update job data with additional fields
     * Used for progress tracking and metadata updates
     * 
     * @param string $jobId Job ID
     * @param array $data Key-value pairs to update
     * @return bool Success status
     */
    public function updateJobData($jobId, $data) {
        if (!$this->isConnected()) {
            $this->log("Cannot update job data: Not connected to Redis", [], 'ERROR');
            return false;
        }
        
        try {
            $data['updated_at'] = date('Y-m-d H:i:s');
            
            $this->redisHmset("job:{$jobId}", $data);
            
            $this->log("Job data updated: {$jobId}", [
                'jobId' => $jobId,
                'updated_fields' => array_keys($data)
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Update job data error: " . $e->getMessage(), [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ], 'ERROR');
            return false;
        }
    }
    
    /**
     * Get last error message
     * 
     * @return string|null Last error message or null if no error
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * Get queue statistics
     * 
     * @return array Statistics
     */
    public function getStats() {
        if (!$this->isConnected()) {
            return [
                'connected' => false,
                'error' => 'Not connected to Redis'
            ];
        }
        
        try {
            $pendingCount = $this->redisLlen($this->queueName);
            $processingCount = $this->redisLlen($this->processingQueue);
            $completedCount = $this->redisScard('completed_jobs');
            $failedCount = $this->redisScard('failed_jobs');
            
            $info = $this->redis->info();
            
            return [
                'connected' => true,
                'pending' => $pendingCount,
                'processing' => $processingCount,
                'completed' => $completedCount,
                'failed' => $failedCount,
                'total' => $pendingCount + $processingCount + $completedCount + $failedCount,
                'redis_version' => $info['redis_version'] ?? 'unknown',
                'uptime_seconds' => $info['uptime_in_seconds'] ?? 0,
                'used_memory_human' => $info['used_memory_human'] ?? 'unknown'
            ];
            
        } catch (Exception $e) {
            $this->log("Get stats error: " . $e->getMessage(), [], 'ERROR');
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get queue length
     * 
     * @return int Queue length
     */
    public function getQueueLength() {
        if (!$this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redisLlen($this->queueName);
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Clear all queues (DANGER - use only for testing)
     */
    public function clearAll() {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            $this->redis->del($this->queueName);
            $this->redis->del($this->processingQueue);
            $this->redis->del('completed_jobs');
            $this->redis->del('failed_jobs');
            
            $this->log("All queues cleared", [], 'WARNING');
            
            return true;
        } catch (Exception $e) {
            $this->log("Clear all error: " . $e->getMessage(), [], 'ERROR');
            return false;
        }
    }
    
    /**
     * Logging helper
     */
    private function log($message, $context = [], $level = 'INFO') {
        try {
            $logFile = $this->config['log_file'];
            $logDir = dirname($logFile);
            
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0777, true)) {
                    error_log("[REDIS-QUEUE] Failed to create log directory: {$logDir}");
                    return false;
                }
                chmod($logDir, 0777);
            }
            
            if (!file_exists($logFile)) {
                if (!touch($logFile)) {
                    error_log("[REDIS-QUEUE] Failed to create log file: {$logFile}");
                    return false;
                }
                chmod($logFile, 0666);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
            $logMessage = "[{$timestamp}] [{$level}] [REDIS-QUEUE] {$message}{$contextStr}\n";
            
            $result = file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log("[REDIS-QUEUE] Failed to write to log file: {$logFile}");
                return false;
            }
            
            chmod($logFile, 0666);
            return true;
        } catch (Exception $e) {
            error_log("[REDIS-QUEUE] Logging exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the currently processing job from compression_processing queue
     * 
     * @return array|null Job data with postId, jobId, start time or null if no job is processing
     */
    public function getCurrentProcessingJob() {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot get current processing job: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return null;
        }
        
        try {
            $length = $this->redisLlen($this->processingQueue);
            
            if ($length === 0) {
                return null;
            }
            
            $item = $this->redisLindex($this->processingQueue, 0);
            
            if (!$item) {
                return null;
            }
            
            $jobData = json_decode($item, true);
            
            if (!$jobData) {
                return null;
            }
            
            $jobId = $jobData['jobId'] ?? null;
            $jobInfo = null;
            
            if ($jobId) {
                $jobInfo = $this->redisHgetall("job:{$jobId}");
            }
            
            $startTime = null;
            if ($jobInfo && isset($jobInfo['updated_at'])) {
                $startTime = $jobInfo['updated_at'];
            }
            
            return [
                'jobId' => $jobId,
                'postId' => $jobData['postId'] ?? null,
                'start_time' => $startTime,
                'data' => $jobData
            ];
            
        } catch (Exception $e) {
            $this->lastError = "Get current processing job error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return null;
        }
    }
    
    /**
     * Get the last N completed jobs
     * 
     * @param int $limit Maximum number of jobs to return (default 50)
     * @return array Array of completed jobs with postId, jobId, completed_at, processing_time, compression_ratio
     */
    public function getRecentCompletedJobs($limit = 50) {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot get completed jobs: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return [];
        }
        
        try {
            $completedJobIds = $this->redisSmembers('completed_jobs');
            
            if (empty($completedJobIds)) {
                return [];
            }
            
            $jobs = [];
            
            foreach ($completedJobIds as $jobId) {
                $jobInfo = $this->redisHgetall("job:{$jobId}");
                
                if (empty($jobInfo)) {
                    continue;
                }
                
                $jobData = isset($jobInfo['data']) ? json_decode($jobInfo['data'], true) : [];
                $result = isset($jobInfo['result']) ? json_decode($jobInfo['result'], true) : [];
                
                $createdAt = $jobInfo['created_at'] ?? null;
                $completedAt = $jobInfo['completed_at'] ?? null;
                $processingTime = null;
                
                if ($createdAt && $completedAt) {
                    $startTs = strtotime($createdAt);
                    $endTs = strtotime($completedAt);
                    if ($startTs && $endTs) {
                        $processingTime = $endTs - $startTs;
                    }
                }
                
                $jobs[] = [
                    'jobId' => $jobId,
                    'postId' => $jobData['postId'] ?? null,
                    'completed_at' => $completedAt,
                    'processing_time' => $processingTime,
                    'compression_ratio' => $result['compression_ratio'] ?? null,
                    'original_size' => $result['original_size'] ?? null,
                    'compressed_size' => $result['compressed_size'] ?? null
                ];
            }
            
            usort($jobs, function($a, $b) {
                $aTime = $a['completed_at'] ? strtotime($a['completed_at']) : 0;
                $bTime = $b['completed_at'] ? strtotime($b['completed_at']) : 0;
                return $bTime - $aTime;
            });
            
            return array_slice($jobs, 0, $limit);
            
        } catch (Exception $e) {
            $this->lastError = "Get recent completed jobs error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return [];
        }
    }
    
    /**
     * Get the last N failed jobs
     * 
     * @param int $limit Maximum number of jobs to return (default 50)
     * @return array Array of failed jobs with postId, jobId, failed_at, error message, attempts
     */
    public function getRecentFailedJobs($limit = 50) {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot get failed jobs: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return [];
        }
        
        try {
            $failedJobIds = $this->redisSmembers('failed_jobs');
            
            if (empty($failedJobIds)) {
                return [];
            }
            
            $jobs = [];
            
            foreach ($failedJobIds as $jobId) {
                $jobInfo = $this->redisHgetall("job:{$jobId}");
                
                if (empty($jobInfo)) {
                    continue;
                }
                
                $jobData = isset($jobInfo['data']) ? json_decode($jobInfo['data'], true) : [];
                
                $jobs[] = [
                    'jobId' => $jobId,
                    'postId' => $jobData['postId'] ?? null,
                    'failed_at' => $jobInfo['failed_at'] ?? null,
                    'error' => $jobInfo['error'] ?? 'Unknown error',
                    'attempts' => (int)($jobInfo['attempts'] ?? 0)
                ];
            }
            
            usort($jobs, function($a, $b) {
                $aTime = $a['failed_at'] ? strtotime($a['failed_at']) : 0;
                $bTime = $b['failed_at'] ? strtotime($b['failed_at']) : 0;
                return $bTime - $aTime;
            });
            
            return array_slice($jobs, 0, $limit);
            
        } catch (Exception $e) {
            $this->lastError = "Get recent failed jobs error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return [];
        }
    }
    
    /**
     * Move permanently failed job to dead letter queue
     * 
     * @param string $jobId Job ID
     * @param string $error Final error message
     * @return bool Success status
     */
    public function moveToDeadLetterQueue($jobId, $error = '') {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot move to dead letter queue: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return false;
        }
        
        try {
            $jobInfo = $this->redisHgetall("job:{$jobId}");
            
            if (empty($jobInfo)) {
                $this->lastError = "Job not found: {$jobId}";
                $this->log($this->lastError, ['jobId' => $jobId], 'ERROR');
                return false;
            }
            
            $jobData = isset($jobInfo['data']) ? json_decode($jobInfo['data'], true) : [];
            
            $deadLetterEntry = [
                'jobId' => $jobId,
                'postId' => $jobData['postId'] ?? null,
                'original_data' => $jobData,
                'final_error' => $error,
                'attempts' => (int)($jobInfo['attempts'] ?? 0),
                'created_at' => $jobInfo['created_at'] ?? null,
                'failed_at' => $jobInfo['failed_at'] ?? null,
                'moved_to_dlq_at' => date('Y-m-d H:i:s'),
                'previous_status' => $jobInfo['status'] ?? null
            ];
            
            $this->redisLpush('dead_letter_queue', json_encode($deadLetterEntry));
            
            $this->redisHmset("job:{$jobId}", [
                'status' => self::STATUS_DEAD_LETTER,
                'updated_at' => date('Y-m-d H:i:s'),
                'moved_to_dlq_at' => date('Y-m-d H:i:s'),
                'final_error' => $error
            ]);
            
            // CRITICAL: Remove from processing queue (fixes stuck processing jobs bug)
            $this->removeFromProcessing($jobId);
            
            // Release job lock to allow future re-submission
            $this->releaseJobLock($jobId);
            
            $this->redisSrem('failed_jobs', $jobId);
            
            $this->redisSadd('dead_letter_jobs', $jobId);
            
            $this->log("Job moved to dead letter queue: {$jobId}", [
                'jobId' => $jobId,
                'postId' => $jobData['postId'] ?? null,
                'attempts' => $jobInfo['attempts'] ?? 0,
                'final_error' => $error
            ], 'WARNING');
            
            return true;
            
        } catch (Exception $e) {
            $this->lastError = "Move to dead letter queue error: " . $e->getMessage();
            $this->log($this->lastError, ['jobId' => $jobId], 'ERROR');
            return false;
        }
    }
    
    /**
     * Get jobs from dead letter queue
     * 
     * @param int $limit Maximum number of jobs to return (default 50)
     * @return array Array of dead letter jobs
     */
    public function getDeadLetterJobs($limit = 50) {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot get dead letter jobs: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return [];
        }
        
        try {
            $length = $this->redisLlen('dead_letter_queue');
            
            if ($length === 0) {
                return [];
            }
            
            $actualLimit = min($limit, $length);
            $jobs = [];
            
            for ($i = 0; $i < $actualLimit; $i++) {
                $item = $this->redisLindex('dead_letter_queue', $i);
                
                if ($item) {
                    $jobData = json_decode($item, true);
                    if ($jobData) {
                        $jobs[] = $jobData;
                    }
                }
            }
            
            return $jobs;
            
        } catch (Exception $e) {
            $this->lastError = "Get dead letter jobs error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return [];
        }
    }
    
    /**
     * Clear all jobs from the dead letter queue
     * 
     * @return array Result with cleared count and job IDs
     */
    public function clearDeadLetterQueue() {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot clear dead letter queue: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'cleared' => 0];
        }
        
        try {
            // Get all jobs in DLQ before clearing
            $dlqJobs = $this->getDeadLetterJobs(1000);
            $count = count($dlqJobs);
            $clearedJobIds = [];
            
            foreach ($dlqJobs as $job) {
                $jobId = $job['jobId'] ?? null;
                if ($jobId) {
                    $clearedJobIds[] = $jobId;
                    // Delete the job hash data
                    $this->redis->del("job:{$jobId}");
                    // Remove from dead_letter_jobs set
                    $this->redisSrem('dead_letter_jobs', $jobId);
                    // Release any stale locks
                    $this->releaseJobLock($jobId);
                }
            }
            
            // Clear the dead letter queue list
            $this->redis->del('dead_letter_queue');
            
            $this->log("Dead letter queue cleared", [
                'cleared_count' => $count,
                'job_ids' => $clearedJobIds
            ], 'WARNING');
            
            return [
                'success' => true,
                'cleared' => $count,
                'job_ids' => $clearedJobIds
            ];
            
        } catch (Exception $e) {
            $this->lastError = "Clear dead letter queue error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'cleared' => 0];
        }
    }
    
    /**
     * Calculate average processing time from completed jobs
     * 
     * @return float|null Average processing time in seconds or null if no data
     */
    public function getAverageProcessingTime() {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot calculate average processing time: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return null;
        }
        
        try {
            $completedJobIds = $this->redisSmembers('completed_jobs');
            
            if (empty($completedJobIds)) {
                return null;
            }
            
            $totalTime = 0;
            $count = 0;
            
            foreach ($completedJobIds as $jobId) {
                $jobInfo = $this->redisHgetall("job:{$jobId}");
                
                if (empty($jobInfo)) {
                    continue;
                }
                
                $createdAt = $jobInfo['created_at'] ?? null;
                $completedAt = $jobInfo['completed_at'] ?? null;
                
                if ($createdAt && $completedAt) {
                    $startTs = strtotime($createdAt);
                    $endTs = strtotime($completedAt);
                    
                    if ($startTs && $endTs && $endTs > $startTs) {
                        $totalTime += ($endTs - $startTs);
                        $count++;
                    }
                }
            }
            
            if ($count === 0) {
                return null;
            }
            
            return round($totalTime / $count, 2);
            
        } catch (Exception $e) {
            $this->lastError = "Calculate average processing time error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return null;
        }
    }
    
    /**
     * Get the number of retry attempts for a job
     * 
     * @param string $jobId Job ID
     * @return int Number of attempts (0 if job not found or error)
     */
    public function getJobAttempts($jobId) {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot get job attempts: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return 0;
        }
        
        try {
            $attempts = $this->redis->hGet("job:{$jobId}", 'attempts');
            
            if ($attempts === false) {
                return 0;
            }
            
            return (int)$attempts;
            
        } catch (Exception $e) {
            $this->lastError = "Get job attempts error: " . $e->getMessage();
            $this->log($this->lastError, ['jobId' => $jobId], 'ERROR');
            return 0;
        }
    }
    
    /**
     * Recover stalled jobs from processing queue back to pending queue
     * Use this when jobs are stuck due to worker crashes or deployment issues
     * Releases job locks to allow re-processing
     * 
     * @param int $stalledThresholdMinutes Jobs older than this are considered stalled (default 30 min)
     * @return array Result with recovered job count and job IDs
     */
    public function recoverStalledJobs($stalledThresholdMinutes = 30) {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot recover stalled jobs: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'recovered' => 0];
        }
        
        try {
            $processingItems = $this->redisLrange($this->processingQueue, 0, -1);
            
            if (empty($processingItems)) {
                $this->log("No jobs in processing queue to recover");
                return ['success' => true, 'recovered' => 0, 'job_ids' => []];
            }
            
            $this->log("Found " . count($processingItems) . " jobs in processing queue, checking for stalled jobs...");
            
            $stalledThreshold = time() - ($stalledThresholdMinutes * 60);
            $recoveredJobs = [];
            
            foreach ($processingItems as $item) {
                if (!$item) {
                    continue;
                }
                
                $jobData = json_decode($item, true);
                
                if (!$jobData) {
                    continue;
                }
                
                $jobId = $jobData['jobId'] ?? null;
                $isStalled = false;
                
                if ($jobId) {
                    $jobInfo = $this->redisHgetall("job:{$jobId}");
                    
                    if (!empty($jobInfo['updated_at'])) {
                        $updatedAt = strtotime($jobInfo['updated_at']);
                        if ($updatedAt && $updatedAt < $stalledThreshold) {
                            $isStalled = true;
                        }
                    } else {
                        $isStalled = true;
                    }
                } else {
                    $isStalled = true;
                }
                
                if ($isStalled) {
                    $removed = $this->redisLrem($this->processingQueue, 1, $item);
                    
                    if ($removed > 0) {
                        $this->redisLpush($this->queueName, $item);
                        
                        // Release job lock so job can be re-processed
                        if ($jobId) {
                            $this->releaseJobLock($jobId);
                            $this->redisHmset("job:{$jobId}", [
                                'status' => self::STATUS_PENDING,
                                'updated_at' => date('Y-m-d H:i:s'),
                                'recovered_at' => date('Y-m-d H:i:s'),
                                'recovery_reason' => 'stalled_job_recovery'
                            ]);
                        }
                        
                        $recoveredJobs[] = $jobId ?? 'unknown';
                        
                        $this->log("Recovered stalled job: {$jobId}", [
                            'jobId' => $jobId,
                            'postId' => $jobData['postId'] ?? null
                        ]);
                    }
                }
            }
            
            $recoveredCount = count($recoveredJobs);
            $this->log("Stalled job recovery complete", [
                'recovered_count' => $recoveredCount,
                'job_ids' => $recoveredJobs
            ]);
            
            return [
                'success' => true,
                'recovered' => $recoveredCount,
                'job_ids' => $recoveredJobs
            ];
            
        } catch (Exception $e) {
            $this->lastError = "Recover stalled jobs error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'recovered' => 0];
        }
    }
    
    /**
     * Force recover all jobs from processing queue (use with caution)
     * This moves ALL jobs from processing back to pending regardless of age
     * Also handles duplicate entries by only recovering each unique job once
     * Releases job locks so recovered jobs can be re-enqueued if needed
     * 
     * @return array Result with recovered job count, removed duplicates count, and job IDs
     */
    public function forceRecoverAllProcessingJobs() {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot recover jobs: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'recovered' => 0, 'duplicates_removed' => 0];
        }
        
        try {
            $processingItems = $this->redisLrange($this->processingQueue, 0, -1);
            
            if (empty($processingItems)) {
                $this->log("No jobs in processing queue to recover");
                return ['success' => true, 'recovered' => 0, 'duplicates_removed' => 0, 'job_ids' => []];
            }
            
            $this->log("Force recovery: Found " . count($processingItems) . " entries in processing queue");
            
            // Track unique job IDs to handle duplicates
            $seenJobIds = [];
            $recoveredJobs = [];
            $duplicatesRemoved = 0;
            
            foreach ($processingItems as $item) {
                if (!$item) {
                    continue;
                }
                
                $jobData = json_decode($item, true);
                if (!$jobData) {
                    continue;
                }
                
                $jobId = $jobData['jobId'] ?? null;
                
                // Check job's current status before recovering
                $currentStatus = null;
                if ($jobId) {
                    $jobInfo = $this->redisHgetall("job:{$jobId}");
                    $currentStatus = $jobInfo['status'] ?? null;
                }
                
                // Remove this entry from processing queue
                $removed = $this->redisLrem($this->processingQueue, 1, $item);
                
                if ($removed > 0) {
                    // Check if this is a duplicate (already seen this jobId)
                    if ($jobId && in_array($jobId, $seenJobIds)) {
                        $duplicatesRemoved++;
                        // Release lock for duplicate
                        $this->releaseJobLock($jobId);
                        $this->log("Removed duplicate entry for job: {$jobId}", [
                            'jobId' => $jobId
                        ]);
                    } 
                    // Skip jobs that are already in dead_letter status - don't re-queue them
                    elseif ($currentStatus === self::STATUS_DEAD_LETTER) {
                        $this->releaseJobLock($jobId);
                        $this->log("Skipped dead letter job (not recovering): {$jobId}", [
                            'jobId' => $jobId,
                            'status' => $currentStatus
                        ]);
                    }
                    // Skip jobs that are already completed
                    elseif ($currentStatus === self::STATUS_COMPLETED) {
                        $this->releaseJobLock($jobId);
                        $this->log("Skipped completed job (not recovering): {$jobId}", [
                            'jobId' => $jobId,
                            'status' => $currentStatus
                        ]);
                    } else {
                        // This is a unique job, move to pending queue
                        $this->redisLpush($this->queueName, $item);
                        
                        if ($jobId) {
                            $seenJobIds[] = $jobId;
                            // Release the old lock so job can be re-processed
                            $this->releaseJobLock($jobId);
                            
                            $this->redisHmset("job:{$jobId}", [
                                'status' => self::STATUS_PENDING,
                                'updated_at' => date('Y-m-d H:i:s'),
                                'recovered_at' => date('Y-m-d H:i:s'),
                                'recovery_reason' => 'force_recovery'
                            ]);
                        }
                        
                        $recoveredJobs[] = $jobId ?? 'unknown';
                        
                        $this->log("Recovered job: {$jobId}", [
                            'jobId' => $jobId,
                            'postId' => $jobData['postId'] ?? null
                        ]);
                    }
                }
            }
            
            $recoveredCount = count($recoveredJobs);
            $this->log("Force recovery complete", [
                'recovered_count' => $recoveredCount,
                'duplicates_removed' => $duplicatesRemoved,
                'job_ids' => $recoveredJobs
            ]);
            
            return [
                'success' => true,
                'recovered' => $recoveredCount,
                'duplicates_removed' => $duplicatesRemoved,
                'job_ids' => $recoveredJobs
            ];
            
        } catch (Exception $e) {
            $this->lastError = "Force recovery error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'recovered' => 0, 'duplicates_removed' => 0];
        }
    }
    
    /**
     * Clean up orphaned job locks
     * Scans for job_lock:* keys whose job hashes are missing or in non-active statuses
     * and releases them. Use this after crashes or when locks are suspected orphaned.
     * 
     * @return array Result with cleaned lock count and job IDs
     */
    public function cleanupOrphanedLocks() {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot cleanup locks: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'cleaned' => 0];
        }
        
        try {
            // Get all job lock keys
            $lockKeys = $this->redis->keys('job_lock:*');
            
            if (empty($lockKeys)) {
                $this->log("No job locks found to audit");
                return ['success' => true, 'cleaned' => 0, 'job_ids' => []];
            }
            
            $this->log("Lock audit: Found " . count($lockKeys) . " job locks");
            
            $cleanedLocks = [];
            
            foreach ($lockKeys as $lockKey) {
                // Extract jobId from lock key
                $jobId = str_replace('job_lock:', '', $lockKey);
                
                // Check job status hash
                $jobInfo = $this->redisHgetall("job:{$jobId}");
                
                $shouldClean = false;
                $reason = '';
                
                if (empty($jobInfo)) {
                    // Job hash doesn't exist - orphaned lock
                    $shouldClean = true;
                    $reason = 'job_hash_missing';
                } else {
                    $status = $jobInfo['status'] ?? '';
                    // Lock should be released for completed, failed, or dead letter jobs
                    if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_DEAD_LETTER])) {
                        $shouldClean = true;
                        $reason = "job_status_{$status}";
                    }
                }
                
                if ($shouldClean) {
                    $this->redis->del($lockKey);
                    $cleanedLocks[] = $jobId;
                    $this->log("Cleaned orphaned lock for job: {$jobId}", [
                        'jobId' => $jobId,
                        'reason' => $reason
                    ]);
                }
            }
            
            $cleanedCount = count($cleanedLocks);
            $this->log("Lock cleanup complete", [
                'total_locks' => count($lockKeys),
                'cleaned_count' => $cleanedCount,
                'job_ids' => $cleanedLocks
            ]);
            
            return [
                'success' => true,
                'cleaned' => $cleanedCount,
                'job_ids' => $cleanedLocks
            ];
            
        } catch (Exception $e) {
            $this->lastError = "Lock cleanup error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError, 'cleaned' => 0];
        }
    }
    
    /**
     * Clear all jobs from queues and reset (use with extreme caution)
     * This clears all pending and processing jobs - only use for maintenance
     * Also clears all job locks to ensure clean state
     * 
     * @return array Result with cleared counts
     */
    public function clearAllQueues() {
        if (!$this->isConnected()) {
            $this->lastError = "Cannot clear queues: Not connected to Redis";
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError];
        }
        
        try {
            $pendingCount = $this->redisLlen($this->queueName);
            $processingCount = $this->redisLlen($this->processingQueue);
            
            // Clear all job locks
            $lockKeys = $this->redis->keys('job_lock:*');
            $locksCleared = count($lockKeys);
            foreach ($lockKeys as $lockKey) {
                $this->redis->del($lockKey);
            }
            
            $this->redis->del($this->queueName);
            $this->redis->del($this->processingQueue);
            
            $this->log("Cleared all queues and locks", [
                'pending_cleared' => $pendingCount,
                'processing_cleared' => $processingCount,
                'locks_cleared' => $locksCleared
            ]);
            
            return [
                'success' => true,
                'pending_cleared' => $pendingCount,
                'processing_cleared' => $processingCount,
                'locks_cleared' => $locksCleared
            ];
            
        } catch (Exception $e) {
            $this->lastError = "Clear queues error: " . $e->getMessage();
            $this->log($this->lastError, [], 'ERROR');
            return ['success' => false, 'error' => $this->lastError];
        }
    }
    
    /**
     * Close Redis connection
     */
    public function close() {
        if ($this->connected && $this->redis) {
            try {
                $this->redis->close();
                $this->connected = false;
            } catch (Exception $e) {
                // Ignore close errors
            }
        }
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}
