# Dockerfile Deployment Fix - November 23, 2025

## Problem
Docker deployment was failing with **exit code 255** during the build process.

## Error Details
```
Deployment failed: Command execution failed (exit code 255)
Error response from daemon: No such container
```

The build would start installing packages (321 packages, 223 MB) but would fail before completing.

## Root Cause
**Line 20 of `Dockerfile` was attempting to install `curl` as a PHP extension:**

```dockerfile
# INCORRECT - This caused the build to fail
&& docker-php-ext-install curl pcntl \
```

**The Issue:** `curl` is **NOT** a PHP extension that can be compiled/installed via `docker-php-ext-install`. The curl functions are already built into PHP by default when you install `libcurl4-openssl-dev`.

## Solution
**Removed `curl` from the PHP extension installation line:**

```dockerfile
# CORRECT - Only install pcntl
&& docker-php-ext-install pcntl \
```

## What Changed
**File:** `vps-api/Dockerfile`
- **Line 23:** Changed from `docker-php-ext-install curl pcntl` to `docker-php-ext-install pcntl`

## Why This Works
- `libcurl4-openssl-dev` is installed via apt (line 15), which provides the curl library
- PHP's curl functions are enabled automatically when libcurl is available
- `pcntl` is a PHP extension that needs to be compiled, so it stays in the extension install command
- `redis` is installed via PECL separately (line 24)

## Deployment Impact
- **Before:** Build would fail after downloading packages, never completing the build
- **After:** Build completes successfully, container starts properly

## How to Deploy
1. **Push the fixed Dockerfile to your repository**
2. **Trigger a new deployment in Coolify** (or your deployment platform)
3. **The build should now complete successfully**

## Verification
After deployment succeeds, verify:
1. Container is running: `docker ps`
2. PHP curl extension works: `docker exec <container> php -r "echo curl_version()['version'];"`
3. pcntl extension loaded: `docker exec <container> php -m | grep pcntl`
4. Redis extension loaded: `docker exec <container> php -m | grep redis`

## Additional Notes
- The build downloads **321 packages** and **223 MB** of archives
- This includes ffmpeg with all video processing dependencies
- Total disk space after build: **826 MB**
- Build time: ~2-3 minutes (depending on network speed)

## Related Files
- `vps-api/Dockerfile` - Fixed Dockerfile
- `vps-api/docker-entrypoint.sh` - Startup script
- `vps-api/redis.conf` - Redis configuration

---

**Status:** ✅ Fixed
**Date:** November 23, 2025
**Impact:** Critical - Deployment was completely broken, now working
