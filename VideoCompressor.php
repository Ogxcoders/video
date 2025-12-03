<?php
/**
 * Video Compressor Class
 * Handles FFmpeg video compression to multiple quality levels (480p, 360p, 240p, 144p)
 */

class VideoCompressor {
    private $config;
    private $logFile;
    private $progressCallback = null;
    private $currentProgress = 0;
    
    const MAX_VIDEO_DURATION = 300;
    const MAX_VIDEO_SIZE_MB = 100;
    const ALLOWED_CODECS = ['h264', 'hevc', 'h265', 'vp8', 'vp9', 'prores', 'mpeg4', 'av1'];
    const ALLOWED_CONTAINERS = ['mp4', 'mov', 'webm', 'mkv'];
    
    const ERROR_DURATION_TOO_LONG = 'DURATION_TOO_LONG';
    const ERROR_FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    const ERROR_INVALID_CODEC = 'INVALID_CODEC';
    const ERROR_VIDEO_CORRUPTED = 'VIDEO_CORRUPTED';
    const ERROR_FILE_NOT_FOUND = 'FILE_NOT_FOUND';
    const ERROR_INVALID_CONTAINER = 'INVALID_CONTAINER';
    const ERROR_DOWNLOAD_FAILED = 'DOWNLOAD_FAILED';
    
    /**
     * Quality presets with resolution and bitrate settings
     * Based on TASKLIST.md Task 10 requirements
     */
    private const QUALITY_PRESETS = [
        '480p' => [
            'width' => 854,
            'height' => 480,
            'bitrate' => '800k',
            'maxrate' => '1000k',
            'bufsize' => '2000k'
        ],
        '360p' => [
            'width' => 640,
            'height' => 360,
            'bitrate' => '600k',
            'maxrate' => '750k',
            'bufsize' => '1500k'
        ],
        '240p' => [
            'width' => 426,
            'height' => 240,
            'bitrate' => '400k',
            'maxrate' => '500k',
            'bufsize' => '1000k'
        ],
        '144p' => [
            'width' => 256,
            'height' => 144,
            'bitrate' => '200k',
            'maxrate' => '250k',
            'bufsize' => '500k'
        ]
    ];
    
