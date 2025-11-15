# Coolify Deployment Guide for VPS API

This guide will help you deploy the VPS API to your Coolify instance with the custom domain `https://post.trendss.net/`.

**🎯 CRITICAL FOR COOLIFY**: This deployment includes a `/health.php` endpoint specifically designed for Coolify's reverse proxy health checks. If you're getting "Bad Gateway" errors, this guide will help you fix them.

## Prerequisites

- Coolify instance running on your VPS
- Docker installed (Coolify handles this)
- Custom domain `post.trendss.net` pointing to your VPS IP
- Access to Coolify dashboard

## Step 1: Prepare Your Files

Ensure all files from the `vps-api` folder are ready:
- Dockerfile
- All PHP files (index.php, config.php, VideoProcessor.php, etc.)
- .dockerignore
- docker-compose.yml (for reference)

## Step 2: Add New Resource in Coolify

1. Login to your Coolify dashboard
2. Go to your project
3. Click **+ New Resource**
4. Select **Docker Compose** or **Dockerfile**

### Option A: Using Dockerfile (Recommended for GitHub)

1. Choose **Dockerfile** deployment
2. Set **Build Pack**: Dockerfile
3. Set **Base Directory**: `/vps-api` (if using Git) or leave empty if root
4. Set **Dockerfile Location**: `Dockerfile`
5. **CRITICAL**: Set **Port**: `80` (Container Port)
6. **CRITICAL**: Set **Health Check Path**: `/health.php` (this prevents "Bad Gateway" errors)

### Option B: Using Docker Compose (Easiest!)

1. Edit `docker-compose.yml` with your secure keys and domain
2. Choose **Docker Compose** deployment in Coolify
3. Upload or paste the edited `docker-compose.yml` content
4. Configure domain: `post.trendss.net`
5. Deploy!

**All configuration is already in the docker-compose.yml file - no need to set environment variables separately.**

## Step 3: Configure docker-compose.yml

Before deploying, edit your `docker-compose.yml` file directly:

### Generate Secure Keys First

```bash
# Generate API Key (64 characters)
openssl rand -hex 32

# Generate Admin Password
openssl rand -base64 24
```

### Edit docker-compose.yml

Open the file and update these values:

```yaml
environment:
  API_KEY: "paste-your-64-char-api-key-here"
  ADMIN_PASSWORD: "paste-your-secure-password-here"
  BASE_URL: "https://post.trendss.net"
  HLS_URL_BASE: "https://post.trendss.net/hls"
  ALLOWED_ORIGINS: "https://your-wordpress-site.com"
  FFMPEG_PATH: "/usr/bin/ffmpeg"
  PARALLEL_LIMIT: "1"
```

**All configuration is in this one file!** No need to set environment variables in Coolify UI.

## Step 4: Configure Custom Domain

