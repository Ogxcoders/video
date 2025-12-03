# Apache VPS Setup Fix - v.ogtemplate.com

## Issues Identified

1. **Files returning 404 Not Found** - Media files (videos, thumbnails, HLS) stored in `/media/content/` are not publicly accessible
2. **Thumbnail URLs not visible** - URLs are correct but files can't be accessed
3. **HLS files path** - Already using correct `/content/{YYYY}/{MM}/{POST_ID}/hls/` path structure

## Root Cause

The URL path `/content/...` needs to map to `/var/www/html/media/content/...` on the server. This requires either:
- Apache Alias directive (recommended)
- Properly functioning mod_rewrite rules
- Symbolic link

## Quick Fix Steps

### Step 1: SSH into your VPS
```bash
ssh root@v.ogtemplate.com
```

### Step 2: Enable Required Apache Modules
```bash
sudo a2enmod rewrite headers expires mime alias
```

### Step 3: Create/Update Apache VirtualHost

Edit your site configuration:
```bash
sudo nano /etc/apache2/sites-available/v.ogtemplate.com.conf
```

Add/update with this configuration:
```apache
<VirtualHost *:80>
    ServerName v.ogtemplate.com
    ServerAlias www.v.ogtemplate.com
    
    DocumentRoot /var/www/html
    
    # CRITICAL: Map /content/ URL to /media/content/ directory
    Alias /content /var/www/html/media/content
    
    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    <Directory /var/www/html/media/content>
        Options -Indexes +FollowSymLinks
        Require all granted
        AllowOverride All
        
        Header set Access-Control-Allow-Origin "*"
        Header set Access-Control-Allow-Methods "GET, HEAD, OPTIONS"
    </Directory>
    
    <Directory /var/www/html/media/uploads>
        Options -Indexes +FollowSymLinks
        Require all granted
        AllowOverride All
    </Directory>

    # MIME types
    AddType application/vnd.apple.mpegurl .m3u8
    AddType video/MP2T .ts
    AddType video/mp4 .mp4
    AddType video/webm .webm
    AddType image/webp .webp
    
    ErrorLog ${APACHE_LOG_DIR}/v.ogtemplate.com-error.log
    CustomLog ${APACHE_LOG_DIR}/v.ogtemplate.com-access.log combined
</VirtualHost>
```

### Step 4: Set Correct Permissions
```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/html/media

# Set permissions
sudo chmod -R 755 /var/www/html/media

# Ensure content directory exists and is accessible
sudo mkdir -p /var/www/html/media/content
sudo mkdir -p /var/www/html/media/uploads
sudo chmod -R 755 /var/www/html/media/content
sudo chmod -R 755 /var/www/html/media/uploads
```

### Step 5: Enable Site and Restart Apache
```bash
sudo a2ensite v.ogtemplate.com.conf
sudo apache2ctl configtest
sudo systemctl restart apache2
```

### Step 6: Test File Access
```bash
# Test a content URL (replace with actual path)
curl -I https://v.ogtemplate.com/content/2024/11/12345/thumbnail.webp

# Expected: HTTP 200 OK
```

## Alternative Fix: Symbolic Link

If you can't modify VirtualHost, create a symbolic link:
```bash
cd /var/www/html
ln -s media/content content
```

## Verification Steps

1. **Check if media directories exist:**
   ```bash
   ls -la /var/www/html/media/
   ls -la /var/www/html/media/content/
   ```

2. **Check Apache configuration:**
   ```bash
   sudo apache2ctl configtest
   ```

3. **Check Apache error logs:**
   ```bash
   tail -f /var/log/apache2/v.ogtemplate.com-error.log
   ```

4. **Test direct file access:**
   ```bash
   # Find a processed file
   find /var/www/html/media/content -name "*.webp" | head -1
   
   # Test URL access
   curl -I https://v.ogtemplate.com/content/[path-from-above]
   ```

## URL Format Reference

| Content Type | URL Format |
|--------------|------------|
| Thumbnail    | `https://v.ogtemplate.com/content/{YYYY}/{MM}/{POST_ID}/thumbnail.webp` |
| 480p Video   | `https://v.ogtemplate.com/content/{YYYY}/{MM}/{POST_ID}/compressed_480p.mp4` |
| HLS Master   | `https://v.ogtemplate.com/content/{YYYY}/{MM}/{POST_ID}/hls/master.m3u8` |
| HLS 480p     | `https://v.ogtemplate.com/content/{YYYY}/{MM}/{POST_ID}/hls/480p.m3u8` |

## After Fix - What to Expect

1. Thumbnail WebP URLs will be accessible and visible in WordPress
2. Video MP4 files will play correctly
3. HLS streams will work for adaptive bitrate playback
4. All existing processed files will become accessible without reprocessing
