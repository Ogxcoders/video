# 🚀 Coolify Deployment Instructions - Updated

## ✅ Code Changes Made

I've added the following fixes directly to the code:

### 1. **docker-compose.yml** - Added Traefik Labels
- Automatic routing configuration for `post.trendss.net`
- HTTPS with Let's Encrypt SSL
- HTTP to HTTPS redirect
- Proper port exposure (80)
- Connected to Coolify's external network

### 2. **index.html** - Default Landing Page
- Professional landing page when visiting root URL
- Shows service status and available endpoints
- Prevents "404 Not Found" errors

### 3. **Dockerfile** - Enhanced Apache Config
- DirectoryIndex set to serve index.html by default
- Apache already listens on port 80 by default (from base image)
- Reverse proxy configuration included
- Trust proxy headers for proper client IP detection

### 4. **.htaccess** - Improved Routing
- Allow static files (HTML, CSS, JS, images)
- Proper DirectoryIndex configuration
- Don't rewrite existing files

### 5. **.coolify.yml** - Coolify Configuration
- Tells Coolify exactly how to deploy
- Traefik labels for automatic routing
- Health check configuration
- Network and port settings

## 📋 Deployment Steps

### Step 1: Push Code to GitHub

```bash
cd vps-api
git add .
git commit -m "Add Coolify routing and configuration fixes"
git push origin main
```

### Step 2: Redeploy in Coolify

1. Go to Coolify dashboard
2. Find your `ogvid` deployment
3. Click **Redeploy** or **Force Rebuild**
4. Wait 2-3 minutes for deployment

### Step 3: Verify Deployment

Once deployed, test these URLs:

```bash
# 1. Test root page (should show landing page)
curl https://post.trendss.net/

# 2. Test health endpoint
curl https://post.trendss.net/health.php

# 3. Test in browser
# Open: https://post.trendss.net/
```

## 🎯 What This Fixes

### Before:
- ❌ Bad Gateway error
- ❌ Traefik couldn't route to container
- ❌ Manual Coolify configuration required

### After:
- ✅ Automatic routing via docker-compose labels
- ✅ Professional landing page at root URL
- ✅ Proper health checks
- ✅ HTTPS with auto SSL
- ✅ No manual Coolify UI configuration needed

## 🔍 How It Works

1. **Traefik Labels** in docker-compose.yml tell Coolify's reverse proxy:
   - Route `post.trendss.net` to this container
   - Use port 80 inside the container
   - Enable HTTPS with Let's Encrypt
   - Redirect HTTP → HTTPS

2. **Coolify Network** ensures container is on the right network:
   - `coolify` network is external (created by Coolify)
   - Container joins this network automatically

3. **Health Check** at `/health.php`:
   - Returns HTTP 200 without authentication
   - Traefik uses this to verify container is healthy
   - Routes traffic only to healthy containers

4. **Default Page** at root:
   - `index.html` serves as landing page
   - Shows service status and available endpoints
   - Professional appearance

## 🛠️ If Still Getting Bad Gateway

If you still see "Bad Gateway" after deploying:

### Check 1: Container Logs
```bash
# In Coolify, view logs and look for:
# ✅ "Apache configured -- resuming normal operations"
# ✅ "GET /health.php HTTP/1.1" 200
```

### Check 2: DNS
```bash
# Ensure DNS points to your VPS
nslookup post.trendss.net
# Should return your VPS IP
```

### Check 3: SSL Certificate
- Wait 2-5 minutes for Let's Encrypt to generate SSL
- Try HTTP first: `http://post.trendss.net/health.php`

### Check 4: Traefik Logs
```bash
# SSH into VPS and check Traefik
docker logs coolify-proxy 2>&1 | tail -50
# Look for routing errors
```

## 📊 Expected Results

After successful deployment:

1. **https://post.trendss.net/** → Shows landing page
2. **https://post.trendss.net/health.php** → `{"status":"ok",...}`
3. **https://post.trendss.net/dashboard.php** → Dashboard login
4. **https://post.trendss.net/test-connection.php** → API test (needs key)

## 🎉 Success Indicators

You'll know it's working when:

- ✅ No more "Bad Gateway" error
- ✅ Landing page loads at root URL
- ✅ Health endpoint returns JSON
- ✅ HTTPS works with green padlock
- ✅ Coolify shows container as "healthy"

---

**Everything is now in the code - just push and redeploy!** 🚀
