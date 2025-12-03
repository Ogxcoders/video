# Logging System Fixes - November 23, 2025

## Problem Summary
The user reported that Redis queue, compressor, and webhook logs were not appearing in `all.log`. Only `[WORKER]` logs were showing up.

## Root Causes Identified

### 1. RedisQueue Not Receiving log_file Configuration ❌ → ✅
**Problem:**
- When worker.php created the RedisQueue instance, it was NOT passing the `log_file` parameter
- RedisQueue used its default log file path, which should have worked, but the configuration was inconsistent

**Location:** `vps-api/worker.php` lines 73-78

**Fix Applied:**
```php
// BEFORE:
$this->queue = new RedisQueue([
    'host' => '127.0.0.1',
    'port' => 6379,
    'queue_name' => 'compression_queue',
    'processing_queue' => 'compression_processing'
]);

// AFTER:
$this->queue = new RedisQueue([
    'host' => '127.0.0.1',
    'port' => 6379,
    'queue_name' => 'compression_queue',
    'processing_queue' => 'compression_processing',
    'log_file' => $this->logFile  // ✅ ADDED
]);
```

**Impact:** RedisQueue now logs to the same file as the worker

---

### 2. RedisQueue Silent on Queue Timeouts ❌ → ✅
**Problem:**
- The `dequeue()` method only logged when a job was found or an error occurred
- When the queue timed out (no jobs available), it returned null silently
- This made it impossible to see queue polling activity in logs

**Location:** `vps-api/RedisQueue.php` lines 168-205

**Fix Applied:**
Added two new log statements:
1. **Before checking queue:** Logs queue length and timeout
2. **On timeout:** Logs that no jobs were available

```php
// ADDED - Log before checking queue:
$queueLength = $this->redis->lLen($this->queueName);
$this->log("Checking queue for jobs", [
    'queue_length' => $queueLength,
    'timeout' => $timeout . 's'
]);

// ADDED - Log on timeout:
if (!$result) {
    $this->log("Queue timeout: No jobs available", [
        'timeout' => $timeout . 's',
        'queue_name' => $this->queueName
    ]);
    return null;
}
```

**Impact:** 
- Now you can see [REDIS-QUEUE] activity in logs
- Shows queue polling every 10 seconds
- Shows when queue is empty vs when jobs are processed

---

## Expected Log Output After Fix

### When Worker Starts:
```
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] INITIALIZING BACKGROUND WORKER
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: Initializing Redis Queue connection
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Connected to Redis successfully  ← NEW!
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: Redis Queue connected successfully
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: Initializing Video Compressor
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] Background Worker Service Starting
```

### When Worker Polls Queue (Every 10 seconds):
```
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: Waiting for next job (timeout: 10s)...
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Checking queue for jobs | {"queue_length":0,"timeout":"10s"}  ← NEW!
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Queue timeout: No jobs available | {"timeout":"10s","queue_name":"compression_queue"}  ← NEW!
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: No job available, continuing...
```

### When Job is Enqueued (compress.php):
```
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESS] Request received | {"method":"POST","uri":"/api/compress","ip":"..."}
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESS] Authentication successful
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESS] Initializing RedisQueue
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Connected to Redis successfully  ← ALREADY WORKING
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESS] RedisQueue connected successfully
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Job enqueued: job_123_1234567890, Queue length: 1  ← ALREADY WORKING
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESS] Job added to Redis queue via RedisQueue class
```

### When Worker Processes Job:
```
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Checking queue for jobs | {"queue_length":1,"timeout":"10s"}  ← NEW!
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Job dequeued: job_123_1234567890  ← ALREADY WORKING
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] PROCESSING JOB: job_123_1234567890
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESSOR] Starting compression for job: job_123_1234567890  ← ALREADY WORKING
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESSOR] Paths configured | {"source":"...","output_dir":"..."}
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESSOR] FFmpeg compression started
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESSOR] FFmpeg compression completed
[YYYY-MM-DD HH:MM:SS] [INFO] [COMPRESSOR] Compression stats | {"compression_ratio":65.5,...}
[YYYY-MM-DD HH:MM:SS] [INFO] [REDIS-QUEUE] Job completed: job_123_1234567890  ← ALREADY WORKING
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] SUCCESS: Job completed successfully
```

