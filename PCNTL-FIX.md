# PCNTL Extension Fix

## Issue Found
Your worker logs showed this warning:
```
[WARNING] [WORKER] WARNING: pcntl_signal not available - graceful shutdown limited
```

## Root Cause
The `pcntl` (Process Control) PHP extension was not installed in the Docker container. This extension is required for:
- Graceful shutdown signal handling (SIGTERM, SIGINT)
- Proper worker process termination
- Clean job state management during shutdown

## What Was Fixed
✅ Added `pcntl` extension to Dockerfile (line 23)

**Changed:**
```dockerfile
&& docker-php-ext-install curl \
```

**To:**
```dockerfile
&& docker-php-ext-install curl pcntl \
```

## Impact of the Fix
**Before:**
- Worker couldn't handle shutdown signals properly
- Ungraceful termination when container stops
- Potential job state corruption during shutdown

**After:**
- Worker properly handles SIGTERM and SIGINT signals
- Graceful shutdown with job cleanup
- Safe container restarts without job loss

## How to Deploy

### Option 1: Rebuild Docker Image (Recommended)
```bash
# In your Coolify dashboard or VPS
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Option 2: Coolify Auto-Rebuild
Simply push the updated Dockerfile to your git repository, and Coolify will automatically rebuild the container.

### Option 3: Manual Docker Build
```bash
cd vps-api
docker build -t vps-api:latest .
docker stop <container-name>
docker rm <container-name>
docker run -d --name vps-api vps-api:latest
```

## Verification
After rebuilding, check the worker logs. You should **NOT** see this warning anymore:
```bash
# View worker logs
docker exec -it <container-name> cat /var/www/html/logs/all.log | grep -i "pcntl"
```

**Expected result:** No pcntl warning in the logs

## Files Changed
- `vps-api/Dockerfile` - Added pcntl extension installation

---

**Date:** November 23, 2025  
**Status:** ✅ Fixed - Awaiting deployment
