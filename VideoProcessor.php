<?php
/**
 * Enhanced Video Processor Class
 * Handles video and thumbnail processing with 4 quality levels
 * Supports hierarchical directory structure: /YYYY/MM/postId/
 * Version 3.0 - Production-ready with comprehensive error handling
 */

/**
 * Base exception class for video processing errors
 */
class VideoProcessingException extends Exception {
    protected $context = [];
    
    public function __construct($message, $code = 0, $context = [], Exception $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }
    
    public function getContext() {
        return $this->context;
    }
}

class DownloadException extends VideoProcessingException {}
class FFmpegException extends VideoProcessingException {}
class FileSystemException extends VideoProcessingException {}
class WebhookException extends VideoProcessingException {}

class VideoProcessor {
    
    private $config;
    private $logFile;
    
    public function __construct($config) {
        $this->config = $config;
        $this->logFile = $config['log_file'];
    }
    
    /**
     * Process video and thumbnail with partial success support
     * 
     * @param string $videoUrl - Video URL to download
     * @param string $thumbnailUrl - Thumbnail URL to download
     * @param int $postId - WordPress post ID
     * @return array - Result with all URLs or error (supports partial success)
     */
    public function processVideo($videoUrl, $thumbnailUrl, $postId) {
        $errors = [];
        $warnings = [];
        $result = [
            'status' => 'pending',
            'post_id' => $postId,
            'thumbnails' => null,
            'compressed_mp4s' => null,
            'hls_playlists' => null,
            'master_playlist' => null
        ];
        
        try {
            $this->log("Processing post {$postId}: video={$videoUrl}, thumbnail={$thumbnailUrl}");
            
            // Create output directory structure
            $directories = $this->createOutputDirectory($postId);
            $outputDir = $directories['path'];
            $publicUrl = $directories['url'];
            
            // STEP 1: Process thumbnail images (non-critical, allow failure)
            $this->log("Step 1/5: Processing thumbnail");
            try {
                $result['thumbnails'] = $this->processThumbnail($thumbnailUrl, $outputDir, $publicUrl);
            } catch (Exception $e) {
                $warnings[] = "Thumbnail processing failed: " . $e->getMessage();
                $this->log("Thumbnail processing failed but continuing: " . $e->getMessage(), 'WARNING');
            }
            
            // STEP 2: Download original video (CRITICAL - must succeed)
            $this->log("Step 2/5: Downloading original video");
            $originalVideo = $this->downloadFileWithRetry($videoUrl, $outputDir . '/original.mp4');
            
            // STEP 3: Create 4 compressed MP4 files (CRITICAL - but can have partial success)
            $this->log("Step 3/5: Creating compressed MP4s");
            $compressedMP4s = [];
            $qualities = $this->config['video_qualities'];
            
            foreach ($qualities as $qualityName => $settings) {
                try {
                    $outputFile = $outputDir . '/compressed_' . $qualityName . '.mp4';
                    $this->compressVideo($originalVideo, $outputFile, $settings, $qualityName);
                    $compressedMP4s[$qualityName] = $publicUrl . '/compressed_' . $qualityName . '.mp4';
                } catch (Exception $e) {
                    $warnings[] = "Failed to create {$qualityName} MP4: " . $e->getMessage();
                    $this->log("Failed to create {$qualityName} but continuing: " . $e->getMessage(), 'WARNING');
                }
            }
            
            if (empty($compressedMP4s)) {
                throw new FFmpegException("All video compression attempts failed");
            }
            
            $result['compressed_mp4s'] = $compressedMP4s;
            
            // STEP 4: Convert each MP4 to HLS (can have partial success)
            $this->log("Step 4/5: Converting to HLS");
            $hlsPlaylists = [];
            
            foreach ($compressedMP4s as $qualityName => $mp4Url) {
                try {
                    $mp4File = $outputDir . '/compressed_' . $qualityName . '.mp4';
                    if (file_exists($mp4File)) {
                        $this->convertMP4ToHLS($mp4File, $qualityName, $outputDir);
                        $hlsPlaylists[$qualityName] = $publicUrl . '/' . $qualityName . '.m3u8';
                    }
                } catch (Exception $e) {
                    $warnings[] = "Failed to create {$qualityName} HLS: " . $e->getMessage();
                    $this->log("Failed to create {$qualityName} HLS but continuing: " . $e->getMessage(), 'WARNING');
                }
            }
            
            if (empty($hlsPlaylists)) {
                throw new FFmpegException("All HLS conversion attempts failed");
            }
            
            $result['hls_playlists'] = $hlsPlaylists;
            
            // STEP 5: Create master playlist (only if we have HLS playlists)
            $this->log("Step 5/5: Creating master playlist");
            if (!empty($hlsPlaylists)) {
                try {
                    $result['master_playlist'] = $this->createMasterPlaylist($outputDir, $publicUrl, $hlsPlaylists);
                } catch (Exception $e) {
                    throw new FFmpegException("Failed to create master playlist: " . $e->getMessage());
                }
            } else {
                throw new FFmpegException("Cannot create master playlist: no HLS renditions available");
            }
            
            // Determine final status
            if (empty($warnings)) {
                $result['status'] = 'success';
                $this->log("Processing completed successfully for post {$postId}");
            } else {
                $result['status'] = 'partial_success';
                $result['warnings'] = $warnings;
                $this->log("Processing completed with warnings for post {$postId}: " . implode(', ', $warnings), 'WARNING');
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Error processing post {$postId}: " . $e->getMessage(), 'ERROR');
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            $result['warnings'] = $warnings;
            return $result;
        }
    }
    
    /**
     * Create hierarchical output directory with validation and disk-space checks
     * Structure: /var/www/media/content/YYYY/MM/postId/
     * 
     * @param int $postId - WordPress post ID
     * @return array - Directory information
     * @throws FileSystemException
     */
    private function createOutputDirectory($postId) {
        $basePath = $this->config['media_base_path'];
        $baseUrl = $this->config['media_base_url'];
        
        // Check disk space before creating directory
        $diskFree = disk_free_space($basePath);
        $diskTotal = disk_total_space($basePath);
        
        if ($diskFree === false || $diskTotal === false) {
            throw new FileSystemException("Cannot determine disk space for: {$basePath}");
        }
        
        $diskPercent = ($diskFree / $diskTotal) * 100;
        
        // Require at least 5% free space (critical threshold)
        if ($diskPercent < 5) {
            throw new FileSystemException(
                "Insufficient disk space: only " . round($diskPercent, 2) . "% free. Processing halted.",
                0,
                ['disk_free_gb' => round($diskFree / (1024*1024*1024), 2)]
            );
        }
        
        $year = date('Y');
        $month = date('m');
        $dirPath = "{$basePath}/{$year}/{$month}/{$postId}";
        
        if (!is_dir($dirPath)) {
            if (!mkdir($dirPath, 0755, true)) {
                throw new FileSystemException("Failed to create directory: {$dirPath}");
            }
        }
        
        // Verify directory structure
        try {
            $this->verifyDirectoryStructure($dirPath);
        } catch (FileSystemException $e) {
            throw new FileSystemException("Directory validation failed: " . $e->getMessage());
        }
        
        $publicUrl = "{$baseUrl}/{$year}/{$month}/{$postId}";
        
        return [
            'path' => $dirPath,
            'url' => $publicUrl,
            'year' => $year,
            'month' => $month
        ];
    }
    
    /**
     * Process thumbnail images with proper error handling
     * - Download original (full quality)
     * - Create compressed WebP (87% quality)
     * 
     * @param string $thumbnailUrl - URL to thumbnail image
     * @param string $outputDir - Directory to save files
     * @param string $publicUrl - Public URL base
     * @return array - URLs to original and WebP thumbnails
     * @throws DownloadException, FileSystemException
     */
    private function processThumbnail($thumbnailUrl, $outputDir, $publicUrl) {
        // Download to unique temporary file
        $tempPath = tempnam($outputDir, 'thumb_');
        
        try {
            $this->downloadFile($thumbnailUrl, $tempPath);
        } catch (DownloadException $e) {
            // Clean up temp file and re-throw
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }
        
        // Detect actual MIME type from downloaded file
        $mimeType = $this->getMimeType($tempPath);
        $extension = $this->getExtensionFromMime($mimeType);
        
        // Move to proper location with correct extension
        $originalPath = $outputDir . '/original.' . $extension;
        
        // Use copy/unlink fallback if rename fails (e.g., cross-filesystem)
        if (!@rename($tempPath, $originalPath)) {
            if (!@copy($tempPath, $originalPath)) {
                @unlink($tempPath);
                throw new FileSystemException("Failed to save thumbnail to: {$originalPath}");
            }
            @unlink($tempPath);
        }
        
        // Convert to WebP
        $webpPath = $outputDir . '/thumbnail.webp';
        try {
            $this->convertToWebP($originalPath, $webpPath);
        } catch (Exception $e) {
            throw new FFmpegException("Failed to convert thumbnail to WebP: " . $e->getMessage());
        }
        
        return [
            'original' => $publicUrl . '/original.' . $extension,
            'webp' => $publicUrl . '/thumbnail.webp'
        ];
    }
    
    /**
     * Get MIME type from file
     */
    private function getMimeType($filePath) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mimeType ?: 'image/jpeg';
    }
    
    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMime($mimeType) {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];
        
