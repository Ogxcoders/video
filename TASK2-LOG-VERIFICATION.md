# Task 2: Compression API Endpoint - Log Verification Guide

## 📊 Understanding Task 2 Logs

Task 2 creates comprehensive logs at `/vps-api/logs/compress.log` when the API receives requests.

---

## 🔍 Where to Find Task 2 Logs

### On Production Server (Coolify/Docker):

**1. Apache Access Logs (Container Logs)**
```bash
docker logs <container-name> | grep -E "POST|compress"
```

Shows HTTP requests like:
```
162.158.63.89 - - [21/Nov/2025:16:14:09 +0000] "POST /index.php HTTP/1.1" 200 440
```

**2. Compress API Application Logs (Inside Container)**
```bash
docker exec -it <container-name> cat /var/www/html/logs/compress.log
```

Shows detailed application logs like:
```
[2024-11-21 10:30:00] [COMPRESS] Request received | {"method":"POST","uri":"/compress.php","ip":"192.168.1.1"}
[2024-11-21 10:30:00] [COMPRESS] Authentication successful | {"ip":"192.168.1.1"}
[2024-11-21 10:30:00] [COMPRESS] Job ID generated | {"jobId":"job_12345_1700568600"}
[2024-11-21 10:30:01] [COMPRESS] Job added to Redis queue | {"queue_length":5}
```

---

## 🛠️ New Tools Created for Log Viewing

### 1. **View Logs Endpoint**
**URL:** `https://v.ogtemplate.com/view-logs.php`

**Usage:**
```bash
# View last 50 lines of all logs
curl https://v.ogtemplate.com/view-logs.php

# View last 100 lines
curl https://v.ogtemplate.com/view-logs.php?lines=100

# View only compress logs
curl https://v.ogtemplate.com/view-logs.php?type=compress

# View only redis logs
curl https://v.ogtemplate.com/view-logs.php?type=redis_queue
```

**Response:**
```json
{
  "timestamp": "2024-11-21 18:30:00",
  "logs": {
    "compress": {
      "exists": true,
      "size": 15420,
      "total_lines": 234,
      "showing_lines": 50,
      "entries": [
        "[2024-11-21 16:14:09] [COMPRESS] Request received | {...}",
        "[2024-11-21 16:14:09] [COMPRESS] Authentication successful | {...}"
      ]
    }
  },
  "stats": {
    "compress_api_calls": 12,
    "redis_operations": 10,
    "errors": 2
  }
}
```

### 2. **Compress API Health Check**
**URL:** `https://v.ogtemplate.com/check-compress-api.php`

**Usage:**
```bash
curl https://v.ogtemplate.com/check-compress-api.php
```

**Response:**
```json
{
  "compress_api": {
    "file_exists": true,
    "file_modified": "2024-11-21 16:12:00"
  },
  "configuration": {
    "api_key_set": true,
    "base_url": "https://v.ogtemplate.com",
    "allowed_origins": ["https://web.trendss.net"]
  },
  "logs": {
    "directory_exists": true,
    "files": [
      {
        "name": "compress.log",
        "size": 15420,
        "lines": 234
      }
    ]
  },
  "redis": {
    "connected": true,
    "queue_stats": {
      "pending": 0,
      "processing": 0,
      "completed": 12
    }
  }
}
```

---

## 📝 What You Should See in Task 2 Logs

### When WordPress Sends a Compression Request:

**1. Request Received**
```
[2024-11-21 16:14:09] [COMPRESS] Request received | {"method":"POST","uri":"/compress.php","ip":"162.158.63.89"}
```

**2. Authentication Check**
```
[2024-11-21 16:14:09] [COMPRESS] Authentication successful | {"ip":"162.158.63.89"}
```
OR if failed:
```
[2024-11-21 16:14:09] [COMPRESS] Authentication failed | {"ip":"162.158.63.89","key_provided":false}
```

