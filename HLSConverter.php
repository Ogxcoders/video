<?php
/**
 * HLS Converter Class
 * Converts MP4 video files to HLS format with adaptive bitrate streaming
 * 
 * Task 11: HLS Streaming Conversion
 * - Converts each quality MP4 to HLS (.m3u8 + .ts segments)
 * - Creates individual playlists: 480p.m3u8, 360p.m3u8, etc.
 * - Generates master.m3u8 playlist (adaptive bitrate)
 * - Uses 6-second segment duration
 */

class HLSConverter {
    private $config;
    private $logFile;
    
    /**
     * HLS quality configuration with bandwidth and resolution info for master playlist
     * Bandwidth values are in bits per second for HLS specification
     */
    private const HLS_QUALITIES = [
        '480p' => [
            'resolution' => '854x480',
            'bandwidth' => 1000000,
            'avg_bandwidth' => 800000,
            'codecs' => 'avc1.64001f,mp4a.40.2'
        ],
        '360p' => [
            'resolution' => '640x360',
            'bandwidth' => 750000,
            'avg_bandwidth' => 600000,
            'codecs' => 'avc1.640015,mp4a.40.2'
        ],
        '240p' => [
            'resolution' => '426x240',
            'bandwidth' => 500000,
            'avg_bandwidth' => 400000,
            'codecs' => 'avc1.640015,mp4a.40.2'
        ],
        '144p' => [
            'resolution' => '256x144',
            'bandwidth' => 250000,
            'avg_bandwidth' => 200000,
            'codecs' => 'avc1.64000c,mp4a.40.2'
        ]
    ];
    