        return $mimeMap[$mimeType] ?? 'jpg';
    }
    
    /**
     * Convert image to WebP format
     */
    private function convertToWebP($inputFile, $outputFile) {
        $ffmpeg = escapeshellarg($this->config['ffmpeg_binary']);
        $input = escapeshellarg($inputFile);
        $output = escapeshellarg($outputFile);
        $quality = $this->config['thumbnail_webp_quality'];
        $compression = $this->config['thumbnail_webp_compression_level'];
        
        $command = "{$ffmpeg} -y -i {$input} -c:v libwebp -quality {$quality} -compression_level {$compression} {$output} 2>&1";
        
        exec($command, $execOutput, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($outputFile)) {
            throw new Exception("WebP conversion failed: " . implode("\n", $execOutput));
        }
        
        return $outputFile;
    }
    
    /**
     * Create 4 compressed MP4 files
     */
    private function createCompressedMP4s($inputFile, $outputDir, $publicUrl) {
        $qualities = $this->config['video_qualities'];
        $result = [];
        
        foreach ($qualities as $qualityName => $settings) {
            $outputFile = $outputDir . '/compressed_' . $qualityName . '.mp4';
            
            $this->compressVideo($inputFile, $outputFile, $settings, $qualityName);
            
            $result[$qualityName] = $publicUrl . '/compressed_' . $qualityName . '.mp4';
        }
        
        return $result;
    }
    
    /**
     * Compress video to specific quality
     */
    private function compressVideo($inputFile, $outputFile, $settings, $qualityName) {
        $ffmpeg = escapeshellarg($this->config['ffmpeg_binary']);
        $input = escapeshellarg($inputFile);
        $output = escapeshellarg($outputFile);
        
        $scale = $settings['scale'];
        $bitrate = $settings['bitrate'];
        $maxrate = $settings['maxrate'];
        $bufsize = $settings['bufsize'];
        $audioBitrate = $settings['audio_bitrate'];
        $profile = $settings['profile'];
        $level = $settings['level'];
        $gopSize = $this->config['ffmpeg_gop_size'];
        $preset = $this->config['ffmpeg_preset'];
        
        // Handle special cases
        $audioSampleRate = isset($settings['audio_sample_rate']) ? $settings['audio_sample_rate'] : '44100';
        
        $command = "{$ffmpeg} -i {$input} ";
        $command .= "-vf \"scale={$scale}:flags=lanczos\" ";
        $command .= "-c:v libx264 -preset {$preset} ";
        $command .= "-profile:v {$profile} -level {$level} ";
        $command .= "-b:v {$bitrate} -maxrate {$maxrate} -bufsize {$bufsize} ";
        $command .= "-g {$gopSize} -keyint_min {$gopSize} -sc_threshold 0 ";
        $command .= "-c:a aac -b:a {$audioBitrate} -ar {$audioSampleRate} ";
        $command .= "-movflags +faststart ";
        $command .= "-y {$output} 2>&1";
        
        $this->log("Compressing {$qualityName}: {$command}");
        
        exec($command, $execOutput, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($outputFile)) {
            throw new Exception("Failed to compress {$qualityName}: " . implode("\n", $execOutput));
        }
        
        return $outputFile;
    }
    
    /**
     * Convert MP4 files to HLS
     */
    private function convertToHLS($compressedMP4s, $outputDir, $publicUrl) {
        $result = [];
        
        foreach ($compressedMP4s as $qualityName => $mp4Url) {
            // Construct filesystem path directly from known filename
            $mp4File = $outputDir . '/compressed_' . $qualityName . '.mp4';
            
            if (!file_exists($mp4File)) {
                throw new Exception("MP4 file not found: {$mp4File}");
            }
            
            $hlsFile = $this->convertMP4ToHLS($mp4File, $qualityName, $outputDir);
            
            $result[$qualityName] = $publicUrl . '/' . $qualityName . '.m3u8';
        }
        
        return $result;
    }
    
    /**
     * Convert single MP4 to HLS
     */
    private function convertMP4ToHLS($mp4File, $qualityName, $outputDir) {
        $ffmpeg = escapeshellarg($this->config['ffmpeg_binary']);
        $input = escapeshellarg($mp4File);
        $playlistFile = $outputDir . '/' . $qualityName . '.m3u8';
        $segmentPattern = $outputDir . '/' . $qualityName . '_%03d.ts';
        $hlsTime = $this->config['hls_time'];
        
        $command = "{$ffmpeg} -i {$input} ";
        $command .= "-c copy ";
        $command .= "-f hls ";
        $command .= "-hls_time {$hlsTime} ";
        $command .= "-hls_playlist_type vod ";
        $command .= "-hls_segment_filename " . escapeshellarg($segmentPattern) . " ";
        $command .= "-y " . escapeshellarg($playlistFile) . " 2>&1";
        
        exec($command, $execOutput, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($playlistFile)) {
            throw new Exception("Failed to convert {$qualityName} to HLS: " . implode("\n", $execOutput));
        }
        
        return $playlistFile;
    }
    
    /**
     * Create master.m3u8 playlist only for successfully created HLS renditions
     * 
     * @param string $outputDir - Directory containing HLS files
     * @param string $publicUrl - Public URL base
     * @param array $hlsPlaylists - Array of successfully created HLS playlists
     * @return string - URL to master playlist
     * @throws FFmpegException if no HLS renditions available
     */
    private function createMasterPlaylist($outputDir, $publicUrl, $hlsPlaylists = []) {
        // Only include qualities that actually have HLS playlists
        $availableQualities = [];
        
        foreach ($hlsPlaylists as $qualityName => $playlistUrl) {
            $playlistFile = $outputDir . '/' . $qualityName . '.m3u8';
            
            // Verify the HLS playlist file actually exists
            if (file_exists($playlistFile)) {
                $availableQualities[] = $qualityName;
            }
        }
        
        if (empty($availableQualities)) {
            throw new FFmpegException("Cannot create master playlist: no HLS renditions available");
        }
        
        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:3\n\n";
        
        // Only add entries for available qualities
        foreach ($availableQualities as $qualityName) {
            $settings = $this->config['video_qualities'][$qualityName];
            $width = $settings['width'];
            $height = $settings['height'];
            
            // Calculate bandwidth dynamically from config (in bits per second)
            $videoBitrate = $this->parseBitrate($settings['bitrate']);
            $audioBitrate = $this->parseBitrate($settings['audio_bitrate']);
            $bandwidth = $videoBitrate + $audioBitrate;
            
            $content .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$width}x{$height},NAME=\"{$qualityName}\"\n";
            $content .= "{$qualityName}.m3u8\n\n";
        }
        
        $masterFile = $outputDir . '/master.m3u8';
        file_put_contents($masterFile, $content);
        
        $this->log("Created master playlist with " . count($availableQualities) . " renditions: " . implode(', ', $availableQualities));
        
        return $publicUrl . '/master.m3u8';
    }
    
    /**
     * Parse bitrate string to bits per second
     * Supports: 800k, 800K, 1.2M, 1.2m, 96000 (raw number)
     */
    private function parseBitrate($bitrateStr) {
        // Trim and uppercase for consistent parsing
        $bitrateStr = strtoupper(trim($bitrateStr));
        
        // Extract numeric value and suffix
        if (preg_match('/^([\d.]+)([KM])?$/', $bitrateStr, $matches)) {
            $value = (float)$matches[1];
            $suffix = $matches[2] ?? '';
            
            // Convert to bits per second
            if ($suffix === 'M') {
                return (int)($value * 1000000);
            } elseif ($suffix === 'K') {
                return (int)($value * 1000);
            } else {
                // Raw number, assume it's already in bps
                return (int)$value;
            }
        }
        
        // Fallback: try to extract just the number
        $numeric = (int)filter_var($bitrateStr, FILTER_SANITIZE_NUMBER_INT);
        return $numeric > 0 ? $numeric : 96000; // Default to 96kbps if parsing fails
    }
    
    /**
     * Download file with retry logic (exponential backoff)
     * 
     * @param string $url - URL to download
     * @param string $destination - File path to save to
     * @param int $maxAttempts - Maximum retry attempts (default: 3)
     * @return string - Path to downloaded file
     * @throws DownloadException
     */
    private function downloadFileWithRetry($url, $destination, $maxAttempts = 3) {
        $attempts = 0;
        $delays = [1, 5, 15]; // 1s, 5s, 15s
        $lastError = null;
        
        while ($attempts < $maxAttempts) {
            try {
                $this->log("Download attempt " . ($attempts + 1) . "/{$maxAttempts} for: {$url}");
                return $this->downloadFile($url, $destination);
            } catch (Exception $e) {
                $attempts++;
                $lastError = $e->getMessage();
                $this->log("Download attempt {$attempts} failed: {$lastError}", 'WARNING');
                
                if ($attempts < $maxAttempts) {
                    $delay = $delays[$attempts - 1];
                    $this->log("Retrying in {$delay} seconds...");
                    sleep($delay);
                }
            }
        }
        
        throw new DownloadException(
            "Failed to download after {$maxAttempts} attempts: {$url}",
            0,
            ['url' => $url, 'last_error' => $lastError]
        );
    }
    
    /**
     * Verify directory structure is correct
     * 
     * @param string $dirPath - Directory to verify
     * @return bool - True if valid
     * @throws FileSystemException
     */
    private function verifyDirectoryStructure($dirPath) {
        // Check path format matches: .../YYYY/MM/postId/
        if (!preg_match('#/\d{4}/\d{2}/\d+/?$#', $dirPath)) {
            throw new FileSystemException("Invalid directory structure: {$dirPath}");
        }
        
        if (!is_dir($dirPath)) {
            throw new FileSystemException("Directory does not exist: {$dirPath}");
        }
        
        if (!is_writable($dirPath)) {
            throw new FileSystemException("Directory not writable: {$dirPath}");
        }
        
        return true;
    }
    
    /**
     * Get directory information
     * 
     * @param int $postId - Post ID
     * @return array - Directory information
     */
    private function getDirectoryInfo($postId) {
        $basePath = $this->config['media_base_path'];
        $year = date('Y');
        $month = date('m');
        $dirPath = "{$basePath}/{$year}/{$month}/{$postId}";
        
        $info = [
            'exists' => is_dir($dirPath),
            'writable' => is_writable($dirPath),
            'path' => $dirPath,
            'size' => 0,
            'files' => []
        ];
        
        if ($info['exists']) {
            $info['size'] = $this->dirSize($dirPath);
            $info['files'] = scandir($dirPath);
        }
        
        return $info;
    }
    
    /**
     * Calculate directory size recursively
     * 
     * @param string $dir - Directory path
     * @return int - Size in bytes
     */
    private function dirSize($dir) {
        $size = 0;
        
        foreach (glob(rtrim($dir, '/') . '/*', GLOB_NOSORT) as $file) {
            $size += is_file($file) ? filesize($file) : $this->dirSize($file);
        }
        
        return $size;
    }
    
    /**
     * Download file from URL with validation and cleanup
     * 
     * @param string $url - URL to download from
     * @param string $destination - File path to save to
     * @return string - Path to downloaded file
     * @throws DownloadException
     */
    private function downloadFile($url, $destination) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new DownloadException("Invalid URL format: {$url}");
        }
        
        $ch = curl_init($url);
        $fp = fopen($destination, 'wb');
        
        if (!$fp) {
            throw new DownloadException("Failed to open file for writing: {$destination}");
        }
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (VideoProcessor/3.0)',
            CURLOPT_FAILONERROR => false
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $error = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        // Validate HTTP status
        if (!$success || $httpCode !== 200) {
            if (file_exists($destination)) {
                unlink($destination);
            }
            throw new DownloadException(
                "Failed to download from {$url}. HTTP code: {$httpCode}, Error: {$error}",
                0,
                ['http_code' => $httpCode, 'url' => $url]
            );
        }
        
        // Validate file exists and has content
        if (!file_exists($destination)) {
            throw new DownloadException("Downloaded file does not exist: {$destination}");
        }
        
        $fileSize = filesize($destination);
        if ($fileSize === 0) {
            unlink($destination);
            throw new DownloadException("Downloaded file is empty: {$destination}");
        }
        
        // Validate minimum file size (at least 1KB)
        if ($fileSize < 1024) {
            unlink($destination);
            throw new DownloadException(
                "Downloaded file too small (${fileSize} bytes): likely corrupt",
                0,
                ['file_size' => $fileSize, 'url' => $url]
            );
        }
        
        // Validate content type for videos (if downloading video)
        if (strpos($destination, 'original.mp4') !== false) {
            $validVideoTypes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-matroska', 'application/octet-stream'];
            if ($contentType && !in_array(strtolower($contentType), $validVideoTypes)) {
                $this->log("Warning: Unexpected content type for video: {$contentType}", 'WARNING');
            }
        }
        
        $this->log("Downloaded file: " . basename($destination) . " (" . round($fileSize / (1024*1024), 2) . " MB)");
        
        return $destination;
    }
    
    /**
     * Get file extension from URL
     */
    private function getExtensionFromUrl($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        return $extension ?: 'jpg';
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'INFO') {
        if (!$this->config['debug'] && $level === 'INFO') {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
        
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}
?>
