# VPS-API Complete Endpoint Reference

**Base URL:** `https://v.ogtemplate.com`

---

## 📋 Quick Access

**Homepage (API Documentation):**  
🔗 `https://v.ogtemplate.com/` or `https://v.ogtemplate.com/index.php`

Returns JSON with all available endpoints and their documentation.

---

## 🏥 Health & Monitoring Endpoints

### 1. General Health Check
**URL:** `https://v.ogtemplate.com/health.php`  
**Method:** GET  
**Auth:** None  
**Description:** Basic server health check - returns uptime and status

**Example:**
```bash
curl https://v.ogtemplate.com/health.php
```

---

### 2. Redis Health Check  
**URL:** `https://v.ogtemplate.com/redis-health.php`  
**Method:** GET  
**Auth:** None  
**Description:** Detailed Redis server status, version, memory usage, and queue statistics

**Example:**
```bash
curl https://v.ogtemplate.com/redis-health.php
```

**Response:**
```json
{
  "status": "healthy",
  "redis": {
    "ping": "PONG",
    "version": "8.0.2",
    "uptime_seconds": 3600,
    "used_memory": "12.5M"
  },
  "queue": {
    "connected": true,
    "pending_jobs": 0,
    "processing_jobs": 0,
    "completed_jobs": 12
  }
}
```

---

### 3. Compress API Health  
**URL:** `https://v.ogtemplate.com/check-compress-api.php`  
**Method:** GET  
**Auth:** None  
**Description:** Compression API configuration status, logs, and Redis connection

**Example:**
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
  "redis": {
    "connected": true
  },
  "logs": {
    "directory_exists": true,
    "files": [...]
  }
}
```

---

## 🎯 API Endpoints (Task 2)

### 4. Compression API (Primary)
**URL:** `https://v.ogtemplate.com/compress.php`  
**Method:** POST  
**Auth:** Required (X-API-Key header)  
**Description:** Queue video compression jobs - receives from WordPress plugin

**Headers:**
```
Content-Type: application/json
X-API-Key: YOUR_API_KEY
```

**Request Body:**
```json
{
  "postId": 30566,
  "wpMediaPath": "/wp-content/uploads/2024/11/video.mp4",
  "wpThumbnailPath": "/wp-content/uploads/2024/11/thumb.jpg",
  "year": 2024,
  "month": 11
}
```

**Example:**
```bash
curl -X POST https://v.ogtemplate.com/compress.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "postId": 30566,
    "wpMediaPath": "/wp-content/uploads/2024/11/video.mp4",
    "wpThumbnailPath": "/wp-content/uploads/2024/11/thumb.jpg",
    "year": 2024,
    "month": 11
  }'
```

**Success Response:**
```json
{
  "status": "success",
  "message": "Compression job queued successfully",
  "jobId": "job_30566_1732205649",
  "postId": 30566,
  "queuedAt": "2024-11-21 16:14:09",
  "year": 2024,
  "month": 11
}
```

**Error Responses:**
- `401` - Invalid or missing API key
- `400` - Missing required fields or invalid data
- `500` - Server error

---

### 5. Video Processing API (Legacy)
**URL:** `https://v.ogtemplate.com/index.php`  
**Method:** POST  
**Auth:** Required (X-API-Key header)  
**Description:** Legacy video processing endpoint

**Request Body:**
```json
{
  "video_url": "https://example.com/video.mp4",
  "post_id": 12345
}
```

---

## 📊 Logging & Monitoring

### 6. View Application Logs
**URL:** `https://v.ogtemplate.com/view-logs.php`  
**Method:** GET  
**Auth:** None  
**Description:** View compress.log, redis-queue.log, and worker.log entries

**Parameters:**
- `lines` - Number of lines to show (default: 50, max: 500)
- `type` - Log type: compress, redis_queue, worker, or all (default)

**Examples:**
```bash
# View last 50 lines of all logs
curl https://v.ogtemplate.com/view-logs.php

# View last 100 lines
curl https://v.ogtemplate.com/view-logs.php?lines=100

# View only compress logs
curl https://v.ogtemplate.com/view-logs.php?type=compress

# View redis queue logs with 200 lines
curl https://v.ogtemplate.com/view-logs.php?type=redis_queue&lines=200
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

---

## 🧪 Testing & Utilities

### 7. Test Connection
**URL:** `https://v.ogtemplate.com/test-connection.php`  
**Method:** GET  
**Auth:** None  
**Description:** Test basic connectivity and server response

**Example:**
```bash
curl https://v.ogtemplate.com/test-connection.php
```

---

### 8. Test Compress API (CLI)
**File:** `/var/www/html/test-compress.php`  
**Method:** CLI only  
**Description:** Test script for compression API endpoint

**Usage:**
```bash
docker exec -it <container-name> php /var/www/html/test-compress.php
```

---

### 9. Test Redis Queue (CLI)
**File:** `/var/www/html/test-redis-queue.php`  
**Method:** CLI only  
**Description:** Comprehensive Redis queue test (12 test steps)

**Usage:**
```bash
docker exec -it <container-name> php /var/www/html/test-redis-queue.php
```

---

## 🔧 Admin Utilities

### 10. Setup
**URL:** `https://v.ogtemplate.com/setup.php`  
**Method:** GET  
**Description:** Initial setup and configuration verification

---

### 11. Dashboard
**URL:** `https://v.ogtemplate.com/dashboard.php`  
**Method:** GET  
**Description:** Admin dashboard (if available)

---

### 12. Cleanup
**URL:** `https://v.ogtemplate.com/cleanup.php`  
**Method:** GET/POST  
**Auth:** Required  
**Description:** Clean up old files and temporary data

---

## 📚 Quick Reference Card

| Endpoint | Purpose | Auth |
|----------|---------|------|
| `/` or `/index.php` | Homepage & API docs (GET) | No |
| `/health.php` | General health check | No |
| `/redis-health.php` | Redis status & queue stats | No |
| `/check-compress-api.php` | Compress API status | No |
| `/compress.php` | Queue compression jobs | Yes |
| `/view-logs.php` | View application logs | No |
| `/test-connection.php` | Test connectivity | No |

---

## 🎯 Most Useful Endpoints

**For Monitoring:**
1. `https://v.ogtemplate.com/redis-health.php` - Check Redis & queues
2. `https://v.ogtemplate.com/check-compress-api.php` - Check API status
3. `https://v.ogtemplate.com/view-logs.php?lines=100` - View recent logs

**For Testing:**
1. `https://v.ogtemplate.com/health.php` - Basic health
2. `https://v.ogtemplate.com/test-connection.php` - Connectivity

**For Development:**
1. `https://v.ogtemplate.com/` - Full API documentation
2. `https://v.ogtemplate.com/compress.php` - Main API endpoint

---

## 📖 Documentation Files

- `TASKLIST.md` - Complete task list and progress
- `REDIS-SETUP.md` - Redis configuration guide
- `TASK2-LOG-VERIFICATION.md` - How to view Task 2 logs
- `TASK3-COMPLETION-SUMMARY.md` - Task 3 completion details
- `API-ENDPOINTS.md` - This file
