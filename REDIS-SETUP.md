# Redis Queue System - Complete Setup Guide

## ✅ Task 3: Redis Queue Setup - COMPLETE

This document covers the complete Redis queue implementation for the video compression system.

---

## 📁 Files Created

### Core Files:
- **`redis.conf`** - Redis server configuration (optimized for Docker)
- **`RedisQueue.php`** - PHP queue management class
- **`docker-entrypoint.sh`** - Container startup script with Redis health checks
- **`redis-health.php`** - Health check endpoint for monitoring

### Testing & Utilities:
- **`test-redis-queue.php`** - Comprehensive test script
- **`startup.sh`** - Manual Redis startup verification

### Integration:
- **`compress.php`** - Uses RedisQueue for job queuing
- **`Dockerfile`** - Updated with Redis installation and startup

---

## 🚀 Features Implemented

### ✅ 1. Install and Configure Redis Server
- **Status:** COMPLETE
- Redis 8.0.2 installed via Docker
- PHP Redis extension (6.3.0) installed via PECL
- Optimized configuration for job queue workload
- Logs to stdout/stderr (Docker best practice)

### ✅ 2. Create Queue Structure
- **Status:** COMPLETE
- FIFO queue using Redis Lists (`LPUSH` + `BRPOP`)
- Main queue: `compression_queue`
- Processing queue: `compression_processing`
- Job tracking via Redis Hashes

### ✅ 3. Implement Job Priority System (FIFO)
- **Status:** COMPLETE
- First In, First Out (FIFO) order
- `LPUSH` adds to queue head
- `BRPOP` removes from queue tail
- Guaranteed processing order

### ✅ 4. Add Job Status Tracking
- **Status:** COMPLETE
- Status flow: `pending → processing → completed/failed`
- Individual job tracking via Redis hashes
- Retry counter tracking
- Timestamp tracking (created, updated, completed/failed)
- Statistics sets for completed/failed jobs

### ✅ 5. Set Up Queue Persistence
- **Status:** COMPLETE
- **Hybrid persistence:** AOF + RDB
- **AOF:** Append-only file with `everysec` sync (max 1 second data loss)
- **RDB:** Snapshots every 15min/5min/1min based on activity
- **Crash recovery:** Automatic reload on restart
- **Data directory:** `/var/lib/redis`

---

## 📊 Queue Data Structures

### Redis Keys Used:

```
compression_queue           (List)   - Main FIFO job queue
compression_processing      (List)   - Currently processing jobs
job:{jobId}                 (Hash)   - Individual job details and status
completed_jobs              (Set)    - Completed job IDs (for stats)
failed_jobs                 (Set)    - Failed job IDs (for stats)
```

### Job Hash Structure:
```
job:job_12345_1700568600
├── data           (JSON)     - Complete job data
├── status         (String)   - pending|processing|completed|failed
├── created_at     (DateTime) - 2024-11-21 10:30:00
├── updated_at     (DateTime) - 2024-11-21 10:31:45
├── attempts       (Integer)  - Retry counter
├── completed_at   (DateTime) - Completion timestamp (if completed)
├── failed_at      (DateTime) - Failure timestamp (if failed)
├── error          (String)   - Error message (if failed)
└── result         (JSON)     - Result data (if completed)
```

---

## 🔧 RedisQueue Class API

### Methods:

```php
// Connection
isConnected()                      → bool
getLastError()                     → string|null

// Queue Operations (FIFO)
enqueue($jobData)                  → bool
dequeue($timeout = 10)             → array|null

// Status Management
markCompleted($jobId, $result)     → bool
markFailed($jobId, $error)         → bool
getJobStatus($jobId)               → array|null

// Statistics
getStats()                         → array
getQueueLength()                   → int

// Testing (DANGER)
clearAll()                         → bool
```

### Usage Example:

```php
<?php
require_once 'RedisQueue.php';

// Initialize
$queue = new RedisQueue([
    'host' => '127.0.0.1',
    'port' => 6379,
    'queue_name' => 'compression_queue'
]);

// Check connection
if (!$queue->isConnected()) {
    die("Redis not connected: " . $queue->getLastError());
}

// Add job (Producer)
$job = [
    'jobId' => 'job_12345_' . time(),
    'postId' => 12345,
    'wpMediaPath' => '/wp-content/uploads/2024/11/video.mp4',
    'wpThumbnailPath' => '/wp-content/uploads/2024/11/thumb.jpg',
    'year' => 2024,
    'month' => 11
];

if ($queue->enqueue($job)) {
    echo "Job queued successfully!\n";
}

// Process job (Worker)
$job = $queue->dequeue(10);  // Wait up to 10 seconds

if ($job) {
    echo "Processing job: {$job['jobId']}\n";
    
    // Do compression work...
    
    // Mark as completed
    $queue->markCompleted($job['jobId'], [
        'output_path' => '/path/to/output',
        'processing_time' => 45.5
    ]);
}

// Get statistics
$stats = $queue->getStats();
print_r($stats);
```

---

## 🩺 Health Checks & Monitoring

