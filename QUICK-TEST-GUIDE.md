# 🧪 Quick Test Guide

## Run Tests in 30 Seconds

### Option 1: Full Test Suite (Inside Docker)
```bash
docker exec vps-api php /var/www/html/run-tests.php
```

### Option 2: Quick Connection Test (Browser)
```
https://v.ogtemplate.com/test-connection.php
```

### Option 3: Quick Connection Test (CLI)
```bash
docker exec vps-api php /var/www/html/test-connection.php
```

## Expected Output

### ✅ Success
```
🎉 All tests passed!
Success Rate: 100.0%
```

### ⚠️ Warnings
```
⚠️  API_KEY not configured (using default)
⚠️  Redis not connected - jobs will use file-based fallback
```

### ❌ Failure
```
❌ Some tests failed. Review output above.
```

## Common Issues & Quick Fixes

| Issue | Quick Fix |
|-------|-----------|
| Redis not connected | `docker exec vps-api redis-cli ping` |
| FFmpeg not found | `docker exec vps-api which ffmpeg` |
| Permission denied | `docker exec vps-api chmod -R 755 /var/www/html/logs` |
| API key warning | Set `API_KEY` environment variable |

## Test What You Just Changed

### Just changed Redis code?
```bash
docker exec vps-api php /var/www/html/run-tests.php | grep -A 20 "Redis"
```

### Just changed API endpoint?
```bash
curl -X POST https://v.ogtemplate.com/compress.php \
  -H "X-API-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"postId":99999,"wpMediaPath":"/test.mp4","year":2024,"month":11}'
```

### Just changed configuration?
```bash
docker exec vps-api php /var/www/html/test-connection.php | grep -A 5 "configuration"
```

## Files

- **`run-tests.php`** - Complete test suite (all tests)
- **`test-connection.php`** - Quick smoke test (30 seconds)
- **`TESTING.md`** - Full documentation
- **Old files removed:** `test-compress.php`, `test-redis-queue.php` ❌

## One-Liner Cheat Sheet

```bash
# Test everything
docker exec vps-api php /var/www/html/run-tests.php

# Test connection only
docker exec vps-api php /var/www/html/test-connection.php

# Check Redis
docker exec vps-api redis-cli ping

# Check logs
docker exec vps-api tail -f /var/www/html/logs/compress.log

# View queue stats
docker exec vps-api redis-cli LLEN compression_queue
```
