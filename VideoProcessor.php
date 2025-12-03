<?php
/**
 * Video Processor Class
 * Handles video download, FFmpeg conversion to HLS, and file management
 */

class VideoProcessor {
    
    private $config;
    private $videosDir;
    private $hlsDir;
    private $logFile;
    
    public function __construct($config) {
        $this->config = $config;
        $this->videosDir = $config['videos_dir'];
        $this->hlsDir = $config['hls_dir'];
        $this->logFile = $config['log_file'];
        
        // Create directories if they don't exist
        $this->ensureDirectories();
    }
    
    /**
     * Ensure required directories exist
     */
    private function ensureDirectories() {
        $dirs = [
            $this->videosDir,
            $this->hlsDir,
            dirname($this->logFile)
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Process a video URL: download, convert to HLS, return streaming URL
     * 
     * @param string $videoUrl The URL of the video to process
     * @param int $postId The WordPress post ID (for tracking)
     * @return array Result with status, message, and hls_url
     */
    public function processVideo($videoUrl, $postId) {
        try {
            $this->log("Starting video processing for post ID: {$postId}");
            $this->log("Video URL: {$videoUrl}");
            
            // Validate video URL
            if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                throw new Exception("Invalid video URL");
            }
            
            // Generate unique identifier for this video
            $videoId = $this->generateVideoId($postId);
            
            // Download the video
            $this->log("Downloading video...");
            $downloadedFile = $this->downloadVideo($videoUrl, $videoId);
            
            if (!$downloadedFile) {
                throw new Exception("Failed to download video");
            }
            
            $this->log("Video downloaded: {$downloadedFile}");
            
            // Convert to HLS
            $this->log("Converting to HLS format...");
            $hlsUrl = $this->convertToHLS($downloadedFile, $videoId);
            
            if (!$hlsUrl) {
                throw new Exception("Failed to convert video to HLS");
            }
            
            $this->log("HLS conversion completed: {$hlsUrl}");
            
            // Cleanup original video if configured
            if ($this->config['cleanup_original']) {
                unlink($downloadedFile);
                $this->log("Original video deleted");
            }
            
            return [
                'status' => 'success',
                'message' => 'Video processed successfully',
                'hls_url' => $hlsUrl,
                'video_id' => $videoId
            ];
            
        } catch (Exception $e) {
            $this->log("Error: " . $e->getMessage(), [], 'ERROR');
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'hls_url' => null
            ];
        }
    }
    
    /**
     * Generate unique video ID
     */
    private function generateVideoId($postId) {
        return 'video_' . $postId . '_' . time();
    }
    
    /**
     * Download video from URL
     */
    private function downloadVideo($url, $videoId) {
        // Get file extension from URL
        $extension = $this->getExtensionFromUrl($url);
        $filename = $videoId . '.' . $extension;
        $filepath = $this->videosDir . '/' . $filename;
        
        // Download using cURL
        $ch = curl_init($url);
        $fp = fopen($filepath, 'wb');
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        fclose($fp);
        
        if (!$success || $httpCode !== 200) {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            return false;
        }
        
        // Verify file was downloaded and has content
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            return false;
        }
        