### 1. Redis Health Endpoint

**URL:** `https://v.ogtemplate.com/redis-health.php`

**Response (Success):**
```json
{
  "timestamp": "2024-11-21 16:30:00",
  "status": "healthy",
  "message": "Redis is running and queue system is operational",
  "redis": {
    "extension_loaded": true,
    "ping": "PONG",
    "version": "8.0.2",
    "uptime_seconds": 3600,
    "uptime_days": 0,
    "used_memory": "12.5M",
    "connected_clients": 2,
    "role": "master",
    "persistence": {
      "rdb_last_save_time": "2024-11-21 16:15:00",
      "rdb_changes_since_last_save": 5,
      "aof_enabled": "yes",
      "aof_rewrite_in_progress": "no"
    }
  },
  "queue": {
    "connected": true,
    "pending_jobs": 5,
    "processing_jobs": 2,
    "completed_jobs": 120,
    "failed_jobs": 3,
    "total_jobs": 130
  }
}
```

**Response (Error):**
```json
{
  "timestamp": "2024-11-21 16:30:00",
  "status": "error",
  "message": "Redis connection failed: Connection refused",
  "redis": {
    "extension_loaded": true,
    "connected": false,
    "error": "Connection refused"
  }
}
```

### 2. Manual Redis CLI Checks

```bash
# Inside container
docker exec -it <container-name> bash

# Test ping
redis-cli ping
# Expected: PONG

# Check server info
redis-cli info server

# Check queue lengths
redis-cli LLEN compression_queue       # Pending jobs
redis-cli LLEN compression_processing  # Processing jobs
redis-cli SCARD completed_jobs         # Completed count
redis-cli SCARD failed_jobs            # Failed count

# Check specific job
redis-cli HGETALL job:job_12345_1700568600

# Monitor real-time operations
redis-cli MONITOR
```

### 3. Log Files

```bash
# Application logs
/var/www/html/logs/redis-queue.log    # RedisQueue operations
/var/www/html/logs/compress.log       # Compression API logs

# Redis logs (via Docker)
docker logs <container-name> | grep -i redis
```

---

## 🧪 Testing

### ⚡ Quick Connection Test (Recommended First Step)

Test Redis connectivity and get diagnostic information:

```bash
# Inside Docker container
docker exec <container-name> php /var/www/html/test-redis-connection.php

# Or if inside container already
php /var/www/html/test-redis-connection.php
```

**What it tests:**
- ✅ PHP Redis extension loaded
- ✅ Redis server connection
- ✅ Redis ping/pong
- ✅ Basic Redis operations (SET, GET, DEL, LPUSH, RPOP)
- ✅ RedisQueue class functionality
- ✅ Server information and diagnostics

**Expected Output:**
```
========================================
 Redis Connection Test Script
========================================

Step 1: Testing Redis Extension
----------------------------------------
✓ Redis extension loaded
  Version: 6.3.0

Step 2: Testing Redis Connection
----------------------------------------
Attempting connection to 127.0.0.1:6379...
✓ Connected to Redis successfully
✓ Redis ping successful

Step 3: Redis Server Information
----------------------------------------
Redis Version: 8.0.2
Redis Mode: standalone
Uptime: 3600 seconds
Connected Clients: 2
Used Memory: 12.5M

Step 4: Testing Basic Operations
----------------------------------------
✓ SET operation successful
✓ GET operation successful
✓ DEL operation successful
✓ LPUSH operation successful
✓ RPOP operation successful

Step 5: Testing RedisQueue Class
----------------------------------------
✓ RedisQueue connected successfully
✓ Successfully enqueued test job
✓ Successfully dequeued test job
✓ Cleanup successful

========================================
 ✅ All Tests Passed!
========================================
```

---

### 🧪 Complete Test Suite

Run comprehensive tests covering all functionality:

```bash
# Using helper script (auto-detects container)
./test-redis.sh

# Or manually inside container
docker exec <container-name> php /var/www/html/run-tests.php

# Or if inside container already
php /var/www/html/run-tests.php
```

**What it tests:**
- ✅ Environment & Dependencies
- ✅ Redis Connection
- ✅ Redis Queue Operations (enqueue, dequeue, FIFO, status tracking)
- ✅ Compression API Endpoint
- ✅ Configuration
- ✅ File System & Permissions

**Expected Output:**
```
======================================================================
              Video Compression System - Full Test Suite              
======================================================================

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  1. Environment & Dependencies
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ✓ PHP version >= 7.4 - Current: 8.1.33
  ✓ Redis extension loaded - Version: 6.3.0
  ✓ cURL extension loaded - Required for API requests
  ✓ JSON extension loaded - Required for data encoding

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  2. Redis Connection Test
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ✓ Connect to Redis server - ✓ Connected
  ✓ Get Redis server info - Version: 8.0.2

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  3. Redis Queue Operations
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  🧹 Cleared test queues
  ✓ Enqueue 3 test jobs - Enqueued: 3/3
  ✓ Verify queue length - Length: 3
  ✓ Dequeue first job (FIFO order) - Job ID: test_job_1_1732205400
  ✓ Mark job as completed - Job: test_job_1_1732205400
  ✓ Mark job as failed - Job: test_job_2_1732205401
  ✓ Get job status - Status: completed
  ✓ Get queue statistics - Completed: 1, Failed: 1
  ✓ Timeout on empty queue - Elapsed: 1.02s
  🧹 Cleaned up test data

... (more sections)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Test Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Total Tests:    25
  ✓ Passed:       25
  ✗ Failed:       0
  ⚠️  Warnings:     0
  Success Rate:   100.0%
  Execution Time: 3.45s

  🎉 All tests passed!
```

