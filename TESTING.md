# Testing Guide for Video Compression System

## Overview

The testing suite has been consolidated into two main files:

1. **`run-tests.php`** - Comprehensive test suite covering all functionality
2. **`test-connection.php`** - Quick smoke test for connectivity

## Running Tests

### Inside Docker Container (Recommended)

```bash
# Run full test suite
docker exec <container-name> php /var/www/html/run-tests.php

# Run quick connection test
docker exec <container-name> php /var/www/html/test-connection.php
```

### From Host Machine (if PHP is installed)

```bash
# Navigate to vps-api directory
cd vps-api

# Run full test suite
php run-tests.php

# Run connection test
php test-connection.php
```

### Via Browser

```
# Connection test only
https://v.ogtemplate.com/test-connection.php
```

## Test Coverage

### 1. Environment & Dependencies
- ✓ PHP version check (>= 7.4)
- ✓ Redis extension loaded
- ✓ cURL extension loaded
- ✓ JSON extension loaded

### 2. Redis Connection
- ✓ Connect to Redis server
- ✓ Get server information
- ✓ Verify connection stability

### 3. Redis Queue Operations
- ✓ Enqueue multiple jobs
- ✓ Verify queue length
- ✓ FIFO order (First In First Out)
- ✓ Mark jobs as completed
- ✓ Mark jobs as failed
- ✓ Get job status
- ✓ Queue statistics
- ✓ Timeout handling on empty queue

### 4. Compression API Endpoint
- ✓ POST request handling
- ✓ JSON response validation
- ✓ Job ID generation
- ✓ Authentication
- ✓ API endpoint availability

### 5. Configuration
- ✓ config.php exists
- ✓ API key configured
- ✓ FFmpeg availability
- ✓ Required settings present

### 6. File System & Permissions
- ✓ Logs directory writable
- ✓ Videos directory writable
- ✓ HLS directory writable
- ✓ Required files exist

## Test Output Example

```
======================================================================
              Video Compression System - Full Test Suite              
======================================================================

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  1. Environment & Dependencies
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ✓ PHP version >= 7.4 - Current: 8.1.33
  ✓ Redis extension loaded - Version: 5.3.7
  ✓ cURL extension loaded - Required for API requests
  ✓ JSON extension loaded - Required for data encoding

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  2. Redis Connection Test
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ✓ Connect to Redis server - ✓ Connected
  ✓ Get Redis server info - Version: 8.0.2

...

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  Test Summary
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Total Tests:    28
  ✓ Passed:       28
  ✗ Failed:       0
  ⚠️  Warnings:     0
  Success Rate:   100.0%
  Execution Time: 3.45s

  🎉 All tests passed!
```

## Troubleshooting

### Redis Connection Failed

**Error:** `Failed to connect to Redis`

**Solutions:**
1. Check if Redis is running:
   ```bash
   docker exec <container> redis-cli ping
   ```
2. Verify Redis configuration in `redis.conf`
3. Check logs:
   ```bash
   docker logs <container>
   ```

### API Key Not Configured

**Warning:** `API_KEY not configured (using default)`

**Solution:**
```bash
# Set environment variable
export API_KEY="your-secure-random-key-here"

# Or in Docker
docker run -e API_KEY="your-key" ...
```

### FFmpeg Not Found

**Error:** `FFmpeg available - NOT FOUND`

**Solution:**
```bash
# Install FFmpeg in container
apt-get update && apt-get install -y ffmpeg

# Or check path in config.php
'ffmpeg_binary' => '/usr/bin/ffmpeg'
```

### Permission Denied

**Error:** `Logs directory writable - Permission denied`

**Solution:**
```bash
# Fix permissions
chmod -R 755 /var/www/html/logs
chmod -R 755 /var/www/html/videos
chmod -R 755 /var/www/html/hls
```

## CI/CD Integration

### Exit Codes

- `0` - All tests passed
- `1` - One or more tests failed

### Example GitHub Actions

```yaml
- name: Run Test Suite
  run: |
    docker exec vps-api-container php /var/www/html/run-tests.php
```

### Example GitLab CI

```yaml
test:
  script:
    - docker exec vps-api-container php /var/www/html/run-tests.php
```

## Manual Testing

### Test Compression API Manually

```bash
curl -X POST https://v.ogtemplate.com/compress.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "postId": 12345,
    "wpPostUrl": "https://example.com/post/12345",
    "wpMediaPath": "/wp-content/uploads/2024/11/video.mp4",
    "wpVideoUrl": "https://example.com/video.mp4",
    "wpThumbnailPath": "/wp-content/uploads/2024/11/thumb.jpg",
    "year": 2024,
    "month": 11
  }'
```

### Check Redis Queue

```bash
# Inside container
docker exec <container> redis-cli

# Check queue length
LLEN compression_queue

# View job details
HGETALL job:job_12345_1234567890

# View stats
SCARD completed_jobs
SCARD failed_jobs
```

## Old Test Files (Removed)

The following files have been removed and consolidated into `run-tests.php`:

- ❌ `test-redis-queue.php` (now part of run-tests.php)
- ❌ `test-compress.php` (now part of run-tests.php)

Only kept:
- ✅ `test-connection.php` (simple smoke test)
- ✅ `run-tests.php` (comprehensive test suite)

## Development

To add new tests, edit `run-tests.php` and add new test methods following the pattern:

```php
private function testMyNewFeature() {
    $this->printSection("7. My New Feature");
    
    $this->assert(
        $condition,
        "Test description",
        "Additional details"
    );
    
    echo "\n";
}
```

Then add it to the `run()` method.
