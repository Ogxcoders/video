# ⚡ Quick Start - 3 Steps to Deploy

Everything you need is in **one file**: `docker-compose.yml`

## Step 1: Generate Your Keys (30 seconds)

```bash
# Generate API Key
openssl rand -hex 32

# Generate Admin Password
openssl rand -base64 24
```

Copy these values - you'll need them in the next step.

## Step 2: Edit docker-compose.yml (1 minute)

Open `docker-compose.yml` and replace these values:

```yaml
environment:
  API_KEY: "PASTE_YOUR_64_CHAR_KEY_HERE"
  ADMIN_PASSWORD: "PASTE_YOUR_PASSWORD_HERE"
  BASE_URL: "https://post.trendss.net"
  HLS_URL_BASE: "https://post.trendss.net/hls"
  ALLOWED_ORIGINS: "https://your-wordpress-site.com"
```

That's it! Everything else is already configured.

## Step 3: Deploy to Coolify (2 minutes)

### In Coolify Dashboard:

1. Click **+ New Resource**
2. Choose **Docker Compose**
3. Upload your edited `docker-compose.yml`
4. Go to **Domains** → Add `post.trendss.net`
5. Enable **HTTPS**
6. Click **Deploy**

### Wait for deployment:
- Build takes 2-3 minutes (installing FFmpeg)
- Check logs for success
- Done! ✅

## Your API is Ready!

Access your deployed API:

- **API Endpoint**: `https://post.trendss.net/index.php`
- **Dashboard**: `https://post.trendss.net/dashboard.php`
- **Test**: `https://post.trendss.net/test-connection.php`

## Connect WordPress

In WordPress admin:

1. Go to **Video Processor → Settings**
2. API Endpoint: `https://post.trendss.net/index.php`
3. API Key: (the one you generated)
4. Click **Test Connection**
5. Save Changes

**Done!** Start processing videos.

---

## What Gets Auto-Installed

When you deploy, Docker automatically installs:

- ✅ PHP 8.1 with Apache
- ✅ FFmpeg (for video processing)
- ✅ All PHP extensions
- ✅ SSL certificate (via Coolify)
- ✅ Persistent storage for videos/HLS/logs

**No manual installation needed!**

---

## Troubleshooting

### Build fails?
- Check you replaced the API_KEY in docker-compose.yml
- Make sure it's not still `CHANGE_THIS_...`

### Can't access domain?
- Wait 2-3 minutes for SSL certificate
- Check DNS points to your VPS IP

### Dashboard login fails?
- Use the ADMIN_PASSWORD you set in docker-compose.yml

---

## Need More Help?

See detailed guides:
- **COOLIFY-DEPLOYMENT.md** - Complete Coolify guide
- **DOCKER-README.md** - Docker details
- **README.md** - Feature overview

---

**Total time: ~5 minutes from zero to deployed API!** 🚀