---

### ⚠️ Important Testing Notes

**1. Tests MUST run inside the Docker container**

Redis binds to `127.0.0.1` inside the container and is NOT accessible from the host machine. This is intentional for security.

❌ **WRONG** (Running from host):
```bash
# This will FAIL with "Failed to connect to Redis"
php run-tests.php
```

✅ **CORRECT** (Running inside container):
```bash
# Option 1: Use helper script (auto-detects container)
./test-redis.sh

# Option 2: Manually specify container
docker exec <container-name> php /var/www/html/run-tests.php

# Option 3: Enter container then run
docker exec -it <container-name> bash
php /var/www/html/run-tests.php
```

**2. Finding your container name:**
```bash
# List all running containers
docker ps

# Find VPS-API container
docker ps | grep -i vps
docker ps | grep -i api
```

**3. Browser-based testing:**
```
# Health check (public endpoint)
https://v.ogtemplate.com/redis-health.php

# View logs (shows Redis queue activity)
https://v.ogtemplate.com/view-logs.php
```

---

## 🔒 Security & Performance

### Security:
- ✅ Bound to localhost only (127.0.0.1)
- ✅ No external network access
- ✅ Password authentication ready (commented out for local)
- ✅ Protected by Docker network isolation

### Performance:
- ✅ Persistent connections (`pconnect`)
- ✅ Blocking operations for efficient waiting
- ✅ Optimized memory settings (256MB limit)
- ✅ Automatic cleanup (30-day job expiry)
- ✅ AOF rewrite for compaction

### Persistence Settings:
```
# AOF (Append Only File)
appendonly yes
appendfsync everysec           # Max 1 second data loss

# RDB (Snapshots)
save 900 1                     # Save if 1 change in 15 min
save 300 10                    # Save if 10 changes in 5 min
save 60 10000                  # Save if 10k changes in 1 min

# Memory
maxmemory 256mb                # Adjust based on server capacity
maxmemory-policy noeviction    # Never evict queue data
```

---

## 🐛 Troubleshooting

### Issue: Redis not starting

**Check logs:**
```bash
docker logs <container-name>
```

**Common causes:**
1. Permission errors on log/data directories
2. Port 6379 already in use
3. Invalid configuration syntax

**Solution:**
- Redis now logs to stdout (no file permission issues)
- Configuration tested and working
- Port is internal to container (no conflicts)

### Issue: Cannot connect to Redis

**Test connection:**
```bash
docker exec -it <container-name> redis-cli ping
```

**Check RedisQueue:**
```php
$queue = new RedisQueue();
if (!$queue->isConnected()) {
    echo $queue->getLastError();
}
```

**Common causes:**
1. Redis not running
2. PHP Redis extension not loaded
3. Wrong host/port configuration

### Issue: Jobs not being processed

**Check queue status:**
```bash
redis-cli LLEN compression_queue
redis-cli LLEN compression_processing
```

**Verify job data:**
```bash
redis-cli LRANGE compression_queue 0 -1
```

---

## 📈 Production Monitoring

### Key Metrics to Monitor:

1. **Queue Length** - `LLEN compression_queue`
   - Alert if > 100 (backlog building)

2. **Processing Queue** - `LLEN compression_processing`
   - Alert if stuck at same count for > 10 minutes

3. **Failed Jobs** - `SCARD failed_jobs`
   - Monitor trend, investigate if increasing

4. **Memory Usage** - `INFO memory`
   - Alert if approaching maxmemory limit

5. **Persistence** - `INFO persistence`
   - Verify AOF/RDB are working

---

## ✅ Task Completion Checklist

- [x] Redis server installed and configured
- [x] PHP Redis extension installed and enabled
- [x] FIFO queue structure implemented
- [x] Job status tracking system complete
- [x] Persistence configured (AOF + RDB)
- [x] RedisQueue PHP class created
- [x] Integration with compress.php completed
- [x] Health check endpoint created
- [x] Comprehensive test suite created
- [x] Startup scripts with health checks
- [x] Docker entrypoint with Redis verification
- [x] Logging system implemented
- [x] Documentation completed

---

## 🎯 Next Steps

**Task 3 is COMPLETE.** Ready for:

**Task 4: Background Worker Service**
- Create worker daemon that monitors Redis queue
- Implement job picker (dequeue jobs)
- Update job status during processing
- Handle worker crashes/restarts

The Redis queue is now fully operational and ready to receive and manage video compression jobs!