**3. Request Validation**
```
[2024-11-21 16:14:09] [COMPRESS] Request validated successfully | {"postId":30566,"year":2024,"month":11}
```

**4. Job ID Generation**
```
[2024-11-21 16:14:09] [COMPRESS] Job ID generated | {"jobId":"job_30566_1732205649","postId":30566}
```

**5. Queue Operation**
```
[2024-11-21 16:14:09] [COMPRESS] Job added to Redis queue via RedisQueue class | {"jobId":"job_30566_1732205649","queue_length":1}
```

**6. Response Sent**
```
[2024-11-21 16:14:09] [COMPRESS] Success response sent | {"jobId":"job_30566_1732205649","postId":30566}
```

---

## 🔎 Troubleshooting: Logs Not Showing

### Issue 1: No compress.log file exists

**Cause:** Compress API hasn't been called yet, or WordPress isn't reaching it.

**Check:**
```bash
# Check if WordPress is actually calling the API
docker logs <container-name> | grep -i compress
docker logs <container-name> | grep "POST /index.php"
```

**Your logs show:**
```
"POST /index.php HTTP/1.1" 200 440 "-" "WordPress/6.8.3"
```

**Problem:** WordPress is calling `/index.php` instead of `/compress.php`

**Solution:** Check WordPress plugin configuration - it should POST to:
- `https://v.ogtemplate.com/compress.php` OR
- `https://v.ogtemplate.com/api/compress`

### Issue 2: Logs exist but empty

**Check permissions:**
```bash
docker exec -it <container-name> ls -la /var/www/html/logs/
```

Should show:
```
drwxr-xr-x www-data www-data compress.log
```

### Issue 3: Getting Apache logs but not application logs

**Apache logs** (what you're seeing):
```
162.158.63.89 - - [21/Nov/2025:16:14:09 +0000] "POST /index.php HTTP/1.1" 200 440
```

**Application logs** (what you need):
```
[2024-11-21 16:14:09] [COMPRESS] Request received | {"method":"POST"...
```

**Solution:** Access compress.log directly:
```bash
docker exec -it <container-name> tail -f /var/www/html/logs/compress.log
```

---

## 🧪 Testing Task 2 Manually

### From WordPress:
Trigger a video upload and check if it calls the compression API.

### From Command Line:
```bash
# Test with curl
curl -X POST https://v.ogtemplate.com/compress.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "postId": 99999,
    "wpMediaPath": "/wp-content/uploads/2024/11/test.mp4",
    "wpThumbnailPath": "/wp-content/uploads/2024/11/test.jpg",
    "year": 2024,
    "month": 11
  }'
```

**Expected Response:**
```json
{
  "status": "success",
  "message": "Compression job queued successfully",
  "jobId": "job_99999_1732205649",
  "postId": 99999
}
```

Then check logs:
```bash
docker exec -it <container-name> cat /var/www/html/logs/compress.log
```

---

## 📊 Quick Verification Checklist

After deployment, verify Task 2 is working:

- [ ] Compress API file exists: `/var/www/html/compress.php`
- [ ] Config has API key: Check `/check-compress-api.php`
- [ ] Redis is connected: Check `/redis-health.php`
- [ ] WordPress can reach API: Test from WordPress plugin
- [ ] Logs are being created: Check `/view-logs.php`
- [ ] Jobs are queued: Check Redis queue length

---

## 🎯 Summary

**Task 2 logs are NOT showing in your deployment logs because:**

1. ❌ WordPress is calling `/index.php` not `/compress.php`
2. ❌ Application logs (`compress.log`) are separate from Apache access logs
3. ❌ You need to access logs inside the container, not just Docker logs

**Use these new tools to verify Task 2:**
- ✅ `https://v.ogtemplate.com/check-compress-api.php` - Check API status
- ✅ `https://v.ogtemplate.com/view-logs.php` - View actual compress logs
- ✅ `docker exec` commands - Access logs directly

**Next: Fix WordPress plugin to call correct endpoint!**
