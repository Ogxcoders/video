<?php
/**
 * RedisQueue - Robust job queue management with Redis
 * Handles job queuing, retry logic, dead letter queue, and statistics
 */
class RedisQueue {
    private $redis;
    private $queueName;
    private $processingQueue;
    private $deadLetterQueue;
    private $maxRetries;
    
    public function __construct($config = []) {
        $this->redis = new Redis();
        $this->redis->connect(
            $config['redis_host'] ?? getenv('REDIS_HOST') ?? 'redis',
            $config['redis_port'] ?? getenv('REDIS_PORT') ?? 6379
        );
        
        $this->queueName = $config['queue_name'] ?? 'video_compression_queue';
        $this->processingQueue = $this->queueName . ':processing';
        $this->deadLetterQueue = $this->queueName . ':dead_letter';
        $this->maxRetries = $config['max_retries'] ?? 3;
    }
    
    /**
     * Add single job to queue
     * 
     * @param array $jobData - Job data (post_id, video_url, thumbnail_url, etc.)
     * @return string - Job ID
     */
    public function addJob($jobData) {
        $jobId = 'job_' . uniqid() . '_' . time();
        
        $job = [
            'id' => $jobId,
            'data' => $jobData,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => time(),
            'updated_at' => time(),
            'error' => null
        ];
        
        // Store job data in hash
        $this->redis->hSet("job:{$jobId}", 'data', json_encode($job));
        
        // Add to queue
        $this->redis->rPush($this->queueName, $jobId);
        
        // Increment stats
        $this->redis->incr('stats:total_jobs');
        $this->redis->incr('stats:pending');
        
        $this->log("Job added: {$jobId}", $job['data']);
        
        return $jobId;
    }
    
    /**
     * Add bulk jobs to queue (for 10k+ videos)
     * 
     * @param array $jobsArray - Array of job data
     * @return array - ['batch_id' => string, 'job_ids' => array, 'total' => int]
     */
    public function addBulkJobs($jobsArray) {
        $batchId = 'batch_' . uniqid() . '_' . time();
        $jobIds = [];
        
        // Use Redis pipeline for performance
        $pipe = $this->redis->multi(Redis::PIPELINE);
        
        foreach ($jobsArray as $jobData) {
            $jobId = 'job_' . uniqid() . '_' . time() . '_' . mt_rand(1000, 9999);
            
            $job = [
                'id' => $jobId,
                'data' => array_merge($jobData, ['batch_id' => $batchId]),
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => time(),
                'updated_at' => time(),
                'error' => null
            ];
            
            // Store job data
            $pipe->hSet("job:{$jobId}", 'data', json_encode($job));
            
            // Add to queue
            $pipe->rPush($this->queueName, $jobId);
            
            $jobIds[] = $jobId;
            
            // Small delay to ensure unique timestamps
            usleep(100);
        }
        
        // Update stats
        $totalJobs = count($jobsArray);
        $pipe->incrBy('stats:total_jobs', $totalJobs);
        $pipe->incrBy('stats:pending', $totalJobs);
        
        // Store batch info
        $pipe->hSet("batch:{$batchId}", 'total', $totalJobs);
        $pipe->hSet("batch:{$batchId}", 'created_at', time());
        
        $pipe->exec();
        
        $this->log("Bulk jobs added: {$totalJobs} jobs in batch {$batchId}");
        
        return [
            'batch_id' => $batchId,
            'job_ids' => $jobIds,
            'total' => $totalJobs
        ];
    }
    
