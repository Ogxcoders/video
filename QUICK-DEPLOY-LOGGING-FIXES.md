# Quick Deployment Guide - Logging Fixes

## What Was Fixed

### Critical Issue #1: VideoProcessor.php Blocking Logs ❌
**Problem:** When `debug` config was false, ALL INFO logs were silently dropped
```php
// OLD CODE - BROKEN
if (!$this->config['debug'] && $level === 'INFO') {
    return;  // ❌ SKIPPED LOGGING!
}
```

**Solution:** Removed debug check - now logs ALWAYS write ✅

### Critical Issue #2: Missing Context Support ❌
**Problem:** Log method didn't support structured context data
```php
// OLD - No context
private function log($message, $level = 'INFO')
```

**Solution:** Added context parameter for rich logging ✅
```php
// NEW - With context
private function log($message, $context = [], $level = 'INFO')
```

### Critical Issue #3: Missing Component Tag ❌
**Problem:** Couldn't identify which component wrote logs

**Solution:** Added `[PROCESSOR]` tag ✅
```
[2025-11-23 17:30:47] [INFO] [PROCESSOR] Video downloaded: /path/to/file.mp4
```

### Critical Issue #4: Missing Directory Structure ❌
**Problem:** Fresh deployments didn't have logs directory

**Solution:** Added .gitkeep and .gitignore ✅

---

## Deploy to Your VPS

### Step 1: Pull Latest Code
```bash
cd /path/to/your/vps-api
git pull origin main
```

### Step 2: Verify Files Exist
```bash
# Check .gitkeep file
ls -la logs/.gitkeep

# Check .gitignore file
cat .gitignore

# Verify VideoProcessor.php fix
grep -A 5 "private function log" VideoProcessor.php
```

### Step 3: Set Permissions (if needed)
```bash
# Ensure logs directory is writable
chmod 777 logs/

# If all.log exists, make it writable
chmod 666 logs/all.log
```

### Step 4: Restart Services
```bash
# Restart worker service (adjust command for your setup)
sudo systemctl restart video-worker

# OR if using Docker/Coolify
docker restart your-container-name

# OR if using nohup
pkill -f worker.php
nohup php worker.php > /dev/null 2>&1 &
```

### Step 5: Test Logging
```bash
# Watch logs in real-time
tail -f logs/all.log

# Process a test video and watch the logs appear
```

### Step 6: View via API
```bash
# View last 100 lines
curl https://v.ogtemplate.com/view-logs.php?lines=100

# Pretty print
curl https://v.ogtemplate.com/view-logs.php?lines=50 | jq .
```

---

## Verify All Components Are Logging

Run this after processing a few videos:

```bash
cd vps-api

# Count logs from each component
echo "=== Log Statistics ==="
echo "WORKER logs:      $(grep -c '\[WORKER\]' logs/all.log)"
echo "COMPRESSOR logs:  $(grep -c '\[COMPRESSOR\]' logs/all.log)"
echo "PROCESSOR logs:   $(grep -c '\[PROCESSOR\]' logs/all.log)"
echo "REDIS-QUEUE logs: $(grep -c '\[REDIS-QUEUE\]' logs/all.log)"
echo "COMPRESS API:     $(grep -c '\[COMPRESS\]' logs/all.log)"
echo "Main API logs:    $(grep -c '\[API\]' logs/all.log)"
echo "WEBHOOK logs:     $(grep -c '\[WEBHOOK\]' logs/all.log)"
echo ""
echo "Total log lines:  $(wc -l logs/all.log | awk '{print $1}')"
echo "Log file size:    $(du -h logs/all.log | awk '{print $1}')"
```

Expected output (after processing some videos):
```
=== Log Statistics ===
WORKER logs:      45
COMPRESSOR logs:  12
PROCESSOR logs:   8    <-- ✅ Should now appear!
REDIS-QUEUE logs: 20
COMPRESS API:     5
Main API logs:    3
WEBHOOK logs:     2

Total log lines:  95
Log file size:    24K
```

---

## Troubleshooting

### Problem: Logs directory doesn't exist
```bash
mkdir -p vps-api/logs
chmod 777 vps-api/logs
```

### Problem: Permission denied writing to all.log
```bash
sudo chown www-data:www-data vps-api/logs/all.log
chmod 666 vps-api/logs/all.log
```

### Problem: Still no PROCESSOR logs appearing
```bash
# Check if VideoProcessor is being used
grep -r "new VideoProcessor" vps-api/

# Check config.php for debug setting
grep "debug" vps-api/config.php

# The fix removed the debug check, so logs should appear regardless
```

### Problem: view-logs.php returns empty
```bash
# Check if file exists and has content
ls -lh vps-api/logs/all.log

# If empty, process a video to trigger logging
# Check worker is running
ps aux | grep worker.php
```

---

## Expected Log Format

After fixes, all components follow this format:

```
[TIMESTAMP] [LEVEL] [COMPONENT] message | {"context": "data"}
```

**Examples:**
```
[2025-11-23 17:30:45] [INFO] [WORKER] Job picked from queue | {"jobId":"job_123","postId":"456"}
[2025-11-23 17:30:46] [INFO] [COMPRESSOR] Starting compression for job: job_123
[2025-11-23 17:30:47] [INFO] [PROCESSOR] Video downloaded: /path/to/file.mp4 | {"size":"15.2 MB"}
[2025-11-23 17:30:50] [INFO] [REDIS-QUEUE] Job enqueued: job_123, Queue length: 5
[2025-11-23 17:30:51] [ERROR] [COMPRESSOR] Compression failed | {"error":"FFmpeg timeout"}
```

---

## Files Changed

1. ✅ `vps-api/VideoProcessor.php` - Fixed log() method
2. ✅ `vps-api/logs/.gitkeep` - Created
3. ✅ `vps-api/.gitignore` - Created
4. ✅ `vps-api/LOGGING-FIXES.md` - Full documentation
5. ✅ `vps-api/QUICK-DEPLOY-LOGGING-FIXES.md` - This file
6. ✅ `replit.md` - Updated with latest changes

---

## Support

If logs still don't appear after deployment:

1. Check `LOGGING-FIXES.md` for detailed technical documentation
2. Verify all services restarted properly
3. Check file permissions on logs directory
4. Ensure Redis and worker are running
5. Process a test video and monitor `tail -f logs/all.log`

**Status:** ✅ Ready to deploy
**Impact:** HIGH - All logs will now appear correctly
**Downtime:** None (safe to deploy during operation)
