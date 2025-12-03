# VPS API Logging System Documentation

## Overview
The VPS API uses a comprehensive, centralized logging system where **all logs** from all components are written to a single file: `logs/all.log`

This makes it easy to:
- Track the complete lifecycle of video processing jobs
- Debug issues across all components
- Monitor system health
- Access logs from outside the Docker container

## Log File Location

**Inside Container**: `/var/www/html/logs/all.log`  
**Outside Container**: Accessible via web interface (see below)

## Components That Log to all.log

All components write to the same `all.log` file with tagged prefixes:

1. **[API]** - Main API endpoint (`index.php`)
2. **[COMPRESS]** - Compression API endpoint (`compress.php`)
3. **[WORKER]** - Background worker processing jobs (`worker.php`)
4. **[REDIS-QUEUE]** - Redis queue operations (`RedisQueue.php`)
5. **[PROCESSOR]** - Video processor (`VideoProcessor.php`)
6. **[COMPRESSOR]** - Video compressor with FFmpeg (`VideoCompressor.php`)
7. **[WEBHOOK]** - Webhook receiver (`webhook-receiver.php`)

## Log Entry Format

```
[YYYY-MM-DD HH:MM:SS] [LEVEL] [COMPONENT] Message | {"context":"json"}
```

**Example**:
```
[2025-11-23 19:26:55] [INFO] [WORKER] Job completed successfully | {"jobId":"job_123_1700000000","postId":123,"time":"5.23s"}
[2025-11-23 19:27:10] [ERROR] [COMPRESSOR] FFmpeg compression failed | {"return_code":1,"error_summary":"Invalid input file"}
```

## Log Levels

- **INFO** - Normal operations, job progress
- **WARNING** - Non-critical issues (webhook retries, fallbacks)
- **ERROR** - Critical errors (job failures, compression errors)
- **DEBUG** - Detailed debugging information (enabled by default)
- **FATAL** - Fatal errors that cause service shutdown

## What Gets Logged

### API Requests (`index.php`, `compress.php`)
- ✅ All incoming requests (method, IP, user agent)
- ✅ Authentication attempts (success/failure)
- ✅ Validation errors
- ✅ Job creation and queue operations
- ✅ Response codes and messages

### Worker Operations (`worker.php`)
- ✅ Worker startup and initialization
- ✅ System information (PHP version, memory, Redis status)
- ✅ Queue statistics (every 30 seconds)
- ✅ Heartbeat (every 5 minutes)
- ✅ Job pickup from queue
- ✅ Job processing start/complete
- ✅ Job success/failure with details
- ✅ Webhook sending attempts and responses
- ✅ All errors and exceptions

### Video Compression (`VideoCompressor.php`)
- ✅ Compression job start
- ✅ File validation (source, output)
- ✅ FFmpeg execution start/complete
- ✅ FFmpeg output summary (FPS, bitrate, size)
- ✅ Compression statistics (ratio, duration, file sizes)
- ✅ All errors with full details

### Redis Queue (`RedisQueue.php`)
- ✅ Connection status
- ✅ Enqueue/dequeue operations
- ✅ Queue length and statistics
- ✅ Job status updates
- ✅ Connection errors

### Webhooks
- ✅ Webhook preparation and payload
- ✅ HTTP request details (URL, timeout)
- ✅ Response status codes
- ✅ Success/failure status
- ✅ Retry attempts

## Accessing Logs

### Option 1: Web Interface (Recommended)

**URL**: `https://v.ogtemplate.com/logs-viewer.php?api_key=YOUR_API_KEY`

**IMPORTANT**: The log viewer requires API key authentication for security. Replace `YOUR_API_KEY` with your actual API key from the environment configuration.

Features:
- View **complete logs** (all lines, no limits)
- **Download complete log file** as `.log` file
- **Search** through logs in real-time
- **Filter** by log level (ERROR, INFO, WARNING)
- **Filter** by component (API, WORKER, COMPRESS, etc.)
- **Auto-refresh** every 10 seconds
- **Color-coded** log entries (errors in red, info in green)
- **Statistics** (total lines, file size, error count, etc.)

### Option 2: Direct API Access

**IMPORTANT**: All API requests require authentication via API key.

#### View All Logs (JSON)
```bash
# Using URL parameter
curl "https://v.ogtemplate.com/logs-api.php?action=view&all=true&api_key=YOUR_API_KEY"

# Or using X-API-Key header (recommended)
curl -H "X-API-Key: YOUR_API_KEY" "https://v.ogtemplate.com/logs-api.php?action=view&all=true"
```

#### Download Complete Log File
```bash
# Using URL parameter
curl -O "https://v.ogtemplate.com/logs-api.php?action=download&api_key=YOUR_API_KEY"

# Or using X-API-Key header (recommended)
curl -H "X-API-Key: YOUR_API_KEY" "https://v.ogtemplate.com/logs-api.php?action=download"
```

#### View Last N Lines
```bash
curl -H "X-API-Key: YOUR_API_KEY" "https://v.ogtemplate.com/logs-api.php?action=view&lines=100"
```

### Option 3: Inside Container

```bash
# View real-time logs
docker exec -it vps-api tail -f /var/www/html/logs/all.log

# View last 100 lines
docker exec -it vps-api tail -100 /var/www/html/logs/all.log

# Search for errors
docker exec -it vps-api grep "ERROR" /var/www/html/logs/all.log

# Search for specific job
docker exec -it vps-api grep "job_123" /var/www/html/logs/all.log
```

## Example: Tracking a Job Through Logs

When a compression job is submitted, you can track its complete lifecycle in the logs:

