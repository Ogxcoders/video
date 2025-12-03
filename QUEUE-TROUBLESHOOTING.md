# Redis Queue Troubleshooting Guide

## Problem: Worker Running But No Jobs Processing

### Symptoms
- Worker shows: `Queue Statistics | {"pending_jobs":0,"processing_jobs":0}`
- Worker logs: `No job available, continuing...`
- No `[COMPRESS]` entries in logs

### Root Cause
The WordPress plugin is **NOT sending compression requests** to your VPS API endpoint.

---

## Diagnostic Steps

### 1. Check WordPress Plugin Configuration

**In WordPress Admin:**
1. Go to **Video Processor → Settings**
2. Check if **API Endpoint** is configured:
   - Should be: `https://v.ogtemplate.com/compress.php` (or your domain)
   - **NOT**: Empty or pointing to wrong URL
3. Check if **API Key** is configured:
   - Must match the `API_KEY` in your Docker environment variables

**Test Connection:**
- Click the **"Test Connection"** button in WordPress settings
- If it fails, the endpoint URL or API key is wrong

---

### 2. Verify API Endpoint is Accessible

**Test from command line:**

```bash
# Replace with your actual API key and domain
curl -X POST https://v.ogtemplate.com/compress.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY_HERE" \
  -d '{
    "video_url": "https://test.com/video.mp4",
    "post_id": 123,
    "wp_media_path": "/test/path",
    "year": 2025,
    "month": "11"
  }'
```

**Expected Response:**
- Success: `{"status":"success","jobId":"job_xxxxx"}`
- Auth Fail: `{"status":"error","message":"Unauthorized: Invalid or missing API key"}`
- Connection Fail: Connection timeout or DNS error

---

### 3. Check if Videos Are Being Uploaded

**In WordPress:**
1. Go to **Video Processor → Queue**
2. Check if any videos are listed
3. Try uploading a new video
4. Click **"Process"** on a video

**If no videos appear:**
- The plugin might not be detecting video uploads
- Check if the post type filter is correct

---

### 4. Monitor Logs in Real-Time

**On your VPS (inside Docker container):**

```bash
# Watch all logs
tail -f /var/www/html/logs/all.log

# Watch for compress requests
tail -f /var/www/html/logs/all.log | grep COMPRESS
```

**What to look for:**
- When WordPress sends a request, you should see: `[COMPRESS] Request received`
- If you don't see this, WordPress isn't sending requests

---

### 5. Check Docker Container Health

```bash
# Check if container is running
docker ps | grep vps-api

# Check container logs
docker logs vps-api --tail 100

# Check health status
docker inspect vps-api | grep -A 10 Health
```

---

## Common Fixes

### Fix 1: Configure WordPress Plugin

**Steps:**
1. WordPress Admin → **Video Processor → Settings**
2. Set **API Endpoint**: `https://v.ogtemplate.com/compress.php`
3. Set **API Key**: (copy from your Docker environment variables)
4. Click **Save Changes**
5. Click **Test Connection** - should show success

---

### Fix 2: Correct API Endpoint URL

**Common mistakes:**
- ❌ `https://v.ogtemplate.com/index.php` (wrong file)
- ❌ `https://v.ogtemplate.com/api/compress` (wrong path)
- ✅ `https://v.ogtemplate.com/compress.php` (correct)

**Note:** The endpoint is at the root, not `/api/`

---

### Fix 3: Match API Keys

**Check Docker environment:**
```bash
docker exec vps-api cat /var/www/html/config.php | grep api_key
```

**This should match** the API key in WordPress settings.

---

### Fix 4: Test Queue Manually

**Add a test job directly to Redis:**

```bash
# Enter Docker container
docker exec -it vps-api bash

# Run test script
php /var/www/html/test-worker-e2e.php
```

**Expected output:**
- Job enqueued successfully
- Worker picks up and processes the job
- Logs show processing activity

**If this works:**
- ✅ Worker and queue system are working
- ❌ Problem is WordPress not sending requests

---

## Verification Checklist

After applying fixes, verify:

- [ ] WordPress **Test Connection** succeeds
- [ ] Can see `[COMPRESS] Request received` in logs
- [ ] Queue statistics show: `pending_jobs > 0`
- [ ] Worker processes jobs: `processed_count` increases
- [ ] Jobs complete successfully in WordPress UI

---

## Still Not Working?

### Check Network Connectivity

**From WordPress server, test API:**
```bash
curl -v https://v.ogtemplate.com/health.php
```

**Should return:**
```json
{"status":"healthy","timestamp":"2025-11-23T20:00:00Z"}
```

### Check Firewall Rules
- Ensure port 80/443 is open on VPS
- Check Coolify/Traefik routing is correct
- Verify SSL certificate is valid

### Enable Debug Mode

**In WordPress plugin (temporarily):**
```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

**Check WordPress logs:**
```bash
tail -f /path/to/wordpress/wp-content/debug.log
```

---

## Quick Test Script

Save this as `test-compress-api.php` and run from command line:

```php
<?php
// Test compress API endpoint
$apiUrl = 'https://v.ogtemplate.com/compress.php';
$apiKey = 'YOUR_API_KEY_HERE';

$data = [
    'video_url' => 'https://test.com/sample.mp4',
    'post_id' => 999,
    'wp_media_path' => '/test/path',
    'year' => date('Y'),
    'month' => date('m')
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: ' . $apiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

echo "Testing API endpoint: $apiUrl\n";
echo "Sending request...\n\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "❌ Connection Error: $error\n";
    exit(1);
}

echo "HTTP Status: $httpCode\n";
echo "Response: $response\n";

if ($httpCode === 200) {
    echo "\n✅ API is working!\n";
    echo "Check logs: tail -f /var/www/html/logs/all.log\n";
} else {
    echo "\n❌ API returned error\n";
}
?>
```

**Run:**
```bash
php test-compress-api.php
```

---

## Summary

**Most likely issue:** WordPress plugin is not configured with the correct API endpoint URL or API key.

**Quick fix:**
1. Go to WordPress → Video Processor → Settings
2. Set API Endpoint to: `https://v.ogtemplate.com/compress.php`
3. Set API Key to match your Docker environment
4. Test Connection
5. Upload a video and click Process

The worker is ready and waiting - it just needs WordPress to send it jobs!
