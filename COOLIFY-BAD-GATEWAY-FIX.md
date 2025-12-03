# 🚨 Coolify "Bad Gateway" Quick Fix Guide

If you're seeing "Bad Gateway" or "502 Bad Gateway" when accessing your domain, follow these steps:

## ⚡ Quick Fix (5 Minutes)

### Step 1: Configure Health Check in Coolify

This fixes 90% of "Bad Gateway" errors.

1. Open your Coolify dashboard
2. Go to your VPS API resource
3. Click on **Health Check** section
4. Configure as follows:
   ```
   Health Check Enabled: YES
   Health Check Path: /health.php
   Health Check Port: 80
   Health Check Method: GET
   Health Check Interval: 30s
   Health Check Timeout: 10s
   Health Check Retries: 3
   ```
5. Click **Save**

### Step 2: Verify Container Port

1. In Coolify, go to **Network** or **Ports** section
2. Ensure settings are:
   ```
   Container Port: 80
   Public Port: (leave empty or 443)
   ```
3. **IMPORTANT**: Do NOT use `80:80` port mapping - let Coolify handle it
4. Click **Save**

### Step 3: Redeploy

1. Click **Redeploy** button in Coolify
2. Wait 2-3 minutes for deployment to complete
3. Check logs for "Apache configured -- resuming normal operations"

### Step 4: Test

```bash
# Test the health endpoint (should return immediately)
curl https://post.trendss.net/health.php

# Expected response:
{"status":"ok","timestamp":1699999999,"service":"vps-api"}
```

✅ If you get this response, your deployment is working!

---

## 🔍 Still Not Working? Advanced Troubleshooting

### Check Container Logs

In Coolify:
1. Go to your resource → **Logs** tab
2. Look for errors:
   - ✅ "Apache/2.4.65 configured -- resuming normal operations" = Good
   - ❌ "curl: (7) Failed to connect" = Health check failing
   - ❌ "Permission denied" = File permissions issue

### Test Health Endpoint Inside Container

SSH into your VPS and run:

```bash
# Find your container name
docker ps | grep vps-api

# Test health endpoint from inside the container
docker exec -it <container-name> curl http://localhost/health.php

# Should return: {"status":"ok","timestamp":...}
```

### Check Traefik (Coolify's Reverse Proxy)

```bash
# View Traefik logs
docker logs -f coolify-proxy

# Look for errors like:
# - "backend not found"
# - "service not available"
# - "connection refused"
```

### Verify Environment Variables

Make sure these are set in Coolify:
```
API_KEY: <your-64-char-key>  (NOT "CHANGE_THIS...")
ADMIN_PASSWORD: <your-secure-password>
BASE_URL: https://post.trendss.net
HLS_URL_BASE: https://post.trendss.net/hls
ALLOWED_ORIGINS: https://your-wordpress-site.com
FFMPEG_PATH: /usr/bin/ffmpeg
PARALLEL_LIMIT: 1
```

### Force Rebuild

If nothing else works:
1. In Coolify, click **Force Rebuild**
2. This rebuilds the Docker image from scratch
3. Wait 2-3 minutes (FFmpeg installation takes time)
4. Check logs during build

---

## 🎯 Why This Happens

**Root Cause**: Coolify uses Traefik as a reverse proxy. Traefik needs to:
1. Know which port your container is listening on (port 80)
2. Verify your container is healthy via health check
3. Route traffic from your domain to your container

**The Problem**: 
- If the health check fails, Traefik marks your container as unhealthy
- Traefik won't route traffic to unhealthy containers
- Result: "Bad Gateway" error

**The Solution**:
- `/health.php` endpoint returns HTTP 200 without requiring authentication
- This allows Traefik to successfully verify your container is healthy
- Traefik then routes traffic to your container
- Your domain works! 🎉

---

## ✅ Checklist

Use this to verify your deployment:

- [ ] Health check path set to `/health.php`
- [ ] Container port set to `80`
- [ ] No host port mapping (let Coolify handle it)
- [ ] Environment variables configured (especially API_KEY)
- [ ] Domain DNS points to VPS IP
- [ ] Container logs show Apache running
- [ ] `curl https://post.trendss.net/health.php` returns `{"status":"ok"...}`
- [ ] SSL certificate generated (wait 2-5 minutes after first deploy)

---

## 📞 Still Need Help?

1. Check the full deployment guide: `COOLIFY-DEPLOYMENT.md`
2. Review Docker documentation: `DOCKER-README.md`
3. SSH into your VPS and run the diagnostic commands above
4. Share your container logs with the support team

---

**Total Fix Time**: Usually 5 minutes or less! 🚀