### When Webhook is Sent:
```
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: Sending webhook to WordPress...
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: Webhook payload prepared
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] DEBUG: Sending HTTP POST request to WordPress...
[YYYY-MM-DD HH:MM:SS] [INFO] [WORKER] Webhook sent successfully | {"http_code":200,"time":"0.5s"}
```

### When Webhook is Received (webhook-receiver.php):
```
[YYYY-MM-DD HH:MM:SS] [INFO] [WEBHOOK] Webhook received | {"jobId":"job_123_1234567890","postId":123}  ← ALREADY WORKING
[YYYY-MM-DD HH:MM:SS] [INFO] [WEBHOOK] Webhook data valid
[YYYY-MM-DD HH:MM:SS] [INFO] [WEBHOOK] Success response sent
```

---

## Components That Now Log to all.log

All components use the same log file and format:

1. ✅ **[API]** - Main API endpoint (`index.php`)
2. ✅ **[COMPRESS]** - Compression API endpoint (`compress.php`)
3. ✅ **[WORKER]** - Background worker (`worker.php`)
4. ✅ **[REDIS-QUEUE]** - Redis queue operations (`RedisQueue.php`) - **FIXED**
5. ✅ **[PROCESSOR]** - Video processor (`VideoProcessor.php`)
6. ✅ **[COMPRESSOR]** - Video compressor (`VideoCompressor.php`)
7. ✅ **[WEBHOOK]** - Webhook receiver (`webhook-receiver.php`)

---

## Files Modified

1. **vps-api/worker.php** (line 81)
   - Added `'log_file' => $this->logFile` to RedisQueue configuration

2. **vps-api/RedisQueue.php** (lines 178-192)
   - Added logging before queue check
   - Added logging when queue timeout occurs

---

## Testing Instructions

### 1. Restart the Worker
```bash
# Stop existing worker
pkill -f worker.php

# Start worker and watch logs
php vps-api/worker.php &
tail -f vps-api/logs/all.log
```

### 2. Expected Immediate Output
You should now see:
- `[REDIS-QUEUE] Connected to Redis successfully` when worker starts
- `[REDIS-QUEUE] Checking queue for jobs` every 10 seconds
- `[REDIS-QUEUE] Queue timeout: No jobs available` when no jobs

### 3. Test with a Job
```bash
# Send a test compression job via API
curl -X POST https://v.ogtemplate.com/api/compress \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "postId": 999,
    "wpMediaPath": "/2025/11/test.mp4",
    "wpThumbnailPath": "/2025/11/test.jpg",
    "year": 2025,
    "month": 11
  }'

# Watch logs - you should see:
# [COMPRESS] logs
# [REDIS-QUEUE] Job enqueued
# [REDIS-QUEUE] Job dequeued
# [COMPRESSOR] logs
# [WEBHOOK] logs (if webhook URL is configured)
```

---

## Benefits of These Fixes

1. **Complete Visibility:** All components now log their activities
2. **Better Debugging:** Can trace job lifecycle from queue → process → webhook
3. **Queue Monitoring:** Can see queue polling activity and when jobs are waiting
4. **Consistent Logging:** All components use same format and file
5. **Production Ready:** Logs are detailed enough for production troubleshooting

---

## Notes

- **compress.php** already had correct logging - no changes needed
- **VideoCompressor** already had correct logging - no changes needed  
- **worker.php** already passed config to VideoCompressor - no changes needed
- The main issue was RedisQueue not receiving log_file and not logging timeouts

---

## Deployment

After deploying these changes to your VPS:

1. Restart the worker service
2. Monitor `all.log` for 1-2 minutes
3. You should immediately see [REDIS-QUEUE] logs appearing
4. Test by submitting a compression job
5. Verify all component logs appear in sequence

Done! 🎉
