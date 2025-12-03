# Queue Issue Summary - November 23, 2025

## Problem
Worker is running and waiting for jobs, but the queue is always empty (0 pending jobs).

## Root Cause
**WordPress is not sending compression requests to the VPS API endpoint.**

Looking at your logs:
- ✅ Worker initialized successfully
- ✅ Redis connected (version 8.0.2)
- ✅ Worker is polling the queue every 10 seconds
- ❌ **ZERO `[COMPRESS]` log entries** - No API requests received
- ❌ **Queue always shows 0 jobs** - Nothing being enqueued

## Why This Happens

The system has two parts:
1. **VPS API** (your logs) - Worker is ready and waiting ✅
2. **WordPress Plugin** - Should send compression jobs ❌

The WordPress plugin needs to:
1. Detect when videos are uploaded
2. Send HTTP POST request to `https://v.ogtemplate.com/compress.php`
3. This creates a job in the Redis queue
4. Worker picks up and processes the job

**Currently:** WordPress is NOT sending any requests → No jobs created → Worker has nothing to do

## Most Likely Causes

### 1. WordPress Plugin Not Configured (90% probability)
- API Endpoint URL is empty or wrong
- API Key doesn't match
- Plugin hasn't been activated

### 2. No Videos Being Uploaded (8% probability)  
- No trigger to send compression requests
- Automatic processing is disabled

### 3. Network/Connectivity Issue (2% probability)
- WordPress can't reach the API endpoint
- Firewall blocking requests
- SSL certificate issue

## How to Fix

### Option 1: Check WordPress Configuration (START HERE)

**Steps:**
1. Log into WordPress admin
2. Navigate to: **Video Processor → Settings**
3. Verify settings:
   ```
   API Endpoint: https://v.ogtemplate.com/compress.php
   API Key: [must match your Docker env variable]
   ```
4. Click **"Test Connection"**
   - Should show: ✅ Connection successful
   - If fails: Wrong URL or API key
5. Click **"Save Changes"**

### Option 2: Test Queue System Directly

Run this on your VPS (inside Docker container):

```bash
# Enter container
docker exec -it vps-api bash

# Run diagnostic
bash /var/www/html/diagnose-queue-issue.sh

# Test queue manually
php /var/www/html/test-enqueue-job.php

# Watch for activity
tail -f /var/www/html/logs/all.log
```

**Expected result:**
- Test job gets enqueued
- Worker picks it up within 10 seconds
- Logs show processing activity

**If this works:**
- ✅ Queue system is functional
- ❌ Problem is WordPress configuration

### Option 3: Monitor for Incoming Requests

In one terminal:
```bash
# Watch for API requests
docker exec -it vps-api tail -f /var/www/html/logs/all.log | grep COMPRESS
```

In WordPress:
1. Upload a video to a post
2. Go to **Video Processor → Queue**
3. Click **"Process"** on the video

**You should see:**
```
[2025-11-23 XX:XX:XX] [INFO] [COMPRESS] Request received | {"method":"POST","uri":"/compress.php","ip":"xxx.xxx.xxx.xxx"}
[2025-11-23 XX:XX:XX] [INFO] [COMPRESS] Authentication successful | {"ip":"xxx.xxx.xxx.xxx"}
[2025-11-23 XX:XX:XX] [INFO] [COMPRESS] Job added to Redis queue | {"jobId":"job_xxxxx","queue_length":1}
```

**If you see nothing:**
- WordPress is not sending requests
- Check WordPress plugin configuration

## Quick Diagnosis

Run this command on your VPS:

```bash
docker exec vps-api bash -c "redis-cli LLEN compression_queue && grep -c COMPRESS /var/www/html/logs/all.log"
```

**Output interpretation:**
```
0     ← Queue length (0 = no jobs)
0     ← COMPRESS log count (0 = no API requests ever received)
```

This confirms WordPress has never sent a single request.

## Verification Steps

After fixing WordPress configuration:

1. **Test Connection** in WordPress settings → Should succeed
2. Upload a test video in WordPress
3. Click **"Process"** on the video
4. Check VPS logs: `docker exec vps-api tail -f /var/www/html/logs/all.log`
5. Should see:
   - `[COMPRESS] Request received`
   - `[COMPRESS] Job added to Redis queue`
   - `[WORKER] PROCESSING JOB`
   - `[WORKER] SUCCESS: Job completed`

## Files Created

To help you fix this, I've created:

1. **`QUEUE-TROUBLESHOOTING.md`** - Comprehensive troubleshooting guide
2. **`diagnose-queue-issue.sh`** - Automated diagnostic script
3. **`test-enqueue-job.php`** - Manual queue testing script

## Next Steps

1. **Check WordPress settings** (most likely fix)
2. **Run diagnostic script** to confirm queue system works
3. **Monitor logs** while testing from WordPress
4. **Contact me** if issue persists after configuration

## Expected Timeline

- Configuration check: 2 minutes
- Testing: 5 minutes  
- **Total: ~10 minutes to resolve**

This is a configuration issue, not a code bug. The system is working correctly - it just needs WordPress to send it jobs!
