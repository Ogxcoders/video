# Background Worker Service Guide

## Overview

The Background Worker Service monitors the Redis queue and processes video compression jobs automatically. It's a daemon that runs continuously, picking up jobs, compressing videos, and notifying WordPress when complete.

## Architecture

```
WordPress → compress.php → Redis Queue → Background Worker → FFmpeg → WordPress Webhook
                              ↓
                        Job Status Tracking
```

## Components

### 1. VideoCompressor.php
Handles FFmpeg video compression operations.

**Features:**
- Compress videos to 480p (854x480, 800kbps, H.264)
- Copy original to `/var/www/media/content/YYYY/MM/POST_ID/original.mp4`
- Save compressed to `/var/www/media/content/YYYY/MM/POST_ID/compressed_480p.mp4`
- Validate output (playability, file size)
- Calculate compression statistics

**Key Methods:**
- `compressVideo($jobData)` - Main compression method
- `compress480p($input, $output)` - FFmpeg compression
- `validateVideo($path)` - Validate output
- `getVideoDuration($path)` - Get video duration

### 2. worker.php
Background daemon that processes jobs from the queue.

**Features:**
- Monitors Redis queue (10-second polling)
- Processes jobs sequentially (one at a time)
- Updates job status (pending → processing → completed/failed)
- Sends webhook to WordPress on completion
- Handles graceful shutdown (SIGTERM, SIGINT)
- Logs all activities

**Key Methods:**
- `start()` - Start the worker loop
- `processJob($job)` - Process a single job
- `sendWebhook($job, $result)` - Notify WordPress
- `handleSignal($signal)` - Graceful shutdown

### 3. webhook-receiver.php
Test endpoint to simulate WordPress webhook receiver.

## Installation

### Automatic (Docker)
The worker starts automatically when the container starts (via `docker-entrypoint.sh`).

```bash
docker-compose up -d
# Worker starts automatically
```

### Manual Start
```bash
# Inside container
php /var/www/html/worker.php

# Or in background
nohup php /var/www/html/worker.php > /dev/null 2>&1 &

# With logging
php /var/www/html/worker.php > /var/www/html/logs/worker-console.log 2>&1 &
```

## Configuration

### Environment Variables

```bash
# WordPress webhook URL
WORDPRESS_WEBHOOK_URL=https://ogtemplate.com/wp-json/compression/v1/webhook

# API key for webhook authentication
API_KEY=your-secure-api-key

# FFmpeg binary path (default: /usr/bin/ffmpeg)
FFMPEG_PATH=/usr/bin/ffmpeg
```

### config.php Settings

```php
return [
    // WordPress Integration
    'wordpress_webhook_url' => getenv('WORDPRESS_WEBHOOK_URL') ?: 'not set',
    
    // Media Directories
    'media_uploads_dir' => '/var/www/media/uploads',
    'media_content_dir' => '/var/www/media/content',
    
    // FFmpeg Settings
    'ffmpeg_binary' => '/usr/bin/ffmpeg',
    'ffmpeg_timeout' => 600,
];
```

## Worker Workflow

### 1. Job Pickup
```
Worker polls Redis queue (10s timeout)
↓
Job found → Status: pending → processing
↓
Validate job data (postId, wpMediaPath, year, month)
```

### 2. Video Processing
```
Read source: /var/www/media/uploads/YYYY/MM/video.mp4
↓
Copy to: /var/www/media/content/YYYY/MM/POST_ID/original.mp4
↓
Compress: FFmpeg (480p, 800kbps, H.264)
↓
Output: /var/www/media/content/YYYY/MM/POST_ID/compressed_480p.mp4
↓
Validate: Check playability with ffprobe
```

### 3. Completion
```
Calculate stats (sizes, compression ratio, duration)
↓
Update job status: processing → completed
↓
Send webhook to WordPress with results
↓
Log statistics
```

## Monitoring

### Check Worker Status
```bash
# Check if worker is running
ps aux | grep worker.php

# Check worker logs (real-time)
tail -f /var/www/html/logs/worker.log

# Check worker console output
tail -f /var/www/html/logs/worker-console.log
```

### Check Queue Status
```bash
# Inside container
docker exec vps-api redis-cli

# Check queue length
LLEN compression_queue

# Check processing queue
LLEN compression_processing

# View job status
HGETALL job:job_12345_1234567890

# Statistics
SCARD completed_jobs
SCARD failed_jobs
```

### View Logs
```bash
# Worker logs
docker exec vps-api tail -f /var/www/html/logs/worker.log

# Compressor logs
docker exec vps-api tail -f /var/www/html/logs/worker.log | grep COMPRESSOR

# Webhook logs
docker exec vps-api tail -f /var/www/html/logs/webhook.log
```

## Testing

