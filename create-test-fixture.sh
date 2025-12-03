#!/bin/bash
# Create test video fixture for testing compression
# Generates a small test video using FFmpeg

set -e

echo "Creating test video fixture..."

# Create test directories
mkdir -p /var/www/media/uploads/2024/11
mkdir -p /var/www/media/content

# Generate a 5-second test video (solid color with timestamp)
ffmpeg -f lavfi -i color=c=blue:s=1280x720:d=5 \
    -vf "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:text='Test Video %{pts\:hms}':fontcolor=white:fontsize=48:x=(w-text_w)/2:y=(h-text_h)/2" \
    -c:v libx264 -preset ultrafast -crf 23 \
    -pix_fmt yuv420p \
    /var/www/media/uploads/2024/11/test-video.mp4 \
    -y 2>&1

# Set permissions
chown -R www-data:www-data /var/www/media
chmod -R 755 /var/www/media

# Get file info
FILE_SIZE=$(stat -f%z /var/www/media/uploads/2024/11/test-video.mp4 2>/dev/null || stat -c%s /var/www/media/uploads/2024/11/test-video.mp4)

echo "✓ Test video created:"
echo "  Path: /var/www/media/uploads/2024/11/test-video.mp4"
echo "  Size: $FILE_SIZE bytes"
echo "  Duration: 5 seconds"
echo ""
echo "You can now test compression with:"
echo "  curl -X POST http://localhost/compress.php \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -H 'X-API-Key: YOUR_KEY' \\"
echo "    -d '{\"postId\":99999,\"wpMediaPath\":\"/wp-content/uploads/2024/11/test-video.mp4\",\"year\":2024,\"month\":11}'"