        return $filepath;
    }
    
    /**
     * Get file extension from URL
     */
    private function getExtensionFromUrl($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        
        // Default to mp4 if no extension found
        return $extension ?: 'mp4';
    }
    
    /**
     * Convert video to HLS format using FFmpeg
     * 
     * NOTE: This is a LEGACY method. New processing should use HLSConverter class
     * which stores files in /content/YYYY/MM/POST_ID/hls/ structure.
     * 
     * This legacy method stores files in /hls/{video_id}/ directory and returns
     * URLs in the format: {base_url}/hls/{video_id}/master.m3u8
     * 
     * IMPORTANT: Do not confuse legacy /hls/ paths with new /content/.../hls/ paths.
     * - Legacy: /hls/video_29561_1764533867/master.m3u8
     * - New:    /content/2025/10/30524/hls/master.m3u8
     */
    private function convertToHLS($inputFile, $videoId) {
        // Create output directory for this video
        $outputDir = $this->hlsDir . '/' . $videoId;
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        // Build FFmpeg command
        $command = $this->buildFFmpegCommand($inputFile, $outputDir);
        
        $this->log("Executing FFmpeg command");
        $this->log("Command: " . $command);
        
        // Execute FFmpeg
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        // Log FFmpeg output
        $this->log("FFmpeg output:\n" . implode("\n", $output));
        
        if ($returnCode !== 0) {
            $this->log("FFmpeg failed with return code: {$returnCode}", [], 'ERROR');
            return false;
        }
        
        // Verify master playlist was created
        $masterPlaylist = $outputDir . '/master.m3u8';
        if (!file_exists($masterPlaylist)) {
            $this->log("Master playlist not found: {$masterPlaylist}", [], 'ERROR');
            return false;
        }
        
        // Return public URL to master playlist
        // Use /hls/ path for legacy files stored in old hls directory
        $baseUrl = rtrim($this->config['base_url'], '/');
        return $baseUrl . '/hls/' . $videoId . '/master.m3u8';
    }
    
    /**
     * Check if video has audio stream
     * Returns: true if audio detected, false if no audio or ffprobe unavailable
     */
    private function hasAudioStream($inputFile) {
        $ffmpegPath = $this->config['ffmpeg_binary'];
        
        $ffprobePath = dirname($ffmpegPath) . '/ffprobe';
        if (!file_exists($ffprobePath)) {
            $ffprobePath = trim(shell_exec('which ffprobe 2>/dev/null'));
        }
        
        if (empty($ffprobePath) || !file_exists($ffprobePath)) {
            $this->log("ffprobe not found - defaulting to video-only mode (audio will still be included if present via -map 0:a?)", [], 'WARNING');
            return false;
        }
        
        $command = escapeshellarg($ffprobePath) . ' -v error -select_streams a:0 -show_entries stream=codec_type -of csv=p=0 ' . escapeshellarg($inputFile) . ' 2>&1';
        exec($command, $output, $returnCode);
        
        $hasAudio = ($returnCode === 0 && !empty($output) && trim($output[0]) === 'audio');
        $this->log("Audio stream detection: " . ($hasAudio ? "found" : "not found"));
        
        return $hasAudio;
    }
    
    /**
     * Build FFmpeg command for HLS conversion
     */
    private function buildFFmpegCommand($inputFile, $outputDir) {
        $ffmpeg = escapeshellarg($this->config['ffmpeg_binary']);
        
        // Detect if video has audio
        $hasAudio = $this->hasAudioStream($inputFile);
        
        // Escape paths
        $input = escapeshellarg($inputFile);
        $output = $outputDir . '/';
        
        // Build filter complex for multiple resolutions (4 qualities: 480p, 360p, 240p, 144p)
        // Using height-based scaling (-2:height) for consistent output across different aspect ratios
        // Order: 480p (stream 0), 360p (stream 1), 240p (stream 2), 144p (stream 3) to match downstream expectations
        $filterComplex = "[0:v]split=4[v1][v2][v3][v4];";
        $filterComplex .= "[v1]scale=-2:480:flags=lanczos,setsar=1[v1out];";
        $filterComplex .= "[v2]scale=-2:360:flags=lanczos,setsar=1[v2out];";
        $filterComplex .= "[v3]scale=-2:240:flags=lanczos,setsar=1[v3out];";
        $filterComplex .= "[v4]scale=-2:144:flags=lanczos,setsar=1[v4out]";
        
        // Build complete command
        $command = "{$ffmpeg} -y -i {$input} ";
        $command .= "-filter_complex \"{$filterComplex}\" ";
        
        // Map streams - always use optional audio flag for safety (4 video streams)
        $command .= "-map \"[v1out]\" -map 0:a? ";
        $command .= "-map \"[v2out]\" -map 0:a? ";
        $command .= "-map \"[v3out]\" -map 0:a? ";
        $command .= "-map \"[v4out]\" -map 0:a? ";
        
        // Encoding settings
        $command .= "-c:v libx264 -preset faster ";
        $command .= "-c:a aac -b:a 64k -ar 44100 ";
        
        // Bitrate settings for each resolution (480p, 360p, 240p, 144p - in stream order)
        $command .= "-b:v:0 500k -maxrate:v:0 600k -bufsize:v:0 1000k ";
        $command .= "-b:v:1 350k -maxrate:v:1 400k -bufsize:v:1 700k ";
        $command .= "-b:v:2 200k -maxrate:v:2 250k -bufsize:v:2 400k ";
        $command .= "-b:v:3 150k -maxrate:v:3 200k -bufsize:v:3 300k ";
        
        // HLS settings - replicate single audio stream across all video variants
        if ($hasAudio) {
            $command .= "-var_stream_map \"v:0,a:0 v:1,a:0 v:2,a:0 v:3,a:0\" ";
        } else {
            $command .= "-var_stream_map \"v:0 v:1 v:2 v:3\" ";
        }
        
        $command .= "-master_pl_name master.m3u8 ";
        $command .= "-f hls -hls_time {$this->config['hls_time']} ";
        $command .= "-hls_list_size {$this->config['hls_list_size']} ";
        $command .= "-hls_segment_filename \"{$output}stream_%v_%03d.ts\" ";
        $command .= "\"{$output}stream_%v.m3u8\"";
        
        return $command;
    }
    
    /**
     * Log message to file
     */
    private function log($message, $context = [], $level = 'INFO') {
        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0777, true)) {
                    error_log("[PROCESSOR] Failed to create log directory: {$logDir}");
                    return false;
                }
                chmod($logDir, 0777);
            }
            
            if (!file_exists($this->logFile)) {
                if (!touch($this->logFile)) {
                    error_log("[PROCESSOR] Failed to create log file: {$this->logFile}");
                    return false;
                }
                chmod($this->logFile, 0666);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
            $logMessage = "[{$timestamp}] [{$level}] [PROCESSOR] {$message}{$contextStr}\n";
            
            $result = file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log("[PROCESSOR] Failed to write to log file: {$this->logFile}");
                return false;
            }
            
            chmod($this->logFile, 0666);
            return true;
        } catch (Exception $e) {
            error_log("[PROCESSOR] Logging exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean up old videos
     */
    public function cleanupOldVideos() {
        $maxAge = $this->config['max_video_age_days'];
        
        if ($maxAge <= 0) {
            return;
        }
        
        $cutoffTime = time() - ($maxAge * 24 * 60 * 60);
        $deleted = 0;
        
        // Clean videos directory
        foreach (glob($this->videosDir . '/*') as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                unlink($file);
                $deleted++;
            }
        }
        
        // Clean HLS directory
        foreach (glob($this->hlsDir . '/*') as $dir) {
            if (is_dir($dir) && filemtime($dir) < $cutoffTime) {
                $this->deleteDirectory($dir);
                $deleted++;
            }
        }
        
        $this->log("Cleanup completed: {$deleted} items deleted");
    }
    
    /**
     * Recursively delete directory
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }
}