    /**
     * Get next job from queue (blocking)
     * 
     * @param int $timeout - Blocking timeout in seconds (0 = non-blocking)
     * @return array|null - Job data or null if queue empty
     */
    public function getNextJob($timeout = 5) {
        // Blocking pop from queue
        $result = $this->redis->blPop([$this->queueName], $timeout);
        
        if (!$result) {
            return null; // Queue empty or timeout
        }
        
        $jobId = $result[1];
        
        // Get job data
        $jobJson = $this->redis->hGet("job:{$jobId}", 'data');
        
        if (!$jobJson) {
            $this->log("Job data not found: {$jobId}", [], 'error');
            return null;
        }
        
        $job = json_decode($jobJson, true);
        $job['status'] = 'processing';
        $job['attempts']++;
        $job['updated_at'] = time();
        $job['worker_id'] = getenv('WORKER_ID') ?? gethostname();
        
        // Update job status
        $this->redis->hSet("job:{$jobId}", 'data', json_encode($job));
        
        // Add to processing queue
        $this->redis->rPush($this->processingQueue, $jobId);
        
        // Update stats
        $this->redis->decr('stats:pending');
        $this->redis->incr('stats:processing');
        
        $this->log("Job started: {$jobId} (attempt {$job['attempts']})", $job['data']);
        
        return $job;
    }
    
    /**
     * Mark job as completed successfully
     * 
     * @param string $jobId - Job ID
     * @param array $result - Processing result
     * @return bool
     */
    public function completeJob($jobId, $result) {
        // Get job data
        $jobJson = $this->redis->hGet("job:{$jobId}", 'data');
        
        if (!$jobJson) {
            return false;
        }
        
        $job = json_decode($jobJson, true);
        $job['status'] = 'completed';
        $job['updated_at'] = time();
        $job['completed_at'] = time();
        $job['result'] = $result;
        $job['duration'] = time() - $job['created_at'];
        
        // Update job
        $this->redis->hSet("job:{$jobId}", 'data', json_encode($job));
        
        // Remove from processing queue
        $this->redis->lRem($this->processingQueue, $jobId, 1);
        
        // Update stats
        $this->redis->decr('stats:processing');
        $this->redis->incr('stats:completed');
        
        // Update batch stats if part of batch
        if (isset($job['data']['batch_id'])) {
            $this->redis->hIncrBy("batch:{$job['data']['batch_id']}", 'completed', 1);
        }
        
        $this->log("Job completed: {$jobId}", ['duration' => $job['duration'] . 's']);
        
        // Schedule cleanup (delete job data after 24 hours)
        $this->redis->expire("job:{$jobId}", 86400);
        
        return true;
    }
    
    /**
     * Mark job as failed with retry logic
     * 
     * @param string $jobId - Job ID
     * @param string $error - Error message
     * @return bool - True if retrying, false if max retries reached
     */
    public function failJob($jobId, $error) {
        // Get job data
        $jobJson = $this->redis->hGet("job:{$jobId}", 'data');
        
        if (!$jobJson) {
            return false;
        }
        
        $job = json_decode($jobJson, true);
        $job['error'] = $error;
        $job['updated_at'] = time();
        
        // Check if should retry
        if ($job['attempts'] < $this->maxRetries) {
            $job['status'] = 'retry';
            
            // Update job
            $this->redis->hSet("job:{$jobId}", 'data', json_encode($job));
            
            // Remove from processing queue
            $this->redis->lRem($this->processingQueue, $jobId, 1);
            
            // Re-add to main queue (with exponential backoff delay)
            $delay = pow(2, $job['attempts']) * 60; // 2min, 4min, 8min
            $this->redis->zAdd($this->queueName . ':delayed', time() + $delay, $jobId);
            
            // Update stats
            $this->redis->decr('stats:processing');
            $this->redis->incr('stats:pending');
            
            $this->log("Job failed, will retry: {$jobId} (attempt {$job['attempts']}/{$this->maxRetries})", [
                'error' => $error,
                'retry_after' => $delay . 's'
            ], 'warning');
            
            return true; // Will retry
            
        } else {
            // Max retries reached - move to dead letter queue
            $job['status'] = 'failed';
            $job['failed_at'] = time();
            
            // Update job
            $this->redis->hSet("job:{$jobId}", 'data', json_encode($job));
            
            // Remove from processing queue
            $this->redis->lRem($this->processingQueue, $jobId, 1);
            
            // Add to dead letter queue
            $this->redis->rPush($this->deadLetterQueue, $jobId);
            
            // Update stats
            $this->redis->decr('stats:processing');
            $this->redis->incr('stats:failed');
            
            // Update batch stats if part of batch
            if (isset($job['data']['batch_id'])) {
                $this->redis->hIncrBy("batch:{$job['data']['batch_id']}", 'failed', 1);
            }
            
            $this->log("Job permanently failed: {$jobId}", [
                'error' => $error,
                'attempts' => $job['attempts']
            ], 'error');
            
            return false; // No more retries
        }
    }
    