```
# 1. Job received by compress API
[2025-11-23 19:26:00] [INFO] [COMPRESS] Request received | {"method":"POST","ip":"1.2.3.4"}
[2025-11-23 19:26:00] [INFO] [COMPRESS] Authentication successful | {"ip":"1.2.3.4"}
[2025-11-23 19:26:00] [INFO] [COMPRESS] Job ID generated | {"jobId":"job_123_1700000000","postId":123}

# 2. Job added to Redis queue
[2025-11-23 19:26:00] [INFO] [COMPRESS] RedisQueue connected successfully | {"jobId":"job_123_1700000000"}
[2025-11-23 19:26:00] [INFO] [COMPRESS] Job added to Redis queue | {"jobId":"job_123_1700000000","queue_length":1}

# 3. Worker picks up job
[2025-11-23 19:26:05] [INFO] [WORKER] Job picked from queue | {"jobId":"job_123_1700000000","postId":123}
[2025-11-23 19:26:05] [INFO] [WORKER] PROCESSING JOB: job_123_1700000000

# 4. Video compression starts
[2025-11-23 19:26:05] [INFO] [COMPRESSOR] Starting compression for job: job_123_1700000000
[2025-11-23 19:26:05] [INFO] [COMPRESSOR] Source file found | {"size":"5.2 MB"}
[2025-11-23 19:26:05] [INFO] [COMPRESSOR] FFmpeg compression started | {"target_resolution":"854x480","target_bitrate":"800kbps"}

# 5. Compression completes
[2025-11-23 19:26:30] [INFO] [COMPRESSOR] FFmpeg compression completed | {"duration":"25.3s","output_size":"2.1 MB","fps":"30 fps"}
[2025-11-23 19:26:30] [INFO] [COMPRESSOR] Compression stats | {"compression_ratio":59.62}

# 6. Webhook sent to WordPress
[2025-11-23 19:26:30] [INFO] [WORKER] DEBUG: Sending webhook to WordPress... | {"jobId":"job_123_1700000000"}
[2025-11-23 19:26:31] [INFO] [WORKER] DEBUG: Webhook sent successfully | {"http_code":200,"time":"0.52s"}

# 7. Job marked complete
[2025-11-23 19:26:31] [INFO] [WORKER] SUCCESS: Job completed successfully | {"jobId":"job_123_1700000000","time":"26.1s"}
```

## Searching Logs

### Search by Job ID
```bash
grep "job_123_1700000000" logs/all.log
```

### Search by Post ID
```bash
grep "postId\":123" logs/all.log
```

### Find All Errors
```bash
grep "ERROR" logs/all.log
```

### Find FFmpeg Issues
```bash
grep "FFmpeg" logs/all.log | grep "ERROR"
```

### Find Webhook Failures
```bash
grep "WEBHOOK" logs/all.log | grep -E "ERROR|failed"
```

## Log Rotation

Currently, logs append indefinitely to `all.log`. For production:

1. **Manual cleanup**:
   ```bash
   # Archive old logs
   mv logs/all.log logs/all-$(date +%Y%m%d).log
   touch logs/all.log
   chmod 666 logs/all.log
   ```

2. **Automatic rotation** (recommended for production):
   - Set up logrotate on the host system
   - Configure rotation based on size (e.g., 100MB) or time (e.g., daily)

## Troubleshooting

### Logs Not Appearing

1. **Check file permissions**:
   ```bash
   ls -la logs/all.log
   # Should show: -rw-rw-rw- (666)
   ```

2. **Check directory permissions**:
   ```bash
   ls -la logs/
   # Should show: drwxrwxrwx (777)
   ```

3. **Verify logging is working**:
   ```bash
   php test-complete-logging.php
   ```

### Cannot Access Web Viewer

1. Verify the files exist:
   - `logs-viewer.php` - Web interface
   - `logs-api.php` - Backend API

2. Check web server is running:
   ```bash
   curl http://localhost/logs-viewer.php
   ```

3. Check file permissions:
   ```bash
   ls -la logs-viewer.php logs-api.php
   ```

## Best Practices

1. **Use the web viewer** for browsing and searching logs
2. **Download logs** before major deployments for backup
3. **Search by jobId** to track specific jobs
4. **Monitor ERROR level** logs regularly
5. **Check worker heartbeat** to ensure worker is alive
6. **Review queue statistics** to detect backlogs
7. **Set up alerts** for critical errors (optional)

## Security

Both the web viewer and API are protected by API key authentication:

- **Web Viewer**: Requires `api_key` URL parameter  
  Example: `https://v.ogtemplate.com/logs-viewer.php?api_key=YOUR_API_KEY`

- **API Endpoint**: Requires API key via URL parameter or `X-API-Key` header  
  Example: `curl -H "X-API-Key: YOUR_API_KEY" https://v.ogtemplate.com/logs-api.php`

**Why authentication is required:**
- Logs contain sensitive operational data (IPs, job IDs, system stats)
- Prevents unauthorized access to system internals
- Protects against data leakage
- Follows security best practices

**API Key Location:**
- Set via `API_KEY` environment variable in Docker/VPS configuration
- Same API key used for all API endpoints (compress, index, logs)

## Log File URLs

- **Web Viewer**: `https://v.ogtemplate.com/logs-viewer.php?api_key=YOUR_API_KEY`
- **API Endpoint**: `https://v.ogtemplate.com/logs-api.php` (requires authentication)
- **Download**: `https://v.ogtemplate.com/logs-api.php?action=download&api_key=YOUR_API_KEY`

## Support

For issues with logging:
1. Run the test script: `php test-complete-logging.php`
2. Check file permissions on `logs/` directory
3. Verify web server has write access
4. Check Docker volume mounts (if using Docker)