    const HLS_SEGMENT_DURATION = 6;
    
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
     * Convert compressed MP4 files to HLS format
     * 
     * @param array $paths Array containing paths to compressed MP4 files
     * @param int $postId WordPress post ID for URL generation
     * @param int $year Year for URL path
     * @param int $month Month for URL path
     * @return array Result with success status and HLS URLs
     */
    public function convertToHLS($paths, $postId, $year, $month) {
        $this->log("Starting HLS conversion for post: {$postId}");
        
        try {
            $outputDir = $paths['output_dir'];
            $hlsDir = $outputDir . '/hls';
            
            if (!is_dir($hlsDir)) {
                mkdir($hlsDir, 0755, true);
                chmod($hlsDir, 0755);
                $this->log("Created HLS directory: {$hlsDir}");
            }
            
            $convertedQualities = [];
            $availableQualities = [];
            
            foreach (['480p', '360p', '240p', '144p'] as $quality) {
                $mp4Path = $paths["compressed_{$quality}"] ?? null;
                
                if (!$mp4Path) {
                    $this->log("Skipping {$quality} - path not provided in paths array", [
                        'available_paths' => array_keys($paths)
                    ], 'WARNING');
                    continue;
                }
                
                if (!file_exists($mp4Path)) {
                    $this->log("Skipping {$quality} - MP4 file does not exist on disk", [
                        'expected_path' => $mp4Path,
                        'file_exists' => false
                    ], 'WARNING');
                    continue;
                }
                
                $this->log("Converting {$quality} to HLS...", ['source' => basename($mp4Path)]);
                
                $result = $this->convertQualityToHLS($mp4Path, $hlsDir, $quality);
                
                if ($result['success']) {
                    $convertedQualities[$quality] = $result;
                    $availableQualities[] = $quality;
                    $this->log("{$quality} HLS conversion completed", [
                        'playlist' => $result['playlist'],
                        'segments' => $result['segment_count']
                    ]);
                } else {
                    $this->log("{$quality} HLS conversion failed", ['error' => $result['error']], 'ERROR');
                }
            }
            
            if (empty($convertedQualities)) {
                throw new Exception("No quality levels were successfully converted to HLS");
            }
            
            $this->log("Generating master.m3u8 playlist...", [
                'qualities' => $availableQualities
            ]);
            
            $masterResult = $this->generateMasterPlaylist($hlsDir, $convertedQualities);
            
            if (!$masterResult['success']) {
                throw new Exception("Failed to generate master playlist: " . $masterResult['error']);
            }
            
            $hlsMasterUrl = $this->buildHLSUrl($postId, $year, $month, 'master.m3u8');
            
            $hlsUrls = [
                'master' => $hlsMasterUrl
            ];
            
            foreach ($availableQualities as $quality) {
                $hlsUrls[$quality] = $this->buildHLSUrl($postId, $year, $month, "{$quality}.m3u8");
            }
            
            $this->log("HLS conversion completed successfully", [
                'master_url' => $hlsMasterUrl,
                'qualities' => $availableQualities,
                'total_segments' => array_sum(array_column($convertedQualities, 'segment_count'))
            ]);
            
            return [
                'success' => true,
                'hls_master_url' => $hlsMasterUrl,
                'hls_urls' => $hlsUrls,
                'hls_dir' => $hlsDir,
                'qualities' => $availableQualities,
                'segment_duration' => self::HLS_SEGMENT_DURATION
            ];
            
        } catch (Exception $e) {
            $this->log("HLS conversion failed: " . $e->getMessage(), [], 'ERROR');
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Convert a single quality MP4 to HLS format
     * 
     * @param string $mp4Path Path to source MP4 file
     * @param string $hlsDir Output directory for HLS files
     * @param string $quality Quality level (480p, 360p, etc.)
     * @return array Result with success status
     */
    private function convertQualityToHLS($mp4Path, $hlsDir, $quality) {
        try {
            $playlistPath = $hlsDir . "/{$quality}.m3u8";
            $segmentPattern = $hlsDir . "/{$quality}_%03d.ts";
            
            $ffmpeg = $this->config['ffmpeg_binary'];
            
            $command = sprintf(
                '%s -y -i %s ' .
                '-c copy ' .
                '-hls_time %d ' .
                '-hls_list_size 0 ' .
                '-hls_segment_filename %s ' .
                '-hls_playlist_type vod ' .
                '-hls_flags independent_segments ' .
                '%s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($mp4Path),
                self::HLS_SEGMENT_DURATION,
                escapeshellarg($segmentPattern),
                escapeshellarg($playlistPath)
            );
            
            $this->log("Executing HLS conversion for {$quality}", ['command' => substr($command, 0, 200) . '...']);
            
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                return [
                    'success' => false,
                    'error' => "FFmpeg failed with code {$returnCode}: " . implode("\n", array_slice($output, -5))
                ];
            }
            
            if (!file_exists($playlistPath)) {
                return [
                    'success' => false,
                    'error' => "Playlist file not created: {$playlistPath}"
                ];
            }
            
            $segments = glob($hlsDir . "/{$quality}_*.ts");
            $segmentCount = count($segments);
            
            if ($segmentCount === 0) {
                return [
                    'success' => false,
                    'error' => "No HLS segments created for {$quality}"
                ];
            }
            
            chmod($playlistPath, 0644);
            foreach ($segments as $segment) {
                chmod($segment, 0644);
            }
            
            return [
                'success' => true,
                'playlist' => $playlistPath,
                'segment_count' => $segmentCount,
                'quality' => $quality
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate master.m3u8 playlist for adaptive bitrate streaming
     * 
     * @param string $hlsDir Directory containing quality playlists
     * @param array $convertedQualities Successfully converted qualities
     * @return array Result with success status
     */
    private function generateMasterPlaylist($hlsDir, $convertedQualities) {
        try {
            $masterPath = $hlsDir . '/master.m3u8';
            
            $content = "#EXTM3U\n";
            $content .= "#EXT-X-VERSION:3\n";
            $content .= "\n";
            
            // List qualities from lowest to highest bandwidth for faster playback startup
            // HLS players typically start with the first stream in the playlist, so listing
            // 144p first allows faster initial playback (~3s vs 12s). ABR (Adaptive Bitrate)
            // will automatically switch to higher qualities as bandwidth allows.
            $qualityOrder = ['144p', '240p', '360p', '480p'];
            
            foreach ($qualityOrder as $quality) {
                if (!isset($convertedQualities[$quality])) {
                    continue;
                }
                
                $config = self::HLS_QUALITIES[$quality];
                
                $content .= sprintf(
                    "#EXT-X-STREAM-INF:BANDWIDTH=%d,AVERAGE-BANDWIDTH=%d,RESOLUTION=%s,CODECS=\"%s\",NAME=\"%s\"\n",
                    $config['bandwidth'],
                    $config['avg_bandwidth'],
                    $config['resolution'],
                    $config['codecs'],
                    $quality
                );
                $content .= "{$quality}.m3u8\n";
            }
            
            $result = file_put_contents($masterPath, $content);
            
            if ($result === false) {
                return [
                    'success' => false,
                    'error' => "Failed to write master playlist to {$masterPath}"
                ];
            }
            
            chmod($masterPath, 0644);
            
            $this->log("Master playlist generated", [
                'path' => $masterPath,
                'size' => strlen($content),
                'qualities' => array_keys($convertedQualities)
            ]);
            
            return [
                'success' => true,
                'path' => $masterPath
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Build public URL for HLS files
     * 
     * @param int $postId WordPress post ID
     * @param int $year Year
     * @param int $month Month
     * @param string $filename HLS filename (e.g., master.m3u8)
     * @return string Public URL
     */
    private function buildHLSUrl($postId, $year, $month, $filename) {
        $baseUrl = rtrim($this->config['base_url'], '/');
        return sprintf(
            '%s/content/%04d/%02d/%d/hls/%s',
            $baseUrl,
            $year,
            $month,
            $postId,
            $filename
        );
    }
    
    /**
     * Check if HLS files already exist for a given output directory
     * 
     * @param string $outputDir The output directory path
     * @return bool True if master.m3u8 exists
     */
    public function hlsExists($outputDir) {
        $masterPath = $outputDir . '/hls/master.m3u8';
        return file_exists($masterPath);
    }
    
    /**
     * Get existing HLS URLs if already converted
     * 
     * @param string $outputDir The output directory path
     * @param int $postId WordPress post ID
     * @param int $year Year
     * @param int $month Month
     * @return array|null HLS URLs or null if not exists
     */
    public function getExistingHLSUrls($outputDir, $postId, $year, $month) {
        $hlsDir = $outputDir . '/hls';
        $masterPath = $hlsDir . '/master.m3u8';
        
        if (!file_exists($masterPath)) {
            return null;
        }
        
        $hlsUrls = [
            'master' => $this->buildHLSUrl($postId, $year, $month, 'master.m3u8')
        ];
        
        foreach (['480p', '360p', '240p', '144p'] as $quality) {
            $playlistPath = $hlsDir . "/{$quality}.m3u8";
            if (file_exists($playlistPath)) {
                $hlsUrls[$quality] = $this->buildHLSUrl($postId, $year, $month, "{$quality}.m3u8");
            }
        }
        
        return $hlsUrls;
    }
    
    /**
     * Log message to file
     */
    private function log($message, $context = [], $level = 'INFO') {
        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0777, true)) {
                    error_log("[HLS-CONVERTER] Failed to create log directory: {$logDir}");
                    return false;
                }
                chmod($logDir, 0777);
            }
            
            if (!file_exists($this->logFile)) {
                if (!touch($this->logFile)) {
                    error_log("[HLS-CONVERTER] Failed to create log file: {$this->logFile}");
                    return false;
                }
                chmod($this->logFile, 0666);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
            $logMessage = "[{$timestamp}] [{$level}] [HLS-CONVERTER] {$message}{$contextStr}\n";
            
            $result = file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log("[HLS-CONVERTER] Failed to write to log file: {$this->logFile}");
                return false;
            }
            
            chmod($this->logFile, 0666);
            return true;
        } catch (Exception $e) {
            error_log("[HLS-CONVERTER] Logging exception: " . $e->getMessage());
            return false;
        }
    }
}