1. In Coolify resource settings, go to **Domains**
2. Click **+ Add Domain**
3. Enter: `post.trendss.net`
4. Enable **HTTPS** (Coolify will auto-generate SSL with Let's Encrypt)
5. Click **Save**

### DNS Configuration

Make sure your DNS is configured:

```
Type: A Record
Name: post
Value: YOUR_VPS_IP_ADDRESS
TTL: 3600
```

Or if using root domain:

```
Type: A Record
Name: @
Value: YOUR_VPS_IP_ADDRESS
TTL: 3600
```

## Step 5: Configure Persistent Storage

In Coolify, add persistent volumes:

1. Go to **Storage** section
2. Add volume: `/var/www/html/videos` → `/videos` (or custom path on host)
3. Add volume: `/var/www/html/hls` → `/hls`
4. Add volume: `/var/www/html/logs` → `/logs`

This ensures your processed videos persist across container restarts.

## Step 6: Deploy

1. Click **Deploy** button
2. Wait for build to complete (FFmpeg installation takes 2-3 minutes)
3. Check build logs for any errors
4. Once deployed, container should be running

## Step 7: Verify Deployment

### Test Health Endpoint (Public - No Auth)

```bash
curl https://post.trendss.net/health.php
```

Should return:
```json
{"status":"ok","timestamp":1699999999,"service":"vps-api"}
```

✅ If this works, your container and reverse proxy are configured correctly!

### Test API Connection Endpoint (Requires Auth)

```bash
curl -H "X-API-Key: YOUR_API_KEY" https://post.trendss.net/test-connection.php
```

Should return API status information with FFmpeg details.

### Test with API Key

```bash
curl -X POST https://post.trendss.net/index.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "video_url": "https://example.com/test-video.mp4",
    "post_id": 999
  }'
```

### Access Dashboard

Visit: `https://post.trendss.net/dashboard.php`

Login with your `ADMIN_PASSWORD`.

## Step 8: Connect WordPress Plugin

In your WordPress admin:

1. Go to **Video Processor → Settings**
2. Set **API Endpoint**: `https://post.trendss.net/index.php`
3. Set **API Key**: (the one you generated)
4. Click **Test API Connection**
5. Should show "Connection successful"
6. Click **Save Changes**

## ⚠️ CRITICAL: Coolify Port & Health Check Configuration

**This is the #1 cause of "Bad Gateway" errors!**

### Port Configuration

1. In Coolify, go to **Network** or **Ports** section
2. Set **Container Port**: `80`
3. Set **Public Port**: leave empty or use `443` (Coolify's Traefik handles this automatically)
4. **DO NOT** map to port `80` on host - let Coolify's reverse proxy handle it

### Health Check Configuration

**REQUIRED to prevent "Bad Gateway" errors:**

1. In Coolify, go to **Health Check** section
2. Set **Health Check Enabled**: `Yes`
3. Set **Health Check Path**: `/health.php`
4. Set **Health Check Port**: `80`
5. Set **Health Check Interval**: `30s`
6. Set **Health Check Timeout**: `10s`
7. Set **Health Check Retries**: `3`

**Why this matters**: The `/health.php` endpoint returns HTTP 200 without requiring authentication. The old `test-connection.php` endpoint requires an API key, which causes Coolify's health checks to fail, resulting in "Bad Gateway" errors.

## Troubleshooting

### 🚨 "Bad Gateway" or 502 Errors (MOST COMMON ISSUE)

**Symptoms**: Domain shows "Bad Gateway" or "502 Bad Gateway" error

**Root Cause**: Coolify's reverse proxy (Traefik) cannot reach your container or health checks are failing.

**Solution**:

1. **Check Health Check Configuration** (Fix 90% of issues):
   ```
   Go to Resource → Health Check section
   - Enable health check: YES
   - Health check path: /health.php
   - Port: 80
   - Interval: 30s
   ```

2. **Verify Container Port**:
   ```
   Go to Resource → Network/Ports section
   - Container Port: 80
   - Public Port: (leave empty or 443)
   - DO NOT use 80:80 mapping
   ```

3. **Check Container Logs**:
   ```
   Go to Resource → Logs tab
   Look for:
   - "Apache/2.4.65 configured -- resuming normal operations" ✅ Good
   - "curl: (7) Failed to connect" ❌ Health check failing
   - "Permission denied" ❌ File permission issue
   ```

4. **Test Health Endpoint Manually**:
   ```bash
   # SSH into your VPS
   docker exec -it <container-name> curl http://localhost/health.php
   
   # Should return: {"status":"ok","timestamp":...}
   ```

5. **Check Traefik Logs** (Advanced):
   ```bash
   # On your VPS
   docker logs -f coolify-proxy
   # Look for routing errors or backend connection failures
   ```

6. **Verify Environment Variables**:
   - Ensure `API_KEY` is not still set to `CHANGE_THIS...`
   - Check all required env vars are set in Coolify UI

7. **Force Rebuild**:
   - Click **Force Rebuild** in Coolify
   - Wait 2-3 minutes for FFmpeg installation
   - Check logs during build for errors

### Build Fails

**Error: "ffmpeg not found"**
- Check Dockerfile has `apt-get install -y ffmpeg`
- Rebuild the container with **Force Rebuild**

**Error: "permission denied"**
- Check Dockerfile has correct `chown` commands
- Verify volumes are writable
- SSH into VPS and check volume permissions

**Error: "no space left on device"**
- Clean up Docker: `docker system prune -a`
- Check VPS disk space: `df -h`

### Container Crashes or Restarts

Check logs in Coolify:
1. Go to your resource
2. Click **Logs** tab
3. Look for PHP errors or Apache issues

Common fixes:
- Ensure all environment variables are set
- Check API_KEY is not the default value
- Verify directories exist and are writable
- Check FFmpeg installed: `docker exec <container> ffmpeg -version`

### Domain Not Working

**SSL Certificate Issues:**
- Wait 2-5 minutes for Let's Encrypt validation
- Ensure DNS is properly configured and propagated
- Check domain is accessible via HTTP first
- Verify Coolify proxy is running: `docker ps | grep coolify-proxy`

**DNS Not Resolving:**
```bash
# Check DNS propagation
nslookup post.trendss.net

# Should point to your VPS IP
```

**404 Errors:**
- Verify `.htaccess` is in place inside container
- Check Apache mod_rewrite is enabled (Dockerfile handles this)
- Test direct endpoint: `https://post.trendss.net/health.php`

### Can't Access Dashboard

- Verify `ADMIN_PASSWORD` environment variable is set in Coolify
- Clear browser cookies/cache
- Check container logs for session errors
- Try: `https://post.trendss.net/dashboard.php` directly

### Health Check Failing

**If health check keeps failing**:

1. Check `/health.php` exists:
   ```bash
   docker exec <container> ls -la /var/www/html/health.php
   ```

2. Test health endpoint:
   ```bash
   docker exec <container> curl -v http://localhost/health.php
   ```

3. Check Apache is running:
   ```bash
   docker exec <container> ps aux | grep apache
   ```

4. Verify .htaccess allows health.php:
   ```bash
   docker exec <container> cat /var/www/html/.htaccess
   # Should have: RewriteCond %{REQUEST_URI} health\.php$
   ```

## Monitoring

### View Container Logs

In Coolify dashboard:
- Click on your resource
- Go to **Logs** tab
- Real-time logs appear here

### Check Container Resource Usage

- Go to **Metrics** tab in Coolify
- Monitor CPU, RAM, and disk usage
- Adjust container resources if needed

## Updating Your Deployment

When you make code changes:

1. Push changes to Git (if using Git deployment)
2. In Coolify, click **Redeploy**
3. Or click **Force Rebuild** for Dockerfile changes
4. Wait for build to complete

## Scaling

If you need more processing power:

### Horizontal Scaling (Not Recommended for Video Processing)
Video processing is CPU-intensive, vertical scaling is better.

### Vertical Scaling
1. In Coolify, adjust container resources:
   - CPU Limit
   - Memory Limit
2. Increase `PARALLEL_LIMIT` environment variable (2-4 for better performance)
3. Redeploy

## Backup Strategy

### Backup Volumes

Coolify doesn't auto-backup volumes, so:

```bash
# SSH into your VPS
ssh your-vps

# Backup videos and HLS
tar -czf vps-api-backup-$(date +%Y%m%d).tar.gz \
  /path/to/coolify/volumes/videos \
  /path/to/coolify/volumes/hls

# Store backup securely
```

### Automated Backups

Add a cron job on your VPS:

```bash
# crontab -e
0 2 * * * tar -czf /backups/vps-api-$(date +\%Y\%m\%d).tar.gz /path/to/volumes && find /backups -name "vps-api-*.tar.gz" -mtime +30 -delete
```

## Security Best Practices

- ✅ Use strong API keys (64+ characters)
- ✅ Use strong admin passwords (24+ characters)
- ✅ Restrict `ALLOWED_ORIGINS` to your WordPress domain only
- ✅ Keep Coolify and container images updated
- ✅ Enable Coolify's automatic SSL certificate renewal
- ✅ Regularly check logs for suspicious activity
- ✅ Implement rate limiting in Coolify proxy if needed

## Performance Tips

1. **Increase PHP Memory**: Add to environment variables
   ```
   PHP_MEMORY_LIMIT=512M
   ```

2. **FFmpeg Preset**: Edit `VideoProcessor.php`
   - `ultrafast`: Fastest, lower quality
   - `medium`: Balanced (default)
   - `slow`: Best quality, slower

3. **Parallel Processing**: Set `PARALLEL_LIMIT=2` or `3` for faster processing
   (Only if VPS has multiple CPU cores)

## Need Help?

- Check Coolify logs first
- Review container logs
- Test API endpoint with curl
- Check WordPress connection test
- Review `/logs/api.log` in container

## Summary

Your VPS API is now deployed on Coolify with:

✅ Custom domain: `https://post.trendss.net/`  
✅ Auto-installed FFmpeg  
✅ SSL certificate (Let's Encrypt)  
✅ Persistent storage for videos/HLS  
✅ Environment-based configuration  
✅ Professional dashboard  
✅ WordPress integration ready  

Enjoy your automated video processing system!