### End-to-End Test
```bash
# Make sure worker is running first
php /var/www/html/test-worker-e2e.php
```

### Manual Job Submission
```bash
curl -X POST https://v.ogtemplate.com/compress.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "postId": 12345,
    "wpPostUrl": "https://ogtemplate.com/post/12345",
    "wpMediaPath": "/wp-content/uploads/2024/11/video.mp4",
    "wpVideoUrl": "https://ogtemplate.com/uploads/video.mp4",
    "year": 2024,
    "month": 11
  }'
```

Then watch the logs:
```bash
tail -f /var/www/html/logs/worker.log
```

## Troubleshooting

### Worker Not Starting

**Symptom:** Worker doesn't appear in process list

**Solutions:**
```bash
# Check if Redis is running
redis-cli ping

# Check for errors in startup
cat /var/www/html/logs/worker-console.log

# Start manually
php /var/www/html/worker.php
```

### Jobs Not Being Processed

**Symptom:** Jobs stay in pending status

**Solutions:**
```bash
# Check worker is running
ps aux | grep worker.php

# Check Redis queue
redis-cli LLEN compression_queue

# Check worker logs for errors
tail -100 /var/www/html/logs/worker.log
```

### FFmpeg Errors

**Symptom:** Jobs fail during compression

**Solutions:**
```bash
# Check FFmpeg is installed
ffmpeg -version

# Check source file exists
ls -lah /var/www/media/uploads/2024/11/

# Check permissions
ls -lah /var/www/media/content/

# Check worker logs for FFmpeg output
grep "FFmpeg" /var/www/html/logs/worker.log
```

### Webhook Not Received

**Symptom:** WordPress doesn't get completion notification

**Solutions:**
```bash
# Check webhook URL configured
grep wordpress_webhook_url /var/www/html/config.php

# Check webhook logs
tail -50 /var/www/html/logs/worker.log | grep webhook

# Test webhook manually
curl -X POST https://ogtemplate.com/wp-json/compression/v1/webhook \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{"jobId":"test","postId":12345,"status":"completed"}'
```

## Performance

### Current Settings (MVP)
- **Concurrency:** 1 job at a time (sequential)
- **Polling:** 10 second timeout
- **Resolution:** 480p only
- **Processing Time:** ~30-60 seconds per video (depends on video length)

### Scaling Considerations
For future scaling:
- Increase concurrency (process multiple jobs in parallel)
- Add more worker instances
- Implement job priority queue
- Add multiple resolution support

## Log Format

### Worker Logs
```
[2024-11-21 10:30:00] [INFO] [WORKER] Processing job: job_12345_1234567890 (Post ID: 12345)
[2024-11-21 10:30:01] [INFO] [COMPRESSOR] Starting compression for job: job_12345_1234567890
[2024-11-21 10:30:45] [INFO] [COMPRESSOR] Compression completed | {"time":"44.23s"}
[2024-11-21 10:30:46] [INFO] [WORKER] Job completed successfully | {"time":"45.12s","compression_ratio":"78.5%"}
```

### Webhook Logs
```
[2024-11-21 10:30:46] Webhook received | {"jobId":"job_12345_1234567890","postId":12345,"status":"completed"}
```

## WordPress Integration

The worker sends a webhook to WordPress on job completion with this payload:

```json
{
  "jobId": "job_12345_1234567890",
  "postId": 12345,
  "status": "completed",
  "compressed_video_480p": "https://v.ogtemplate.com/content/2024/11/12345/compressed_480p.mp4",
  "original_size": 25600000,
  "compressed_size": 5500000,
  "compression_ratio": 78.5,
  "duration": 15.5,
  "processing_time": 45.2,
  "completed_at": "2024-11-21 10:30:46"
}
```

WordPress should update post meta fields:
- `_compression_status` → `completed`
- `_compressed_video_480p` → URL
- `_original_file_size` → bytes
- `_compressed_file_size` → bytes
- `_compression_ratio` → percentage
- `_video_duration` → seconds
- `_processing_completed_at` → timestamp

## Graceful Shutdown

The worker handles shutdown signals gracefully:

```bash
# Send SIGTERM (graceful shutdown)
kill -TERM $(pgrep -f worker.php)

# Send SIGINT (Ctrl+C)
kill -INT $(pgrep -f worker.php)
```

On shutdown:
- Completes current job if processing
- Logs final statistics
- Cleanly disconnects from Redis
- Exits with code 0

## Files

- **`worker.php`** - Main worker daemon (560 lines)
- **`VideoCompressor.php`** - Compression handler (350 lines)
- **`webhook-receiver.php`** - Test webhook endpoint
- **`test-worker-e2e.php`** - End-to-end test script
- **`logs/worker.log`** - Worker activity log
- **`logs/worker-console.log`** - Worker console output
- **`logs/webhook.log`** - Webhook activity log
