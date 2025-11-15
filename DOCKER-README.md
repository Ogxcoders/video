# Docker Deployment for VPS API

Quick guide to deploy the VPS API using Docker with your custom domain `https://post.trendss.net/`.

## 🚀 Quick Start

### 1. Clone or Upload Files

Upload the `vps-api` folder to your VPS:

```bash
cd /home/your-user
mkdir vps-api-deploy
cd vps-api-deploy
# Upload all vps-api files here
```

### 2. Generate Secure Keys

```bash
# Generate API Key (64 characters)
openssl rand -hex 32

# Generate Admin Password
openssl rand -base64 24
```

### 3. Edit docker-compose.yml

Open `docker-compose.yml` and update these values:

```yaml
environment:
  API_KEY: "paste-your-64-char-key-here"
  ADMIN_PASSWORD: "paste-your-secure-password-here"
  BASE_URL: "https://post.trendss.net"
  HLS_URL_BASE: "https://post.trendss.net/hls"
  ALLOWED_ORIGINS: "https://your-wordpress-site.com"
```

**Everything is in this one file!** No need for separate .env files.

### 4. Build and Run with Docker Compose

```bash
docker-compose up -d
```

This will:
- ✅ Build the Docker image
- ✅ Install FFmpeg automatically
- ✅ Create necessary directories
- ✅ Start the API server
- ✅ Map to port 80

### 5. Verify It's Running

```bash
docker-compose ps
docker-compose logs -f
```

### 6. Test the API

```bash
curl http://localhost/test-connection.php
```

## 🔧 Coolify Deployment

### Method 1: Using Dockerfile

1. **In Coolify Dashboard**:
   - Click **+ New Resource**
   - Choose **Dockerfile**
   - Connect your Git repo or upload files
   - Set port: `80`

2. **Add Environment Variables** (in Coolify):
   ```
   API_KEY=your-key
   ADMIN_PASSWORD=your-password
   BASE_URL=https://post.trendss.net
   HLS_URL_BASE=https://post.trendss.net/hls
   ALLOWED_ORIGINS=https://your-wordpress-site.com
   ```

3. **Configure Domain**:
   - Go to **Domains** section
   - Add: `post.trendss.net`
   - Enable HTTPS (auto SSL)
   - Save

4. **Add Persistent Volumes**:
   - `/var/www/html/videos`
   - `/var/www/html/hls`
   - `/var/www/html/logs`

5. **Deploy**:
   - Click **Deploy**
   - Wait for build (2-3 minutes)
   - Check logs for success

### Method 2: Using Docker Compose in Coolify

1. Upload `docker-compose.yml` to Coolify
2. Set environment variables in Coolify UI
3. Configure domain and volumes
4. Deploy

## 📁 Important Files

- `Dockerfile` - Container build instructions
- `docker-compose.yml` - Local development setup
- `.env.example` - Environment variable template
- `.dockerignore` - Files to exclude from build
- `COOLIFY-DEPLOYMENT.md` - Detailed Coolify guide

## 🔍 Testing Your Deployment

### Test API Endpoint

```bash
curl -X POST https://post.trendss.net/index.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{"video_url": "https://example.com/video.mp4", "post_id": 123}'
```

### Access Dashboard

Visit: `https://post.trendss.net/dashboard.php`

## 🛠️ Useful Docker Commands

### View Logs
```bash
docker-compose logs -f
```

### Restart Container
```bash
docker-compose restart
```

### Stop Container
```bash
docker-compose down
```

### Rebuild After Changes
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Access Container Shell
```bash
docker-compose exec vps-api bash
```

### Check FFmpeg Installation
```bash
docker-compose exec vps-api ffmpeg -version
```

## 🌐 DNS Configuration

Point your domain to your VPS:

```
Type: A
Name: post
Value: YOUR_VPS_IP
TTL: 3600
```

## 🔒 Security Checklist

- ✅ Use strong, unique API key (64+ chars)
- ✅ Use strong admin password (24+ chars)
- ✅ Enable HTTPS via Coolify or reverse proxy
- ✅ Restrict ALLOWED_ORIGINS to your WordPress domain only
- ✅ Keep Docker images updated
- ✅ Backup volumes regularly

## 📊 Monitoring

### Check Container Health
```bash
docker-compose ps
```

### Check Resource Usage
```bash
docker stats
```

### View API Logs
```bash
docker-compose exec vps-api tail -f /var/www/html/logs/api.log
```

## 🆘 Troubleshooting

### Port Already in Use

Edit `docker-compose.yml` and change:
```yaml
ports:
  - "8080:80"  # Change 8080 to another port like 9090
```

### Permission Errors

The Dockerfile already handles permissions, but if you encounter issues:
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### FFmpeg Not Found

This shouldn't happen as Dockerfile installs it, but verify:
```bash
docker-compose exec vps-api which ffmpeg
docker-compose exec vps-api ffmpeg -version
```

### Container Keeps Restarting

Check logs:
```bash
docker-compose logs
```

Common causes:
- Missing environment variables
- API_KEY not set or using default
- Port conflict

## 📦 What's Inside the Container

- **PHP 8.1** with Apache
- **FFmpeg** (latest from Debian repos)
- **curl** extension for PHP
- **mod_rewrite, mod_headers, mod_expires** enabled
- All your PHP files in `/var/www/html/`

## 🔄 Updating Your Code

1. Make changes to your PHP files
2. Rebuild and restart:
```bash
docker-compose down
docker-compose build
docker-compose up -d
```

Or in Coolify:
- Just click **Redeploy**

## 💡 Tips

1. **Local Testing**: Use `docker-compose.yml` to test locally before deploying to Coolify
2. **Volumes**: The docker-compose setup maps local folders, so processed videos persist
3. **Logs**: Always check logs if something isn't working
4. **Coolify Auto-Deploy**: Connect Git repo in Coolify for automatic deployments on push

## 📚 Additional Documentation

- See `COOLIFY-DEPLOYMENT.md` for detailed Coolify setup
- See `DEPLOYMENT-GUIDE.md` for traditional VPS deployment
- See `README.md` for feature overview

## ✅ Deployment Checklist

- [ ] Files uploaded to VPS or Git repo connected
- [ ] `.env` file created with secure keys
- [ ] Domain DNS configured (post.trendss.net → VPS IP)
- [ ] Docker/Coolify environment variables set
- [ ] Persistent volumes configured
- [ ] HTTPS enabled in Coolify
- [ ] Container deployed and running
- [ ] API endpoint tested
- [ ] WordPress plugin connected and tested
- [ ] Dashboard accessible

---

**You're all set!** Your VPS API will automatically install FFmpeg and all dependencies when deployed. 🎉