    /**
     * Get queue statistics
     * 
     * @return array - Queue stats
     */
    public function getStats() {
        return [
            'pending' => (int)$this->redis->get('stats:pending') ?: 0,
            'processing' => (int)$this->redis->get('stats:processing') ?: 0,
            'completed' => (int)$this->redis->get('stats:completed') ?: 0,
            'failed' => (int)$this->redis->get('stats:failed') ?: 0,
            'total' => (int)$this->redis->get('stats:total_jobs') ?: 0,
            'queue_length' => $this->redis->lLen($this->queueName),
            'processing_count' => $this->redis->lLen($this->processingQueue),
            'dead_letter_count' => $this->redis->lLen($this->deadLetterQueue)
        ];
    }
    
    /**
     * Get batch statistics
     * 
     * @param string $batchId - Batch ID
     * @return array|null - Batch stats or null if not found
     */
    public function getBatchStats($batchId) {
        if (!$this->redis->exists("batch:{$batchId}")) {
            return null;
        }
        
        return [
            'batch_id' => $batchId,
            'total' => (int)$this->redis->hGet("batch:{$batchId}", 'total') ?: 0,
            'completed' => (int)$this->redis->hGet("batch:{$batchId}", 'completed') ?: 0,
            'failed' => (int)$this->redis->hGet("batch:{$batchId}", 'failed') ?: 0,
            'created_at' => (int)$this->redis->hGet("batch:{$batchId}", 'created_at')
        ];
    }
    
    /**
     * Process delayed jobs (move from delayed queue to main queue)
     * Should be called periodically by worker or cron
     * 
     * @return int - Number of jobs moved
     */
    public function processDelayedJobs() {
        $now = time();
        $jobIds = $this->redis->zRangeByScore($this->queueName . ':delayed', 0, $now);
        
        if (empty($jobIds)) {
            return 0;
        }
        
        foreach ($jobIds as $jobId) {
            // Move to main queue
            $this->redis->rPush($this->queueName, $jobId);
            
            // Remove from delayed queue
            $this->redis->zRem($this->queueName . ':delayed', $jobId);
        }
        
        $count = count($jobIds);
        $this->log("Moved {$count} delayed jobs to main queue");
        
        return $count;
    }
    
    /**
     * Clear all queues (DANGEROUS - use with caution!)
     * 
     * @return bool
     */
    public function clearAllQueues() {
        $this->redis->del($this->queueName);
        $this->redis->del($this->processingQueue);
        $this->redis->del($this->deadLetterQueue);
        $this->redis->del($this->queueName . ':delayed');
        
        // Reset stats
        $this->redis->del('stats:pending');
        $this->redis->del('stats:processing');
        $this->redis->del('stats:completed');
        $this->redis->del('stats:failed');
        
        $this->log("All queues cleared", [], 'warning');
        
        return true;
    }
    
    /**
     * Log message
     * 
     * @param string $message - Log message
     * @param array $context - Additional context
     * @param string $level - Log level (info|warning|error)
     */
    private function log($message, $context = [], $level = 'info') {
        $logFile = '/var/www/html/logs/redis_queue.log';
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        file_put_contents(
            $logFile,
            json_encode($entry) . "\n",
            FILE_APPEND
        );
    }
}
?>
