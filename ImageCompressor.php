<?php
/**
 * Image Compressor Class
 * Handles compression of images to WebP format at 22% quality (88% compression)
 * Supports: JPG, JPEG, PNG, GIF, WebP, BMP, TIFF
 */

class ImageCompressor {
    private $config;
    private $logFile;
    
    private $supportedFormats = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff'
    ];
    
    public function __construct($config = []) {
        $defaultConfig = require __DIR__ . '/config.php';
        $this->config = array_merge($defaultConfig, $config);
        $this->logFile = $this->config['log_file'];
        
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
            chmod($logDir, 0777);
        }
    }
    
    /**
     * Download image from URL to local uploads directory
     * 
     * @param string $imageUrl Full URL to the image file
     * @param string $wpThumbnailPath WordPress media path (for determining relative path)
     * @return array Result with 'success', 'local_path', and optionally 'error'
     */
    public function downloadImageFromUrl($imageUrl, $wpThumbnailPath = '') {
        $this->log("Starting image download from URL", [
            'url' => $imageUrl,
            'wpThumbnailPath' => $wpThumbnailPath
        ]);
        
        $validationResult = $this->validateDownloadUrl($imageUrl);
        if (!$validationResult['valid']) {
            $this->log("URL validation failed", [
                'url' => $imageUrl,
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
        
        if (!empty($wpThumbnailPath)) {
            if (preg_match('#/wp-content/uploads/(.+)$#', $wpThumbnailPath, $matches)) {
                $relativePath = $matches[1];
            } elseif (preg_match('#^/?wp-content/uploads/(.+)$#', $wpThumbnailPath, $matches)) {
                $relativePath = $matches[1];
            } else {
                $relativePath = ltrim($wpThumbnailPath, '/');
            }
        } else {
            $parsedUrl = parse_url($imageUrl, PHP_URL_PATH);
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
        
        $this->log("Downloading image to local path", [
            'url' => $imageUrl,
            'local_path' => $localPath
        ]);
        
        $ch = curl_init($imageUrl);
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
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ImageCompressor/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: image/*,*/*',
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        curl_close($ch);
        fclose($fp);
        
        if (!empty($effectiveUrl) && $effectiveUrl !== $imageUrl) {
            $finalValidation = $this->validateDownloadUrl($effectiveUrl);
            if (!$finalValidation['valid']) {
                @unlink($localPath);
                $this->log("Final URL validation failed after redirect", [
                    'original_url' => $imageUrl,
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
            $this->log("Image download failed", [
                'url' => $imageUrl,
                'http_code' => $httpCode,
                'curl_error' => $error
            ], 'ERROR');
            
            return [
                'success' => false,
                'error' => "Download failed: HTTP {$httpCode}" . ($error ? " - {$error}" : "")
            ];
        }
        
        $fileSize = filesize($localPath);
        if ($fileSize < 100) {
            @unlink($localPath);
            $this->log("Downloaded file too small", [
                'url' => $imageUrl,
                'size' => $fileSize
            ], 'ERROR');
            
            return [
                'success' => false,
                'error' => "Downloaded file is too small ({$fileSize} bytes) - may not be a valid image"
            ];
        }
        
        chmod($localPath, 0644);
        
        $this->log("Image downloaded successfully", [
            'url' => $imageUrl,
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
            
            // Support wildcard domains (e.g., *.replit.dev)
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
     * Compress image to WebP format at 22% quality (88% compression)
     * 
     * @param array $jobData Job data containing image paths
     * @return array Result with success status and output paths
     */
    public function compressImage($jobData) {
        $this->log("Starting image compression for job: " . ($jobData['jobId'] ?? 'unknown'));
        
        try {
            $postId = $jobData['postId'];
            $wpThumbnailPath = $jobData['wpThumbnailPath'] ?? null;
            $wpThumbnailUrl = $jobData['wpThumbnailUrl'] ?? '';
            $year = $jobData['year'];
            $month = $jobData['month'];
            $reprocess = $jobData['reprocess'] ?? false;
            
            if (empty($wpThumbnailPath) && empty($wpThumbnailUrl)) {
                $this->log("No thumbnail path or URL provided, skipping image compression");
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'No thumbnail provided'
                ];
            }
            
            if (!empty($wpThumbnailUrl)) {
                $this->log("Remote thumbnail URL provided - downloading from WordPress", [
                    'wpThumbnailUrl' => $wpThumbnailUrl,
                    'wpThumbnailPath' => $wpThumbnailPath,
                    'jobId' => $jobData['jobId'] ?? 'unknown'
                ]);
                
                $downloadResult = $this->downloadImageFromUrl($wpThumbnailUrl, $wpThumbnailPath);
                
                if (!$downloadResult['success']) {
                    $this->log("Thumbnail download failed - continuing without thumbnail", [
                        'error' => $downloadResult['error'],
                        'jobId' => $jobData['jobId'] ?? 'unknown'
                    ], 'WARNING');
                    
                    return [
                        'success' => true,
                        'skipped' => true,
                        'message' => 'Thumbnail download failed: ' . $downloadResult['error']
                    ];
                }
                
                $wpThumbnailPath = $downloadResult['relative_path'];
                $this->log("Thumbnail downloaded successfully - proceeding with compression", [
                    'local_path' => $downloadResult['local_path'],
                    'relative_path' => $wpThumbnailPath,
                    'file_size' => $this->formatBytes($downloadResult['file_size']),
                    'jobId' => $jobData['jobId'] ?? 'unknown'
                ]);
            }
            
            $paths = $this->buildPaths($postId, $year, $month, $wpThumbnailPath);
            
            $this->log("Image paths configured", [
                'source' => $paths['source'],
                'output_dir' => $paths['output_dir'],
                'original' => $paths['original'],
                'webp' => $paths['thumbnail_webp'],
                'reprocess' => $reprocess
            ]);
            
            if (!$reprocess && file_exists($paths['thumbnail_webp']) && $this->validateWebP($paths['thumbnail_webp'])) {
                $this->log("Reprocess=false and valid WebP exists, skipping thumbnail compression");
                
                $webpSize = filesize($paths['thumbnail_webp']);
                $sourceSize = file_exists($paths['source']) ? filesize($paths['source']) : 0;
                $dimensions = $this->getImageDimensions($paths['thumbnail_webp']);
                
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Existing WebP thumbnail found (reprocess=false)',
                    'paths' => [
                        'thumbnail_webp' => $paths['thumbnail_webp'],
                        'output_dir' => $paths['output_dir']
                    ],
                    'urls' => [
                        'thumbnail_webp' => $this->buildPublicUrl($paths['thumbnail_webp'])
                    ],
                    'stats' => [
                        'webp_size' => $webpSize,
                        'original_size' => $sourceSize,
                        'compression_ratio' => $sourceSize > 0 ? round((($sourceSize - $webpSize) / $sourceSize) * 100, 2) : 0
                    ],
                    'dimensions' => $dimensions,
                    'jobId' => $jobData['jobId'] ?? 'unknown',
                    'postId' => $postId
                ];
            }
            
            if ($reprocess && (file_exists($paths['thumbnail_webp']) || file_exists($paths['original']))) {
                $this->log("Reprocess=true, cleaning existing thumbnail files...");
                $this->cleanThumbnailFiles($paths);
            }
            
            if (!file_exists($paths['source'])) {
                throw new Exception("Source image not found: {$paths['source']}");
            }
            
            if (!is_readable($paths['source'])) {
                throw new Exception("Source image is not readable: {$paths['source']}");
            }
            
            $sourceSize = filesize($paths['source']);
            $format = $this->detectImageFormat($paths['source']);
            
            $this->log("Source image found", [
                'size' => $this->formatBytes($sourceSize),
                'format' => $format
            ]);
            
            // Check if source is already WebP format - skip compression if valid
            if ($format === 'webp') {
                $this->log("DEBUG: Source image is already WebP format, checking validity...", [
                    'source_path' => $paths['source'],
                    'jobId' => $jobData['jobId'] ?? 'unknown'
                ]);
                
                if ($this->validateWebP($paths['source'])) {
                    $this->log("Source is valid WebP - copying directly to output (skipping compression)", [
                        'source' => $paths['source'],
                        'destination' => $paths['thumbnail_webp'],
                        'jobId' => $jobData['jobId'] ?? 'unknown'
                    ]);
                    
                    // Create output directory if needed
                    if (!is_dir($paths['output_dir'])) {
                        mkdir($paths['output_dir'], 0755, true);
                        $this->log("Created output directory: {$paths['output_dir']}");
                    }
                    
                    // Copy source WebP directly to thumbnail_webp path
                    if (!copy($paths['source'], $paths['thumbnail_webp'])) {
                        throw new Exception("Failed to copy WebP source to output");
                    }
                    chmod($paths['thumbnail_webp'], 0644);
                    
                    // Also copy to original path for consistency
                    if (!copy($paths['source'], $paths['original'])) {
                        throw new Exception("Failed to copy WebP source to original path");
                    }
                    chmod($paths['original'], 0644);
                    
                    $webpSize = filesize($paths['thumbnail_webp']);
                    $dimensions = $this->getImageDimensions($paths['thumbnail_webp']);
                    $thumbnailUrl = $this->buildPublicUrl($paths['thumbnail_webp']);
                    
                    $this->log("WebP source directly copied successfully", [
                        'thumbnail_webp_path' => $paths['thumbnail_webp'],
                        'thumbnail_webp_url' => $thumbnailUrl,
                        'size' => $this->formatBytes($webpSize),
                        'jobId' => $jobData['jobId'] ?? 'unknown'
                    ]);
                    
                    return [
                        'success' => true,
                        'paths' => [
                            'original' => $paths['original'],
                            'thumbnail_webp' => $paths['thumbnail_webp'],
                            'output_dir' => $paths['output_dir']
                        ],
                        'urls' => [
                            'thumbnail_webp' => $thumbnailUrl
                        ],
                        'stats' => [
                            'original_size' => $sourceSize,
                            'webp_size' => $webpSize,
                            'compression_ratio' => 0,
                            'original_format' => 'webp',
                            'processing_time' => 0,
                            'webp_source_copied' => true
                        ],
                        'dimensions' => $dimensions,
                        'jobId' => $jobData['jobId'] ?? 'unknown',
                        'postId' => $postId
                    ];
                } else {
                    $this->log("Source WebP failed validation - will attempt recompression", [
                        'source' => $paths['source'],
                        'jobId' => $jobData['jobId'] ?? 'unknown'
                    ], 'WARNING');
                }
            }
            
            if (!is_dir($paths['output_dir'])) {
                mkdir($paths['output_dir'], 0755, true);
                $this->log("Created output directory: {$paths['output_dir']}");
            }
            
            $this->log("Copying original image to output directory...");
            if (!copy($paths['source'], $paths['original'])) {
                throw new Exception("Failed to copy original image");
            }
            chmod($paths['original'], 0644);
            $this->log("Original image copied successfully");
            
            $this->log("Starting WebP compression at 22% quality (88% compression)...");
            $compressionResult = $this->compressToWebP($paths['original'], $paths['thumbnail_webp']);
            
            if (!$compressionResult['success']) {
                throw new Exception("WebP compression failed: " . $compressionResult['error']);
            }
            
            $this->log("WebP compression completed", [
                'time' => number_format($compressionResult['time'], 2) . 's'
            ]);
            
            if (!file_exists($paths['thumbnail_webp'])) {
                throw new Exception("WebP file not created");
            }
            
            $webpSize = filesize($paths['thumbnail_webp']);
            
            if (!$this->validateWebP($paths['thumbnail_webp'])) {
                throw new Exception("Output WebP is corrupted or invalid");
            }
            
            $this->log("WebP output validated successfully");
            
            $compressionRatio = (($sourceSize - $webpSize) / $sourceSize) * 100;
            
            $stats = [
                'original_size' => $sourceSize,
                'webp_size' => $webpSize,
                'compression_ratio' => round($compressionRatio, 2),
                'original_format' => $format,
                'processing_time' => $compressionResult['time']
            ];
            
            $this->log("Image compression stats", $stats);
            
            $dimensions = $this->getImageDimensions($paths['thumbnail_webp']);
            
            $result = [
                'success' => true,
                'paths' => [
                    'original' => $paths['original'],
                    'thumbnail_webp' => $paths['thumbnail_webp'],
                    'output_dir' => $paths['output_dir']
                ],
                'urls' => [
                    'thumbnail_webp' => $this->buildPublicUrl($paths['thumbnail_webp'])
                ],
                'stats' => $stats,
                'dimensions' => $dimensions,
                'jobId' => $jobData['jobId'] ?? 'unknown',
                'postId' => $postId
            ];
            
            $this->log("Image compression job completed successfully", ['jobId' => $jobData['jobId'] ?? 'unknown']);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log("Image compression failed: " . $e->getMessage(), ['jobId' => $jobData['jobId'] ?? 'unknown'], 'ERROR');
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'jobId' => $jobData['jobId'] ?? 'unknown'
            ];
        }
    }
    
    /**
     * Build file paths for image processing
     */
    private function buildPaths($postId, $year, $month, $wpThumbnailPath) {
        if (!ctype_digit((string)$postId) || $postId <= 0) {
            throw new Exception("Invalid postId: must be positive integer");
        }
        
        if (!ctype_digit((string)$year) || $year < 2000 || $year > 2100) {
            throw new Exception("Invalid year: must be between 2000-2100");
        }
        
        if (!ctype_digit((string)$month) || $month < 1 || $month > 12) {
            throw new Exception("Invalid month: must be between 1-12");
        }
        
        $wpThumbnailPath = $this->sanitizePath($wpThumbnailPath);
        
        $sourcePath = $this->resolveSourcePath($wpThumbnailPath);
        
        $uploadsDir = $this->config['media_uploads_dir'];
        $realSourcePath = realpath($sourcePath);
        $realUploadsDir = realpath($uploadsDir);
        
        if ($realSourcePath === false) {
            throw new Exception("Source image does not exist: {$wpThumbnailPath}");
        }
        
        if ($realUploadsDir === false) {
            throw new Exception("Uploads directory does not exist: {$uploadsDir}");
        }
        
        if (strpos($realSourcePath, $realUploadsDir) !== 0) {
            throw new Exception("Source path is outside allowed uploads directory");
        }
        
        $ext = strtolower(pathinfo($realSourcePath, PATHINFO_EXTENSION));
        
        $contentDir = $this->config['media_content_dir'];
        $outputDir = sprintf('%s/%04d/%02d/%d', rtrim($contentDir, '/'), $year, $month, $postId);
        
        return [
            'source' => $realSourcePath,
            'output_dir' => $outputDir,
            'original' => $outputDir . '/original_thumbnail.' . $ext,
            'thumbnail_webp' => $outputDir . '/thumbnail.webp'
        ];
    }
    
    /**
     * Sanitize and normalize path
     */
    private function sanitizePath($path) {
        $path = rawurldecode(rawurldecode($path));
        $path = str_replace("\0", '', $path);
        $path = str_replace('\\', '/', $path);
        
        while (strpos($path, '../') !== false || strpos($path, '..\\') !== false) {
            $path = str_replace(['../', '..\\'], '', $path);
        }
        
        if (preg_match('#(\.\.)|(%2e%2e)|(%252e)|(\x00)#i', $path)) {
            throw new Exception("Path contains forbidden sequences");
        }
        
        return $path;
    }
    
    /**
     * Resolve WordPress media path to filesystem path
     * Handles relative paths, full URLs, and absolute server paths with strict validation
     */
    private function resolveSourcePath($wpThumbnailPath) {
        $uploadsDir = $this->config['media_uploads_dir'];
        
        // If it's a full URL, extract the path part
        if (preg_match('#^https?://#i', $wpThumbnailPath)) {
            if (preg_match('#/wp-content/uploads/(.+)$#', $wpThumbnailPath, $matches)) {
                $relativePath = $matches[1];
            } else {
                throw new Exception("Invalid WordPress media URL: {$wpThumbnailPath}");
            }
        } 
        // Handle absolute paths (e.g., /var/www/html/wp-content/uploads/...)
        elseif (preg_match('#/wp-content/uploads/(.+)$#', $wpThumbnailPath, $matches)) {
            $relativePath = $matches[1];
        }
        // Handle relative paths starting with /wp-content/uploads/
        elseif (preg_match('#^/?wp-content/uploads/(.+)$#', $wpThumbnailPath, $matches)) {
            $relativePath = $matches[1];
        }
        // Already a relative path (just year/month/filename)
        else {
            $relativePath = ltrim($wpThumbnailPath, '/');
        }
        
        $relativePath = $this->sanitizePath($relativePath);
        
        $fullPath = rtrim($uploadsDir, '/') . '/' . ltrim($relativePath, '/');
        $fullPath = str_replace('//', '/', $fullPath);
        
        return $fullPath;
    }
    
    /**
     * Detect image format from file
     */
    private function detectImageFormat($imagePath) {
        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        
        if (isset($this->supportedFormats[$ext])) {
            return $ext;
        }
        
        if (function_exists('exif_imagetype')) {
            $type = @exif_imagetype($imagePath);
            $typeMap = [
                IMAGETYPE_JPEG => 'jpeg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_WEBP => 'webp',
                IMAGETYPE_BMP => 'bmp',
                IMAGETYPE_TIFF_II => 'tiff',
                IMAGETYPE_TIFF_MM => 'tiff'
            ];
            
            if (isset($typeMap[$type])) {
                return $typeMap[$type];
            }
        }
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($imagePath);
        
        $mimeToFormat = array_flip($this->supportedFormats);
        if (isset($mimeToFormat[$mime])) {
            return $mimeToFormat[$mime];
        }
        
        throw new Exception("Unsupported image format: {$ext} (MIME: {$mime})");
    }
    
    /**
     * Compress image to WebP using FFmpeg at 22% quality (88% compression)
     */
    private function compressToWebP($inputPath, $outputPath) {
        $startTime = microtime(true);
        
        $ffmpeg = $this->config['ffmpeg_binary'];
        
        $command = sprintf(
            '%s -y -i %s -c:v libwebp -quality 22 -compression_level 6 -preset photo %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );
        
        $this->log("FFmpeg WebP conversion started", [
            'input' => basename($inputPath),
            'output' => basename($outputPath),
            'quality' => '22%',
            'preset' => 'photo'
        ]);
        
        exec($command, $output, $returnCode);
        
        $elapsed = microtime(true) - $startTime;
        
        if ($returnCode !== 0) {
            $error = implode("\n", $output);
            $this->log("FFmpeg WebP conversion failed", [
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
        
        chmod($outputPath, 0644);
        
        $size = file_exists($outputPath) ? filesize($outputPath) : 0;
        
        $this->log("FFmpeg WebP conversion completed", [
            'duration' => number_format($elapsed, 2) . 's',
            'output_size' => $this->formatBytes($size)
        ]);
        
        return [
            'success' => true,
            'time' => $elapsed,
            'output' => implode("\n", $output)
        ];
    }
    
    /**
     * Validate WebP output
     */
    private function validateWebP($webpPath) {
        if (!file_exists($webpPath)) {
            return false;
        }
        
        if (filesize($webpPath) < 100) {
            $this->log("Validation failed: WebP file too small", ['path' => $webpPath], 'ERROR');
            return false;
        }
        
        $handle = fopen($webpPath, 'rb');
        if (!$handle) {
            return false;
        }
        
        $header = fread($handle, 12);
        fclose($handle);
        
        if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
            $this->log("Validation failed: Invalid WebP header", ['path' => $webpPath], 'ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Get image dimensions
     */
    private function getImageDimensions($imagePath) {
        $ffprobe = dirname($this->config['ffmpeg_binary']) . '/ffprobe';
        
        $command = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($imagePath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output[0])) {
            $parts = explode(',', $output[0]);
            if (count($parts) === 2) {
                return [
                    'width' => (int)$parts[0],
                    'height' => (int)$parts[1]
                ];
            }
        }
        
        if (function_exists('getimagesize')) {
            $size = @getimagesize($imagePath);
            if ($size) {
                return [
                    'width' => $size[0],
                    'height' => $size[1]
                ];
            }
        }
        
        return ['width' => 0, 'height' => 0];
    }
    
    /**
     * Build public URL for file
     * Constructs URL in format: {base_url}/content/{YYYY}/{MM}/{POST_ID}/filename
     * 
     * Uses string-based path matching instead of realpath() for reliability
     * This ensures URLs are generated even if file system timing issues occur
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
        
        $baseUrl = rtrim($this->config['base_url'], '/');
        $contentDir = rtrim($this->config['media_content_dir'], '/');
        
        // Normalize file path (handle both absolute and relative paths)
        $normalizedFilePath = str_replace('\\', '/', $filePath);
        $normalizedContentDir = str_replace('\\', '/', $contentDir);
        
        // Try realpath first for existing files
        $realFilePath = realpath($filePath);
        $realContentDir = realpath($contentDir);
        
        if ($realFilePath !== false && $realContentDir !== false) {
            // File exists - use realpath for accurate matching
            if (strpos($realFilePath, $realContentDir) === 0) {
                $relativePath = substr($realFilePath, strlen($realContentDir));
                $relativePath = str_replace('\\', '/', $relativePath);
                $publicUrl = $baseUrl . '/content' . $relativePath;
                
                $this->log("buildPublicUrl: URL constructed (realpath)", [
                    'filePath' => $filePath,
                    'publicUrl' => $publicUrl
                ]);
                
                return $publicUrl;
            }
        }
        
        // Fallback: String-based path matching (for newly created files or permission issues)
        if (strpos($normalizedFilePath, $normalizedContentDir) === 0) {
            $relativePath = substr($normalizedFilePath, strlen($normalizedContentDir));
            $publicUrl = $baseUrl . '/content' . $relativePath;
            
            $this->log("buildPublicUrl: URL constructed (string match)", [
                'filePath' => $filePath,
                'publicUrl' => $publicUrl
            ]);
            
            return $publicUrl;
        }
        
        // Additional fallback: Extract path pattern from file path
        // Look for pattern like /media/content/YYYY/MM/POST_ID/filename
        if (preg_match('#/media/content(/\d{4}/\d{2}/\d+/.+)$#', $normalizedFilePath, $matches)) {
            $relativePath = $matches[1];
            $publicUrl = $baseUrl . '/content' . $relativePath;
            
            $this->log("buildPublicUrl: URL constructed (pattern match)", [
                'filePath' => $filePath,
                'publicUrl' => $publicUrl
            ]);
            
            return $publicUrl;
        }
        
        $this->log("buildPublicUrl: Could not construct URL - path not in content directory", [
            'filePath' => $filePath,
            'normalizedFilePath' => $normalizedFilePath,
            'contentDir' => $contentDir
        ], 'WARNING');
        
        return null;
    }
    
    /**
     * Clean existing thumbnail files for reprocessing
     */
    private function cleanThumbnailFiles($paths) {
        $filesToClean = [
            $paths['thumbnail_webp'] ?? null,
            $paths['original'] ?? null
        ];
        
        foreach ($filesToClean as $file) {
            if ($file && file_exists($file)) {
                if (unlink($file)) {
                    $this->log("Deleted existing file: {$file}");
                } else {
                    $this->log("Failed to delete file: {$file}", [], 'WARNING');
                }
            }
        }
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
                    error_log("[IMAGE-COMPRESSOR] Failed to create log directory: {$logDir}");
                    return false;
                }
                chmod($logDir, 0777);
            }
            
            if (!file_exists($this->logFile)) {
                if (!touch($this->logFile)) {
                    error_log("[IMAGE-COMPRESSOR] Failed to create log file: {$this->logFile}");
                    return false;
                }
                chmod($this->logFile, 0666);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
            $logMessage = "[{$timestamp}] [{$level}] [IMAGE-COMPRESSOR] {$message}{$contextStr}\n";
            
            $result = file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log("[IMAGE-COMPRESSOR] Failed to write to log file: {$this->logFile}");
                return false;
            }
            
            chmod($this->logFile, 0666);
            return true;
        } catch (Exception $e) {
            error_log("[IMAGE-COMPRESSOR] Logging exception: " . $e->getMessage());
            return false;
        }
    }
}