    /**
     * Initialize Video Compressor
     */
    public function __construct($config = []) {
        $defaultConfig = require __DIR__ . '/config.php';
        $this->config = array_merge($defaultConfig, $config);
        $this->logFile = $this->config['log_file'];
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
            chmod($logDir, 0777);
        }
    }
    
    /**
     * Download video from URL to local uploads directory
     * 
     * @param string $videoUrl Full URL to the video file
     * @param string $wpMediaPath WordPress media path (for determining relative path)
     * @return array Result with 'success', 'local_path', and optionally 'error'
     */
    public function downloadVideoFromUrl($videoUrl, $wpMediaPath = '') {
        $this->log("Starting video download from URL", [
            'url' => $videoUrl,
            'wpMediaPath' => $wpMediaPath
        ]);
        
        $validationResult = $this->validateDownloadUrl($videoUrl);
        if (!$validationResult['valid']) {
            $this->log("URL validation failed", [
                'url' => $videoUrl,
                'error' => $validationResult['error']
            ], 'ERROR');
            return [
                'success' => false,
                'error' => $validationResult['error']
            ];
        }
        
        $uploadsDir = $this->config['media_uploads_dir'];
        
        if (!is_dir($uploadsDir)) {
            if (!mkdir($uploadsDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => "Failed to create uploads directory: {$uploadsDir}"
                ];
            }
        }
        
        if (!empty($wpMediaPath)) {
            if (preg_match('#/wp-content/uploads/(.+)$#', $wpMediaPath, $matches)) {
                $relativePath = $matches[1];
            } elseif (preg_match('#^/?wp-content/uploads/(.+)$#', $wpMediaPath, $matches)) {
                $relativePath = $matches[1];
            } else {
                $relativePath = ltrim($wpMediaPath, '/');
            }
        } else {
            $parsedUrl = parse_url($videoUrl, PHP_URL_PATH);
            if (preg_match('#/wp-content/uploads/(.+)$#', $parsedUrl, $matches)) {
                $relativePath = $matches[1];
            } else {
                $filename = basename($parsedUrl);
                $relativePath = date('Y/m') . '/' . $filename;
            }
        }
        
        $relativePath = str_replace(['../', '..\\'], '', $relativePath);
        
        $localPath = rtrim($uploadsDir, '/') . '/' . ltrim($relativePath, '/');
        $localDir = dirname($localPath);
        
        if (!is_dir($localDir)) {
            if (!mkdir($localDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => "Failed to create directory: {$localDir}"
                ];
            }
        }
        
        $this->log("Downloading video to local path", [
            'url' => $videoUrl,
            'local_path' => $localPath
        ]);
        
        $ch = curl_init($videoUrl);
        $fp = fopen($localPath, 'wb');
        
        if (!$fp) {
            return [
                'success' => false,
                'error' => "Failed to open file for writing: {$localPath}"
            ];
        }
        
        $verifySsl = $this->config['verify_ssl_downloads'] ?? true;
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; VideoCompressor/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: video/*,*/*',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        curl_close($ch);
        fclose($fp);
        
        if (!empty($effectiveUrl) && $effectiveUrl !== $videoUrl) {
            $finalValidation = $this->validateDownloadUrl($effectiveUrl);
            if (!$finalValidation['valid']) {
                @unlink($localPath);
                $this->log("Final URL validation failed after redirect", [
                    'original_url' => $videoUrl,
                    'effective_url' => $effectiveUrl,
                    'error' => $finalValidation['error']
                ], 'ERROR');
                
                return [
                    'success' => false,
                    'error' => "Redirect to unauthorized URL blocked: " . $finalValidation['error']
                ];
            }
        }
        
        if (!$success || $httpCode !== 200) {
            @unlink($localPath);
            $this->log("Video download failed", [
                'url' => $videoUrl,
                'http_code' => $httpCode,
                'curl_error' => $error
            ], 'ERROR');
            
            return [
                'success' => false,
                'error' => "Download failed: HTTP {$httpCode}" . ($error ? " - {$error}" : "")
            ];
        }
        
        $fileSize = filesize($localPath);
        if ($fileSize < 1024) {
            @unlink($localPath);
            $this->log("Downloaded file too small", [
                'url' => $videoUrl,
                'size' => $fileSize
            ], 'ERROR');
            
            return [
                'success' => false,
                'error' => "Downloaded file is too small ({$fileSize} bytes) - may not be a valid video"
            ];
        }
        
        chmod($localPath, 0644);
        
        $this->log("Video downloaded successfully", [
            'url' => $videoUrl,
            'local_path' => $localPath,
            'size' => $this->formatBytes($fileSize)
        ]);
        
        return [
            'success' => true,
            'local_path' => $localPath,
            'relative_path' => $relativePath,
            'file_size' => $fileSize
        ];
    }
    
    /**
     * Validate download URL against allowed domains and security requirements
     * 
     * @param string $url The URL to validate
     * @return array Result with 'valid' boolean and optionally 'error' message
     */
    private function validateDownloadUrl($url) {
        if (empty($url)) {
            return ['valid' => false, 'error' => 'URL is empty'];
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['valid' => false, 'error' => 'Invalid URL format'];
        }
        
        $parsedUrl = parse_url($url);
        
        $scheme = strtolower($parsedUrl['scheme'] ?? '');
        if (empty($scheme) || !in_array($scheme, ['http', 'https'])) {
            return ['valid' => false, 'error' => 'Only HTTP/HTTPS URLs are allowed'];
        }
        
        if (empty($parsedUrl['host'])) {
            return ['valid' => false, 'error' => 'URL missing host'];
        }
        
        $host = strtolower($parsedUrl['host']);
        
        $privatePatterns = [
            '/^localhost$/i',
            '/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
            '/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
            '/^172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}$/',
            '/^192\.168\.\d{1,3}\.\d{1,3}$/',
            '/^169\.254\.\d{1,3}\.\d{1,3}$/',
            '/^0\.0\.0\.0$/',
            '/^\[::1\]$/',
            '/^metadata\.google\.internal$/i',
            '/\.internal$/i',
            '/\.local$/i'
        ];
        
        foreach ($privatePatterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return ['valid' => false, 'error' => 'Private/internal hosts not allowed'];
            }
        }
        
        $allowedDomains = $this->config['allowed_download_domains'] ?? ['*'];
        
        if (empty($allowedDomains)) {
            return ['valid' => false, 'error' => 'No allowed download domains configured'];
        }
        
        // Check if wildcard '*' is in the allowed domains list - allows all domains
        if (in_array('*', $allowedDomains, true)) {
            $this->log("Domain validation: wildcard '*' enabled - all domains allowed", [
                'url' => $url,
                'host' => $host
            ]);
            return ['valid' => true];
        }
        
        $domainAllowed = false;
        foreach ($allowedDomains as $allowedDomain) {
            $allowedDomain = strtolower(trim($allowedDomain));
            
            if (strpos($allowedDomain, '*.') === 0) {
                $wildcardSuffix = substr($allowedDomain, 2);
                if ($host === $wildcardSuffix || substr($host, -strlen('.' . $wildcardSuffix)) === '.' . $wildcardSuffix) {
                    $domainAllowed = true;
                    break;
                }
            } elseif ($host === $allowedDomain || preg_match('/\.' . preg_quote($allowedDomain, '/') . '$/', $host)) {
                $domainAllowed = true;
                break;
            }
        }
        
        if (!$domainAllowed) {
            return [
                'valid' => false, 
                'error' => "Domain '$host' not in allowed list: " . implode(', ', $allowedDomains)
            ];
        }
        
        $this->log("URL validation passed", [
            'url' => $url,
            'host' => $host
        ]);
        
        return ['valid' => true];
    }
    
    /**
     * Set a callback function for progress updates
     * 
     * @param callable $callback Callback function that receives (progress, stage) parameters
     */
    public function setProgressCallback($callback) {
        $this->progressCallback = $callback;
    }
    
    /**
     * Get current compression progress (0-100)
     * 
     * @return int Current progress percentage
     */
    public function getProgress() {
        return $this->currentProgress;
    }
    
    /**
     * Update progress and trigger callback if set
     * 
     * @param int $progress Progress percentage (0-100)
     * @param string $stage Current processing stage
     */
    private function updateProgress($progress, $stage = 'processing') {
        $this->currentProgress = $progress;
        
        if ($this->progressCallback !== null && is_callable($this->progressCallback)) {
            call_user_func($this->progressCallback, $progress, $stage);
        }
        
        $this->log("Progress update", [
            'progress' => $progress . '%',
            'stage' => $stage
        ]);
    }
    
    /**
     * Get comprehensive video information using ffprobe
     * Task 15: Returns detailed metadata for validation purposes
     * 
     * @param string $filePath Path to the video file
     * @return array Video metadata including duration, codec, container, resolution, frame_rate, and corruption status
     */
    public function getVideoInfo($filePath) {
        $info = [
            'valid' => false,
            'corrupted' => false,
            'error' => null,
            'duration' => 0,
            'video_codec' => null,
            'audio_codec' => null,
            'container' => null,
            'width' => 0,
            'height' => 0,
            'resolution' => null,
            'bitrate' => 0,
            'frame_rate' => null,
            'frame_rate_numeric' => 0,
            'file_size' => 0,
            'file_size_mb' => 0
        ];
        
        if (!file_exists($filePath)) {
            $info['error'] = 'File not found';
            $info['corrupted'] = true;
            $this->log("getVideoInfo: File not found", ['path' => $filePath], 'ERROR');
            return $info;
        }
        
        $info['file_size'] = filesize($filePath);
        $info['file_size_mb'] = round($info['file_size'] / (1024 * 1024), 2);
        
        $pathInfo = pathinfo($filePath);
        $info['container'] = strtolower($pathInfo['extension'] ?? '');
        
        $ffprobe = dirname($this->config['ffmpeg_binary']) . '/ffprobe';
        
        $command = sprintf(
            '%s -v error -show_entries format=duration,bit_rate,format_name -show_entries stream=codec_type,codec_name,width,height,r_frame_rate -of json %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($filePath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0 || empty($output)) {
            $info['corrupted'] = true;
            $info['error'] = 'ffprobe failed to read video - file may be corrupted';
            $this->log("getVideoInfo: ffprobe failed (video may be corrupted)", [
                'path' => $filePath,
                'return_code' => $returnCode,
                'output' => implode("\n", array_slice($output, 0, 5))
            ], 'ERROR');
            return $info;
        }
        
        $jsonOutput = implode("\n", $output);
        $data = json_decode($jsonOutput, true);
        
        if ($data === null) {
            $info['corrupted'] = true;
            $info['error'] = 'Failed to parse video metadata - file may be corrupted';
            $this->log("getVideoInfo: Failed to parse ffprobe output (video may be corrupted)", [
                'path' => $filePath,
                'output' => substr($jsonOutput, 0, 500)
            ], 'ERROR');
            return $info;
        }
        
        if (isset($data['format'])) {
            $info['duration'] = round((float)($data['format']['duration'] ?? 0), 2);
            $info['bitrate'] = (int)($data['format']['bit_rate'] ?? 0);
            
            if (isset($data['format']['format_name'])) {
                $formats = explode(',', $data['format']['format_name']);
                $info['container'] = strtolower(trim($formats[0]));
            }
        }
        
        $hasVideoStream = false;
        if (isset($data['streams']) && is_array($data['streams'])) {
            foreach ($data['streams'] as $stream) {
                $codecType = $stream['codec_type'] ?? '';
                
                if ($codecType === 'video' && $info['video_codec'] === null) {
                    $hasVideoStream = true;
                    $info['video_codec'] = strtolower($stream['codec_name'] ?? '');
                    $info['width'] = (int)($stream['width'] ?? 0);
                    $info['height'] = (int)($stream['height'] ?? 0);
                    $info['resolution'] = $info['width'] . 'x' . $info['height'];
                    
                    if (isset($stream['r_frame_rate'])) {
                        $parts = explode('/', $stream['r_frame_rate']);
                        if (count($parts) === 2 && $parts[1] > 0) {
                            $info['frame_rate_numeric'] = round($parts[0] / $parts[1], 2);
                            $info['frame_rate'] = $info['frame_rate_numeric'] . ' fps';
                        } else {
                            $info['frame_rate'] = $stream['r_frame_rate'];
                        }
                    }
                }
                
                if ($codecType === 'audio' && $info['audio_codec'] === null) {
                    $info['audio_codec'] = strtolower($stream['codec_name'] ?? '');
                }
            }
        }
        
        if (!$hasVideoStream) {
            $info['corrupted'] = true;
            $info['error'] = 'No video stream found in file';
            $this->log("getVideoInfo: No video stream found", ['path' => $filePath], 'ERROR');
            return $info;
        }
        
        if ($info['duration'] <= 0 || $info['width'] <= 0 || $info['height'] <= 0) {
            $info['corrupted'] = true;
            $info['error'] = 'Invalid video metadata (zero duration or dimensions)';
            $this->log("getVideoInfo: Invalid video metadata", [
                'path' => $filePath,
                'duration' => $info['duration'],
                'width' => $info['width'],
                'height' => $info['height']
            ], 'ERROR');
            return $info;
        }
        
        $info['valid'] = true;
        
        $this->log("Video info retrieved successfully", [
            'path' => basename($filePath),
            'duration' => $info['duration'] . 's',
            'resolution' => $info['resolution'],
            'video_codec' => $info['video_codec'],
            'container' => $info['container'],
            'file_size_mb' => $info['file_size_mb'] . ' MB'
        ]);
        
        return $info;
    }
    
    /**
     * Validate input video for processing
     * Task 15: Comprehensive validation before compression
     * 
     * @param string $filePath Path to the video file
     * @return array Validation result with 'valid' boolean, 'errors' array, 'error_code', and 'video_info'
     */
    public function validateInputVideo($filePath) {
        $result = [
            'valid' => true,
            'errors' => [],
            'error_code' => null,
            'video_info' => null
        ];
        
        $this->log("Starting input video validation", ['path' => basename($filePath)]);
        
        if (!file_exists($filePath)) {
            $result['valid'] = false;
            $result['errors'][] = "Video file not found: {$filePath}";
            $result['error_code'] = self::ERROR_FILE_NOT_FOUND;
            $this->log("Validation failed: file not found", ['path' => $filePath], 'ERROR');
            return $result;
        }
        
        $fileSizeMB = filesize($filePath) / (1024 * 1024);
        if ($fileSizeMB > self::MAX_VIDEO_SIZE_MB) {
            $result['valid'] = false;
            $result['errors'][] = sprintf(
                "File size %.2f MB exceeds maximum allowed size of %d MB",
                $fileSizeMB,
                self::MAX_VIDEO_SIZE_MB
            );
            $result['error_code'] = self::ERROR_FILE_TOO_LARGE;
            $this->log("Validation failed: file too large", [
                'path' => basename($filePath),
                'size_mb' => round($fileSizeMB, 2),
                'max_mb' => self::MAX_VIDEO_SIZE_MB
            ], 'ERROR');
            return $result;
        }
        
        $videoInfo = $this->getVideoInfo($filePath);
        $result['video_info'] = $videoInfo;
        
        if ($videoInfo['corrupted'] || !$videoInfo['valid']) {
            $result['valid'] = false;
            $result['errors'][] = "Video file is corrupted or unreadable: " . ($videoInfo['error'] ?? 'Unknown error');
            $result['error_code'] = self::ERROR_VIDEO_CORRUPTED;
            $this->log("Validation failed: video corrupted", [
                'path' => basename($filePath),
                'error' => $videoInfo['error']
            ], 'ERROR');
            return $result;
        }
        
        if ($videoInfo['duration'] > self::MAX_VIDEO_DURATION) {
            $result['valid'] = false;
            $result['errors'][] = sprintf(
                "Video duration %.2f seconds exceeds maximum allowed duration of %d seconds",
                $videoInfo['duration'],
                self::MAX_VIDEO_DURATION
            );
            $result['error_code'] = self::ERROR_DURATION_TOO_LONG;
            $this->log("Validation failed: duration too long", [
                'path' => basename($filePath),
                'duration' => $videoInfo['duration'],
                'max_duration' => self::MAX_VIDEO_DURATION
            ], 'ERROR');
            return $result;
        }
        
        $codec = $videoInfo['video_codec'];
        if (!in_array($codec, self::ALLOWED_CODECS)) {
            $result['valid'] = false;
            $result['errors'][] = sprintf(
                "Video codec '%s' is not supported. Allowed codecs: %s",
                $codec,
                implode(', ', self::ALLOWED_CODECS)
            );
            $result['error_code'] = self::ERROR_INVALID_CODEC;
            $this->log("Validation failed: invalid codec", [
                'path' => basename($filePath),
                'codec' => $codec,
                'allowed_codecs' => self::ALLOWED_CODECS
            ], 'ERROR');
            return $result;
        }
        
        $container = $videoInfo['container'];
        $containerNormalized = $this->normalizeContainer($container);
        if (!in_array($containerNormalized, self::ALLOWED_CONTAINERS)) {
            $result['valid'] = false;
            $result['errors'][] = sprintf(
                "Container format '%s' is not supported. Allowed formats: %s",
                $container,
                implode(', ', self::ALLOWED_CONTAINERS)
            );
            $result['error_code'] = self::ERROR_INVALID_CONTAINER;
            $this->log("Validation failed: invalid container", [
                'path' => basename($filePath),
                'container' => $container,
                'allowed_containers' => self::ALLOWED_CONTAINERS
            ], 'ERROR');
            return $result;
        }
        
        $this->log("Input video validation passed", [
            'path' => basename($filePath),
            'duration' => $videoInfo['duration'] . 's',
            'size_mb' => $videoInfo['file_size_mb'] . ' MB',
            'codec' => $codec,
            'container' => $container,
            'resolution' => $videoInfo['resolution']
        ]);
        
        return $result;
    }
    
    /**
     * Normalize container format name for validation
     * 
     * @param string $container Raw container format from ffprobe
     * @return string Normalized container name
     */
    private function normalizeContainer($container) {
        $container = strtolower(trim($container));
        
        $mapping = [
            'matroska' => 'mkv',
            'quicktime' => 'mov',
            'mov' => 'mov',
            'mp4' => 'mp4',
            'webm' => 'webm',
            'mkv' => 'mkv'
        ];
        
        return $mapping[$container] ?? $container;
    }
    
    /**
     * Get video duration and detailed info using ffprobe
     * 
     * @param string $filePath Path to the video file
     * @return array Video information including duration, codec, resolution
     */
    public function getVideoDurationAndInfo($filePath) {
        $ffprobe = dirname($this->config['ffmpeg_binary']) . '/ffprobe';
        
        $info = [
            'duration' => 0,
            'video_codec' => null,
            'audio_codec' => null,
            'width' => 0,
            'height' => 0,
            'resolution' => null,
            'bitrate' => 0,
            'frame_rate' => null,
            'file_size' => file_exists($filePath) ? filesize($filePath) : 0
        ];
        
        if (!file_exists($filePath)) {
            $this->log("getVideoDurationAndInfo: File not found", ['path' => $filePath], 'ERROR');
            return $info;
        }
        
        $command = sprintf(
            '%s -v error -show_entries format=duration,bit_rate -show_entries stream=codec_type,codec_name,width,height,r_frame_rate -of json %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($filePath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0 || empty($output)) {
            $this->log("getVideoDurationAndInfo: ffprobe failed", [
                'path' => $filePath,
                'return_code' => $returnCode
            ], 'WARNING');
            return $info;
        }
        
        $jsonOutput = implode("\n", $output);
        $data = json_decode($jsonOutput, true);
        
        if ($data === null) {
            $this->log("getVideoDurationAndInfo: Failed to parse ffprobe output", [
                'path' => $filePath,
                'output' => substr($jsonOutput, 0, 500)
            ], 'WARNING');
            return $info;
        }
        
        if (isset($data['format'])) {
            $info['duration'] = round((float)($data['format']['duration'] ?? 0), 2);
            $info['bitrate'] = (int)($data['format']['bit_rate'] ?? 0);
        }
        
        if (isset($data['streams']) && is_array($data['streams'])) {
            foreach ($data['streams'] as $stream) {
                $codecType = $stream['codec_type'] ?? '';
                
                if ($codecType === 'video' && $info['video_codec'] === null) {
                    $info['video_codec'] = $stream['codec_name'] ?? null;
                    $info['width'] = (int)($stream['width'] ?? 0);
                    $info['height'] = (int)($stream['height'] ?? 0);
                    $info['resolution'] = $info['width'] . 'x' . $info['height'];
                    
                    if (isset($stream['r_frame_rate'])) {
                        $parts = explode('/', $stream['r_frame_rate']);
                        if (count($parts) === 2 && $parts[1] > 0) {
                            $info['frame_rate'] = round($parts[0] / $parts[1], 2) . ' fps';
                        } else {
                            $info['frame_rate'] = $stream['r_frame_rate'];
                        }
                    }
                }
                
                if ($codecType === 'audio' && $info['audio_codec'] === null) {
                    $info['audio_codec'] = $stream['codec_name'] ?? null;
                }
            }
        }
        
        $this->log("Video info retrieved", [
            'path' => basename($filePath),
            'duration' => $info['duration'] . 's',
            'resolution' => $info['resolution'],
            'video_codec' => $info['video_codec'],
            'audio_codec' => $info['audio_codec']
        ]);
        
        return $info;
    }
    
    /**
     * Compress video to all quality levels (480p, 360p, 240p, 144p) in parallel
     * 
     * @param array $jobData Job data from queue
     * @return array Result with success status and output paths for all qualities
     */
    public function compressVideo($jobData) {
        $this->log("Starting multi-quality compression for job: {$jobData['jobId']}");
        
        try {
            $postId = $jobData['postId'];
            $wpMediaPath = $jobData['wpMediaPath'];
            $wpVideoUrl = $jobData['wpVideoUrl'] ?? '';
            $year = $jobData['year'];
            $month = $jobData['month'];
            $reprocess = $jobData['reprocess'] ?? false;
            
            if (!empty($wpVideoUrl)) {
                $this->log("Remote video URL provided - downloading from WordPress", [
                    'wpVideoUrl' => $wpVideoUrl,
                    'wpMediaPath' => $wpMediaPath,
                    'jobId' => $jobData['jobId']
                ]);
                
                $downloadResult = $this->downloadVideoFromUrl($wpVideoUrl, $wpMediaPath);
                
                if (!$downloadResult['success']) {
                    $this->log("Video download failed", [
                        'error' => $downloadResult['error'],
                        'jobId' => $jobData['jobId']
                    ], 'ERROR');
                    
                    return [
                        'success' => false,
                        'error' => $downloadResult['error'],
                        'error_code' => self::ERROR_DOWNLOAD_FAILED,
                        'jobId' => $jobData['jobId']
                    ];
                }
                
                $wpMediaPath = $downloadResult['relative_path'];
                $this->log("Video downloaded successfully - proceeding with compression", [
                    'local_path' => $downloadResult['local_path'],
                    'relative_path' => $wpMediaPath,
                    'file_size' => $this->formatBytes($downloadResult['file_size']),
                    'jobId' => $jobData['jobId']
                ]);
            }
            
            $paths = $this->buildPaths($postId, $year, $month, $wpMediaPath);
            
            $this->log("Paths configured for multi-quality compression", [
                'source' => $paths['source'],
                'output_dir' => $paths['output_dir'],
                'original' => $paths['original'],
                'qualities' => ['480p', '360p', '240p', '144p'],
                'reprocess' => $reprocess ? 'yes' : 'no'
            ]);
            
            $this->log("Task 15: Validating input video before compression...", ['jobId' => $jobData['jobId']]);
            $validationResult = $this->validateInputVideo($paths['source']);
            
            if (!$validationResult['valid']) {
                $errorMessage = implode('; ', $validationResult['errors']);
                $this->log("Input video validation failed", [
                    'jobId' => $jobData['jobId'],
                    'error_code' => $validationResult['error_code'],
                    'errors' => $validationResult['errors']
                ], 'ERROR');
                
                return [
                    'success' => false,
                    'error' => $errorMessage,
                    'error_code' => $validationResult['error_code'],
                    'validation_error' => true,
                    'video_info' => $validationResult['video_info'],
                    'jobId' => $jobData['jobId'] ?? 'unknown'
                ];
            }
            
            $this->log("Input video validation passed", [
                'jobId' => $jobData['jobId'],
                'video_info' => [
                    'duration' => $validationResult['video_info']['duration'] . 's',
                    'codec' => $validationResult['video_info']['video_codec'],
                    'resolution' => $validationResult['video_info']['resolution'],
                    'size_mb' => $validationResult['video_info']['file_size_mb'] . ' MB'
                ]
            ]);
            
            if (!is_readable($paths['source'])) {
                throw new Exception("Source video is not readable: {$paths['source']}");
            }
            
            $sourceSize = filesize($paths['source']);
            
            $this->log("Source file found", ['size' => $this->formatBytes($sourceSize)]);
            
            if (is_dir($paths['output_dir'])) {
                if ($reprocess) {
                    $this->log("Reprocess flag set - overwriting existing directory");
                    $this->cleanDirectory($paths['output_dir']);
                } elseif ($this->allQualitiesExist($paths)) {
                    $this->log("All quality videos already exist - skipping (use reprocess=true to override)");
                    return $this->buildExistingFilesResult($paths, $sourceSize, $jobData);
                }
            } else {
                mkdir($paths['output_dir'], 0755, true);
                $this->log("Created output directory: {$paths['output_dir']}");
            }
            
            chmod($paths['output_dir'], 0755);
            
            $this->log("Copying original to output directory...");
            if (!copy($paths['source'], $paths['original'])) {
                throw new Exception("Failed to copy original video");
            }
            chmod($paths['original'], 0644);
            $this->log("Original copied successfully");
            
            // Get video duration
            $duration = $this->getVideoDuration($paths['original']);
            $this->log("Video duration: {$duration}s");
            
            // Compress to all quality levels in parallel
            $this->log("Starting parallel multi-quality compression (480p, 360p, 240p, 144p)...");
            $startTime = microtime(true);
            
            $compressionResults = $this->compressAllQualitiesParallel($paths['original'], $paths);
            
            $totalTime = microtime(true) - $startTime;
            
            // Check for any failures
            $failedQualities = [];
            $successfulQualities = [];
            foreach ($compressionResults as $quality => $result) {
                if (!$result['success']) {
                    $failedQualities[$quality] = $result['error'];
                } else {
                    $successfulQualities[$quality] = $result;
                }
            }
            
            if (count($failedQualities) === count(self::QUALITY_PRESETS)) {
                throw new Exception("All quality compressions failed: " . json_encode($failedQualities));
            }
            
            if (!empty($failedQualities)) {
                $this->log("Some quality compressions failed", [
                    'failed' => array_keys($failedQualities),
                    'successful' => array_keys($successfulQualities)
                ], 'WARNING');
            }
            
            $this->log("Multi-quality compression completed", [
                'total_time' => number_format($totalTime, 2) . 's',
                'successful_qualities' => array_keys($successfulQualities),
                'failed_qualities' => array_keys($failedQualities)
            ]);
            
            // Validate and gather stats for all successful outputs
            $urls = [];
            $pathsResult = ['original' => $paths['original'], 'output_dir' => $paths['output_dir']];
            $qualityStats = [];
            $totalCompressedSize = 0;
            
            foreach (['480p', '360p', '240p', '144p'] as $quality) {
                $qualityPath = $paths["compressed_{$quality}"];
                if (isset($successfulQualities[$quality]) && file_exists($qualityPath)) {
                    if (!$this->validateVideo($qualityPath)) {
                        $this->log("Validation failed for {$quality}", ['path' => $qualityPath], 'WARNING');
                        continue;
                    }
                    
                    $size = filesize($qualityPath);
                    $urls["compressed_{$quality}"] = $this->buildPublicUrl($qualityPath);
                    $pathsResult["compressed_{$quality}"] = $qualityPath;
                    $qualityStats[$quality] = [
                        'size' => $size,
                        'compression_ratio' => round((($sourceSize - $size) / $sourceSize) * 100, 2),
                        'time' => $successfulQualities[$quality]['time']
                    ];
                    
                    if ($quality === '480p') {
                        $totalCompressedSize = $size;
                    }
                    
                    $this->log("{$quality} validated successfully", [
                        'size' => $this->formatBytes($size),
                        'compression' => $qualityStats[$quality]['compression_ratio'] . '%'
                    ]);
                }
            }
            
            // Use 480p for main compression ratio (primary quality)
            $compressionRatio = isset($qualityStats['480p']) ? $qualityStats['480p']['compression_ratio'] : 0;
            
            $stats = [
                'original_size' => $sourceSize,
                'compressed_size' => $totalCompressedSize,
                'compression_ratio' => round($compressionRatio, 2),
                'duration' => $duration,
                'processing_time' => $totalTime,
                'quality_stats' => $qualityStats
            ];
            
            $this->log("Multi-quality compression stats", $stats);
            
            // Build result
            $result = [
                'success' => true,
                'paths' => $pathsResult,
                'urls' => $urls,
                'stats' => $stats,
                'jobId' => $jobData['jobId'],
                'postId' => $postId
            ];
            
            $this->log("Multi-quality compression job completed successfully", ['jobId' => $jobData['jobId']]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Compression failed: " . $e->getMessage(), ['jobId' => $jobData['jobId'] ?? 'unknown'], 'ERROR');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'jobId' => $jobData['jobId'] ?? 'unknown'
            ];
        }
    }
    
    /**
     * Check if all quality levels already exist
     */
    private function allQualitiesExist($paths) {
        foreach (['480p', '360p', '240p', '144p'] as $quality) {
            if (!file_exists($paths["compressed_{$quality}"])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Build result from existing files (when skipping)
     */
    private function buildExistingFilesResult($paths, $sourceSize, $jobData) {
        $urls = [];
        $pathsResult = ['original' => $paths['original'], 'output_dir' => $paths['output_dir']];
        $qualityStats = [];
        $totalCompressedSize = 0;
        
        $duration = $this->getVideoDuration($paths['compressed_480p']);
        
        foreach (['480p', '360p', '240p', '144p'] as $quality) {
            $qualityPath = $paths["compressed_{$quality}"];
            if (file_exists($qualityPath)) {
                $size = filesize($qualityPath);
                $urls["compressed_{$quality}"] = $this->buildPublicUrl($qualityPath);
                $pathsResult["compressed_{$quality}"] = $qualityPath;
                $qualityStats[$quality] = [
                    'size' => $size,
                    'compression_ratio' => round((($sourceSize - $size) / $sourceSize) * 100, 2),
                    'time' => 0
                ];
                
                if ($quality === '480p') {
                    $totalCompressedSize = $size;
                }
            }
        }
        
        $compressionRatio = isset($qualityStats['480p']) ? $qualityStats['480p']['compression_ratio'] : 0;
        
        return [
            'success' => true,
            'skipped' => true,
            'message' => 'All quality videos already compressed',
            'paths' => $pathsResult,
            'urls' => $urls,
            'stats' => [
                'original_size' => $sourceSize,
                'compressed_size' => $totalCompressedSize,
                'compression_ratio' => $compressionRatio,
                'duration' => $duration,
                'processing_time' => 0,
                'quality_stats' => $qualityStats
            ],
            'jobId' => $jobData['jobId'],
            'postId' => $jobData['postId']
        ];
    }
    
    /**
     * Compress video to all quality levels
     * 
     * By default runs SEQUENTIALLY to prevent resource exhaustion on VPS.
     * Set 'parallel_compression' => true in config to enable parallel processing
     * (only recommended for high-resource servers with 4+ CPU cores and 8GB+ RAM).
     * 
     * @param string $inputPath Path to source video
     * @param array $paths Array of output paths
     * @return array Results for each quality level
     */
    private function compressAllQualitiesParallel($inputPath, $paths) {
        $parallelEnabled = $this->config['parallel_compression'] ?? false;
        
        if (!$parallelEnabled) {
            return $this->compressAllQualitiesSequential($inputPath, $paths);
        }
        
        $this->log("Parallel compression enabled - starting 4 concurrent FFmpeg processes", [
            'warning' => 'High resource usage expected'
        ]);
        
        $results = [];
        $processes = [];
        $tempFiles = [];
        
        // Start all compression processes in parallel
        foreach (self::QUALITY_PRESETS as $quality => $preset) {
            $outputPath = $paths["compressed_{$quality}"];
            $tempOutputFile = sys_get_temp_dir() . "/ffmpeg_output_{$quality}_" . uniqid() . ".log";
            $tempFiles[$quality] = $tempOutputFile;
            
            $command = $this->buildFfmpegCommand($inputPath, $outputPath, $preset, $quality);
            
            $this->log("Starting {$quality} compression in background", [
                'output' => basename($outputPath),
                'resolution' => "{$preset['width']}x{$preset['height']}",
                'bitrate' => $preset['bitrate']
            ]);
            
            // Start process in background
            $descriptorspec = [
                0 => ["pipe", "r"],
                1 => ["file", $tempOutputFile, "w"],
                2 => ["file", $tempOutputFile, "a"]
            ];
            
            $process = proc_open($command, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                fclose($pipes[0]);
                $processes[$quality] = [
                    'process' => $process,
                    'output_path' => $outputPath,
                    'start_time' => microtime(true),
                    'temp_file' => $tempOutputFile
                ];
            } else {
                $results[$quality] = [
                    'success' => false,
                    'error' => "Failed to start FFmpeg process for {$quality}",
                    'time' => 0
                ];
            }
        }
        
        // Wait for all processes to complete and gather results
        foreach ($processes as $quality => $processData) {
            $status = proc_get_status($processData['process']);
            $exitCode = -1;
            
            // Wait for process to finish (with timeout of 10 minutes per quality)
            $timeout = 600;
            $waited = 0;
            while ($status['running']) {
                usleep(100000); // 100ms sleep
                $waited += 0.1;
                if ($waited >= $timeout) {
                    // Force terminate if timeout
                    proc_terminate($processData['process'], 9);
                    $this->log("FFmpeg {$quality} terminated due to timeout", ['timeout' => $timeout . 's'], 'ERROR');
                    break;
                }
                $status = proc_get_status($processData['process']);
            }
            
            // Get exit code from proc_get_status (more reliable than proc_close)
            // Note: exitcode is only set once the process has terminated
            if (isset($status['exitcode']) && $status['exitcode'] !== -1) {
                $exitCode = $status['exitcode'];
            }
            
            // Close the process handle
            $closeResult = proc_close($processData['process']);
            
            // If we didn't get exit code from status, use proc_close result
            if ($exitCode === -1) {
                $exitCode = $closeResult;
            }
            
            $elapsed = microtime(true) - $processData['start_time'];
            
            // Get FFmpeg output
            $output = '';
            if (file_exists($processData['temp_file'])) {
                $output = file_get_contents($processData['temp_file']);
                @unlink($processData['temp_file']);
            }
            
            // Verify output file was created and is valid
            $outputValid = file_exists($processData['output_path']) && 
                           filesize($processData['output_path']) > 1024;
            
            if ($exitCode === 0 && $outputValid) {
                $results[$quality] = [
                    'success' => true,
                    'time' => $elapsed,
                    'output' => $output
                ];
                
                $this->log("{$quality} compression completed", [
                    'time' => number_format($elapsed, 2) . 's',
                    'size' => $this->formatBytes(filesize($processData['output_path'])),
                    'exit_code' => $exitCode
                ]);
            } else {
                // Clean up partial/corrupted output file
                if (file_exists($processData['output_path'])) {
                    @unlink($processData['output_path']);
                }
                
                $results[$quality] = [
                    'success' => false,
                    'error' => "FFmpeg failed for {$quality}: exit_code={$exitCode}, output_valid={$outputValid}, " . substr($output, -300),
                    'time' => $elapsed
                ];
                
                $this->log("{$quality} compression failed", [
                    'exit_code' => $exitCode,
                    'output_valid' => $outputValid,
                    'time' => number_format($elapsed, 2) . 's',
                    'error_summary' => substr($output, -200)
                ], 'ERROR');
            }
        }
        
        return $results;
    }
    
    /**
     * Compress video to all quality levels SEQUENTIALLY
     * 
     * Runs one FFmpeg process at a time to prevent resource exhaustion.
     * Recommended for VPS environments with limited CPU/RAM.
     * 
     * @param string $inputPath Path to source video
     * @param array $paths Array of output paths
     * @return array Results for each quality level
     */
    private function compressAllQualitiesSequential($inputPath, $paths) {
        $results = [];
        
        $this->log("Starting sequential compression (resource-safe mode)", [
            'qualities' => array_keys(self::QUALITY_PRESETS)
        ]);
        
        foreach (self::QUALITY_PRESETS as $quality => $preset) {
            $outputPath = $paths["compressed_{$quality}"];
            
            $this->log("Compressing {$quality}...", [
                'output' => basename($outputPath),
                'resolution' => "{$preset['width']}x{$preset['height']}",
                'bitrate' => $preset['bitrate']
            ]);
            
            $startTime = microtime(true);
            $command = $this->buildFfmpegCommand($inputPath, $outputPath, $preset, $quality);
            
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            
            $elapsed = microtime(true) - $startTime;
            $outputStr = implode("\n", $output);
            
            $outputValid = file_exists($outputPath) && filesize($outputPath) > 1024;
            
            if ($returnCode === 0 && $outputValid) {
                $results[$quality] = [
                    'success' => true,
                    'time' => $elapsed,
                    'output' => $outputStr
                ];
                
                $this->log("{$quality} compression completed", [
                    'time' => number_format($elapsed, 2) . 's',
                    'size' => $this->formatBytes(filesize($outputPath)),
                    'exit_code' => $returnCode
                ]);
            } else {
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
                
                $results[$quality] = [
                    'success' => false,
                    'error' => "FFmpeg failed for {$quality}: exit_code={$returnCode}, " . substr($outputStr, -300),
                    'time' => $elapsed
                ];
                
                $this->log("{$quality} compression failed", [
                    'exit_code' => $returnCode,
                    'output_valid' => $outputValid,
                    'time' => number_format($elapsed, 2) . 's',
                    'error_summary' => substr($outputStr, -200)
                ], 'ERROR');
            }
        }
        
        return $results;
    }
    
    /**
     * Build FFmpeg command for specific quality level
     * 
     * Includes GOP alignment flags for HLS compatibility:
     * - -g 48: Keyframe every 48 frames (~2 seconds at 24fps)
     * - -keyint_min 48: Minimum keyframe interval
     * - -sc_threshold 0: Disable scene change detection for consistent keyframes
     * 
     * These flags ensure HLS segments align perfectly across quality levels
     * for smooth Adaptive Bitrate (ABR) switching.
     */
    private function buildFfmpegCommand($inputPath, $outputPath, $preset, $quality) {
        $ffmpeg = $this->config['ffmpeg_binary'];
        
        $command = sprintf(
            '%s -y -i %s -vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2" ' .
            '-c:v libx264 -preset medium -crf 23 -b:v %s -maxrate %s -bufsize %s ' .
            '-g 48 -keyint_min 48 -sc_threshold 0 ' .
            '-c:a aac -b:a 128k -ar 44100 -ac 2 ' .
            '-map 0:v:0 -map 0:a:0? ' .
            '-movflags +faststart ' .
            '%s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($inputPath),
            $preset['width'],
            $preset['height'],
            $preset['width'],
            $preset['height'],
            $preset['bitrate'],
            $preset['maxrate'],
            $preset['bufsize'],
            escapeshellarg($outputPath)
        );
        
        return $command;
    }
    
    /**
     * Build file paths for processing
     */
    private function buildPaths($postId, $year, $month, $wpMediaPath) {
        // Strict validation - must be exact integers, not strings
        if (!ctype_digit((string)$postId) || $postId <= 0) {
            throw new Exception("Invalid postId: must be positive integer");
        }
        
        if (!ctype_digit((string)$year) || $year < 2000 || $year > 2100) {
            throw new Exception("Invalid year: must be between 2000-2100");
        }
        
        if (!ctype_digit((string)$month) || $month < 1 || $month > 12) {
            throw new Exception("Invalid month: must be between 1-12");
        }
        
        // Sanitize and validate wpMediaPath
        $wpMediaPath = $this->sanitizePath($wpMediaPath);
        
        // Convert WP path to actual file system path
        $sourcePath = $this->resolveSourcePath($wpMediaPath);
        
        // Validate source path is within allowed directory
        $uploadsDir = $this->config['media_uploads_dir'];
        $realSourcePath = realpath($sourcePath);
        $realUploadsDir = realpath($uploadsDir);
        
        if ($realSourcePath === false) {
            throw new Exception("Source file does not exist: {$wpMediaPath}");
        }
        
        if ($realUploadsDir === false) {
            throw new Exception("Uploads directory does not exist: {$uploadsDir}");
        }
        
        // Security: Ensure source is within uploads directory (prevent path traversal)
        if (strpos($realSourcePath, $realUploadsDir) !== 0) {
            throw new Exception("Source path is outside allowed uploads directory");
        }
        
        // Output directory: /var/www/media/content/YYYY/MM/POST_ID/
        $contentDir = $this->config['media_content_dir'];
        $outputDir = sprintf('%s/%04d/%02d/%d', rtrim($contentDir, '/'), $year, $month, $postId);
        
        return [
            'source' => $realSourcePath,
            'output_dir' => $outputDir,
            'original' => $outputDir . '/original.mp4',
            'compressed_480p' => $outputDir . '/compressed_480p.mp4',
            'compressed_360p' => $outputDir . '/compressed_360p.mp4',
            'compressed_240p' => $outputDir . '/compressed_240p.mp4',
            'compressed_144p' => $outputDir . '/compressed_144p.mp4'
        ];
    }
    
    /**
     * Sanitize and normalize path - comprehensive security check
     */
    private function sanitizePath($path) {
        // URL decode multiple times to prevent double-encoding bypass
        $path = rawurldecode(rawurldecode($path));
        
        // Remove null bytes
        $path = str_replace("\0", '', $path);
        
        // Normalize all directory separators to forward slash
        $path = str_replace('\\', '/', $path);
        
        // Remove any occurrence of ../ or ..\\ (after normalization)
        while (strpos($path, '../') !== false || strpos($path, '..\\') !== false) {
            $path = str_replace(['../', '..\\'], '', $path);
        }
        
        // Reject paths with suspicious patterns
        if (preg_match('#(\.\.)|(%2e%2e)|(%252e)|(\x00)#i', $path)) {
            throw new Exception("Path contains forbidden sequences");
        }
        
        return $path;
    }
    
    /**
     * Resolve WordPress media path to filesystem path
     * Handles relative paths, full URLs, and absolute server paths with strict validation
     */
    private function resolveSourcePath($wpMediaPath) {
        $uploadsDir = $this->config['media_uploads_dir'];
        
        // If it's a full URL, extract the path part
        if (preg_match('#^https?://#i', $wpMediaPath)) {
            // Extract path after /wp-content/uploads/
            if (preg_match('#/wp-content/uploads/(.+)$#', $wpMediaPath, $matches)) {
                $relativePath = $matches[1];
            } else {
                throw new Exception("Invalid WordPress media URL: {$wpMediaPath}");
            }
        } 
        // Handle absolute paths (e.g., /var/www/html/wp-content/uploads/...)
        elseif (preg_match('#/wp-content/uploads/(.+)$#', $wpMediaPath, $matches)) {
            $relativePath = $matches[1];
        }
        // Handle relative paths starting with /wp-content/uploads/
        elseif (preg_match('#^/?wp-content/uploads/(.+)$#', $wpMediaPath, $matches)) {
            $relativePath = $matches[1];
        }
        // Already a relative path (just year/month/filename)
        else {
            $relativePath = ltrim($wpMediaPath, '/');
        }
        
        // Additional sanitization on relative path
        $relativePath = $this->sanitizePath($relativePath);
        
        // Build full filesystem path
        $fullPath = rtrim($uploadsDir, '/') . '/' . ltrim($relativePath, '/');
        
        // Canonicalize the path
        $fullPath = str_replace('//', '/', $fullPath);
        
        return $fullPath;
    }
    
    /**
     * Build public URL for file
     * Validates that the file is under media_content_dir before generating URL
     * Returns null if validation fails (for graceful error handling)
     */
    private function buildPublicUrl($filePath) {
        if (empty($this->config['base_url'])) {
            $this->log("buildPublicUrl: Configuration error - base_url is not set", [
                'filePath' => $filePath
            ], 'ERROR');
            return null;
        }
        if (empty($this->config['media_content_dir'])) {
            $this->log("buildPublicUrl: Configuration error - media_content_dir is not set", [
                'filePath' => $filePath
            ], 'ERROR');
            return null;
        }
        
        $baseUrl = $this->config['base_url'];
        $contentDir = $this->config['media_content_dir'];
        
        // Get relative path from content directory
        $realFilePath = realpath($filePath);
        $realContentDir = realpath($contentDir);
        
        if ($realFilePath === false) {
            $this->log("buildPublicUrl: File path does not exist", [
                'filePath' => $filePath
            ], 'WARNING');
            return null;
        }
        
        if ($realContentDir === false) {
            $this->log("buildPublicUrl: Content directory does not exist", [
                'contentDir' => $contentDir
            ], 'WARNING');
            return null;
        }
        
        // Security: Ensure file is within content directory
        if (strpos($realFilePath, $realContentDir) !== 0) {
            $this->log("buildPublicUrl: File is outside media_content_dir (legacy path)", [
                'filePath' => $filePath,
                'realFilePath' => $realFilePath,
                'contentDir' => $contentDir,
                'realContentDir' => $realContentDir
            ], 'WARNING');
            return null;
        }
        
        $relativePath = substr($realFilePath, strlen($realContentDir));
        $publicUrl = rtrim($baseUrl, '/') . '/content' . $relativePath;
        
        $this->log("buildPublicUrl: URL constructed successfully", [
            'filePath' => $filePath,
            'publicUrl' => $publicUrl
        ]);
        
        return $publicUrl;
    }
    
    /**
     * Compress video to 480p using FFmpeg
     * Includes GOP alignment for HLS compatibility
     */
    private function compress480p($inputPath, $outputPath) {
        $startTime = microtime(true);
        
        $ffmpeg = $this->config['ffmpeg_binary'];
        
        // FFmpeg command for 480p compression
        // 854x480 (16:9), 800kbps video, H.264, AAC audio
        // GOP flags (-g 48, -keyint_min 48, -sc_threshold 0) for HLS segment alignment
        // -map 0:a:0? handles videos without audio stream gracefully
        $command = sprintf(
            '%s -y -i %s -vf "scale=854:480:force_original_aspect_ratio=decrease,pad=854:480:(ow-iw)/2:(oh-ih)/2" ' .
            '-c:v libx264 -preset medium -crf 23 -b:v 800k -maxrate 1000k -bufsize 2000k ' .
            '-g 48 -keyint_min 48 -sc_threshold 0 ' .
            '-c:a aac -b:a 128k -ar 44100 -ac 2 ' .
            '-map 0:v:0 -map 0:a:0? ' .
            '-movflags +faststart ' .
            '%s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );
        
        $this->log("FFmpeg compression started", [
            'input' => basename($inputPath),
            'output' => basename($outputPath),
            'target_resolution' => '854x480',
            'target_bitrate' => '800kbps'
        ]);
        
        exec($command, $output, $returnCode);
        
        $elapsed = microtime(true) - $startTime;
        
        if ($returnCode !== 0) {
            $error = implode("\n", $output);
            $this->log("FFmpeg compression failed", [
                'return_code' => $returnCode,
                'duration' => number_format($elapsed, 2) . 's',
                'error_summary' => substr($error, 0, 500)
            ], 'ERROR');
            
            return [
                'success' => false,
                'error' => $error,
                'time' => $elapsed
            ];
        }
        
        // Extract useful info from FFmpeg output
        $outputStr = implode("\n", $output);
        $fps = $this->extractFfmpegInfo($outputStr, 'fps');
        $bitrate = $this->extractFfmpegInfo($outputStr, 'bitrate');
        $size = file_exists($outputPath) ? filesize($outputPath) : 0;
        
        $this->log("FFmpeg compression completed", [
            'duration' => number_format($elapsed, 2) . 's',
            'output_size' => $this->formatBytes($size),
            'fps' => $fps ?: 'N/A',
            'bitrate' => $bitrate ?: 'N/A'
        ]);
        
        return [
            'success' => true,
            'time' => $elapsed,
            'output' => $outputStr
        ];
    }
    
    /**
     * Extract information from FFmpeg output
     */
    private function extractFfmpegInfo($output, $type) {
        if ($type === 'fps') {
            if (preg_match('/(\d+\.?\d*)\s*fps/', $output, $matches)) {
                return $matches[1] . ' fps';
            }
        } elseif ($type === 'bitrate') {
            if (preg_match('/bitrate:\s*(\d+\.?\d*\s*[kM]bits\/s)/', $output, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    /**
     * Get video duration in seconds using ffprobe
     */
    private function getVideoDuration($videoPath) {
        $ffprobe = dirname($this->config['ffmpeg_binary']) . '/ffprobe';
        
        $command = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($videoPath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output[0])) {
            return round((float)$output[0], 2);
        }
        
        return 0;
    }
    
    /**
     * Validate that output video is playable
     */
    private function validateVideo($videoPath) {
        if (!file_exists($videoPath)) {
            return false;
        }
        
        // Check file size (should be > 1KB)
        if (filesize($videoPath) < 1024) {
            $this->log("Validation failed: file too small", ['path' => $videoPath], 'ERROR');
            return false;
        }
        
        // Use ffprobe to validate
        $ffprobe = dirname($this->config['ffmpeg_binary']) . '/ffprobe';
        
        $command = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($videoPath)
        );
        
        exec($command, $output, $returnCode);
        
        // Should return codec name (e.g., "h264")
        $valid = ($returnCode === 0 && !empty($output[0]));
        
        if (!$valid) {
            $this->log("Validation failed: ffprobe check failed", [
                'path' => $videoPath,
                'code' => $returnCode,
                'output' => implode("\n", $output)
            ], 'ERROR');
        }
        
        return $valid;
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Log message to file
     */
    private function log($message, $context = [], $level = 'INFO') {
        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0777, true)) {
                    error_log("[COMPRESSOR] Failed to create log directory: {$logDir}");
                    return false;
                }
                chmod($logDir, 0777);
            }
            
            if (!file_exists($this->logFile)) {
                if (!touch($this->logFile)) {
                    error_log("[COMPRESSOR] Failed to create log file: {$this->logFile}");
                    return false;
                }
                chmod($this->logFile, 0666);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
            $logMessage = "[{$timestamp}] [{$level}] [COMPRESSOR] {$message}{$contextStr}\n";
            
            $result = file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log("[COMPRESSOR] Failed to write to log file: {$this->logFile}");
                return false;
            }
            
            chmod($this->logFile, 0666);
            return true;
        } catch (Exception $e) {
            error_log("[COMPRESSOR] Logging exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean directory contents for reprocessing
     */
    private function cleanDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->cleanDirectory($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        
        $this->log("Cleaned directory for reprocessing", ['dir' => $dir]);
    }
}
