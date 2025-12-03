<?php
/**
 * Professional Admin Dashboard for Web B Video Processing API
 * Version 2.0 - Task 12: Processing Status Dashboard
 */

// Gracefully handle any autoload errors before they become fatal
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Only handle fatal-like errors
    if (strpos($errstr, 'require_once') !== false || strpos($errstr, 'Failed to open stream') !== false) {
        error_log("[DASHBOARD] Autoload error suppressed: {$errstr}");
        return true; // Suppress the error
    }
    return false; // Let other errors through
});

try {
    require_once __DIR__ . '/RedisQueue.php';
} catch (Throwable $e) {
    error_log("[DASHBOARD] Failed to load RedisQueue: " . $e->getMessage());
    // Continue - RedisQueue should work without autoloader if native Redis is available
}

restore_error_handler();

$config = require __DIR__ . '/config.php';

// Get configuration validation warnings
$configWarnings = getConfigValidationWarnings($config);

session_start();

$adminPassword = getenv('ADMIN_PASSWORD');

if (empty($adminPassword)) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Dashboard Not Configured</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f7fa;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
            }
            .error-box {
                background: white;
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                max-width: 600px;
            }
            h1 { color: #dc3545; }
            code {
                background: #f8f9fa;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: monospace;
            }
            .step {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 15px 0;
            }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>⚠️ Dashboard Not Configured</h1>
            <p>The admin password has not been set. For security, you must configure a password before accessing the dashboard.</p>
            
            <h3>Setup Instructions:</h3>
            
            <div class="step">
                <strong>Option 1: Environment Variable (Recommended)</strong><br>
                Set the <code>ADMIN_PASSWORD</code> environment variable:
                <pre>export ADMIN_PASSWORD="your-secure-password-here"</pre>
            </div>
            
            <div class="step">
                <strong>Option 2: Apache .htaccess</strong><br>
                Add to your .htaccess file:
                <pre>SetEnv ADMIN_PASSWORD "your-secure-password-here"</pre>
            </div>
            
            <div class="step">
                <strong>Option 3: Docker/Coolify</strong><br>
                Add environment variable in your container configuration:
                <pre>ADMIN_PASSWORD=your-secure-password-here</pre>
            </div>
            
            <p style="margin-top: 20px;"><strong>Generate a secure password:</strong></p>
            <pre style="background: #f8f9fa; padding: 10px; border-radius: 5px;">openssl rand -base64 24</pre>
            
            <p style="color: #dc3545; margin-top: 20px;">
                <strong>Security Notice:</strong> Never use simple or default passwords. Always use strong, randomly generated passwords for production systems.
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (!isset($_SESSION['dashboard_authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $adminPassword) {
            $_SESSION['dashboard_authenticated'] = true;
            header('Location: dashboard.php');
            exit;
        } else {
            $loginError = 'Invalid password';
        }
    }
    
    if (!isset($_SESSION['dashboard_authenticated'])) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Admin Login - Video Processing API</title>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                }
                .login-box {
                    background: white;
                    padding: 40px;
                    border-radius: 10px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                    max-width: 400px;
                    width: 100%;
                }
                h1 { color: #667eea; margin-bottom: 10px; }
                .subtitle { color: #666; margin-bottom: 30px; }
                input[type="password"] {
                    width: 100%;
                    padding: 12px;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                    font-size: 16px;
                    margin-bottom: 15px;
                }
                button {
                    width: 100%;
                    padding: 12px;
                    background: #667eea;
                    color: white;
                    border: none;
                    border-radius: 5px;
                    font-size: 16px;
                    cursor: pointer;
                    font-weight: 600;
                }
                button:hover { background: #764ba2; }
                .error { color: #dc3545; margin-bottom: 15px; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h1>Admin Login</h1>
                <div class="subtitle">Video Processing API Dashboard</div>
                <?php if (isset($loginError)): ?>
                    <div class="error"><?php echo htmlspecialchars($loginError); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="Enter admin password" required autofocus>
                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: dashboard.php');
    exit;
}

function getDirectorySize($dir) {
    $size = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getVideoCount($dir) {
    $count = 0;
    if (is_dir($dir)) {
        $files = new DirectoryIterator($dir);
        foreach ($files as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['mp4', 'webm', 'mov', 'avi'])) {
                $count++;
            }
        }
    }
    return $count;
}

function getHLSFolderCount($dir) {
    $count = 0;
    if (is_dir($dir)) {
        $folders = new DirectoryIterator($dir);
        foreach ($folders as $folder) {
            if ($folder->isDir() && !$folder->isDot()) {
                $count++;
            }
        }
    }
    return $count;
}

function getRecentVideos($hlsDir, $config, $limit = 5) {
    $videos = [];
    
    // Scan old HLS directory (legacy path)
    if (is_dir($hlsDir)) {
        $folders = new DirectoryIterator($hlsDir);
        foreach ($folders as $folder) {
            if ($folder->isDir() && !$folder->isDot()) {
                $masterFile = $folder->getPathname() . '/master.m3u8';
                if (file_exists($masterFile)) {
                    $videos[] = [
                        'id' => $folder->getFilename(),
                        'path' => $folder->getPathname(),
                        'url' => str_replace($hlsDir, $config['hls_url_base'], $folder->getPathname()),
                        'size' => getDirectorySize($folder->getPathname()),
                        'created' => $folder->getCTime(),
                        'source' => 'legacy_hls'
                    ];
                }
            }
        }
    }
    
    // Scan new media/content directory (new path: /content/YYYY/MM/POSTID/)
    $contentDir = $config['media_content_dir'] ?? __DIR__ . '/media/content';
    if (is_dir($contentDir)) {
        // Build URL exactly like VideoCompressor::buildPublicUrl() does:
        // rtrim($baseUrl, '/') . '/content' . $relativePath
        $baseUrl = rtrim($config['base_url'] ?? '', '/');
        $realContentDir = realpath($contentDir);
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($contentDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $hlsSubDir = $file->getPathname() . '/hls';
                $masterFile = $hlsSubDir . '/master.m3u8';
                
                if (file_exists($masterFile)) {
                    // Get relative path from content directory (includes leading slash)
                    $realFilePath = realpath($file->getPathname());
                    if ($realContentDir !== false && $realFilePath !== false) {
                        $relativePath = substr($realFilePath, strlen($realContentDir));
                        
                        // URL format matches VideoCompressor::buildPublicUrl() exactly:
                        // {base_url}/content{relativePath}/hls/master.m3u8
                        $videos[] = [
                            'id' => 'Post ' . basename($file->getPathname()),
                            'path' => $file->getPathname(),
                            'url' => $baseUrl . '/content' . $relativePath . '/hls/master.m3u8',
                            'size' => getDirectorySize($file->getPathname()),
                            'created' => filemtime($masterFile),
                            'source' => 'media_content',
                            'has_thumbnail' => file_exists($file->getPathname() . '/thumbnail.webp'),
                            'qualities' => getAvailableQualities($file->getPathname())
                        ];
                    }
                }
            }
        }
    }
    
    usort($videos, function($a, $b) {
        return $b['created'] - $a['created'];
    });
    
    return array_slice($videos, 0, $limit);
}

function getAvailableQualities($postDir) {
    $qualities = [];
    foreach (['480p', '360p', '240p', '144p'] as $quality) {
        if (file_exists($postDir . "/compressed_{$quality}.mp4")) {
            $qualities[] = $quality;
        }
    }
    return $qualities;
}

function getMediaContentStats($contentDir) {
    $stats = [
        'total_posts' => 0,
        'total_size' => 0,
        'videos_count' => 0,  // Only counts compressed MP4s, not segments or originals
        'thumbnails_count' => 0,
        'hls_count' => 0
    ];
    
    if (!is_dir($contentDir)) {
        return $stats;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($contentDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $postDirs = [];
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $stats['total_size'] += $file->getSize();
            $filename = $file->getFilename();
            $ext = strtolower($file->getExtension());
            
            // Only count compressed MP4s (compressed_480p.mp4, compressed_360p.mp4, etc.)
            // Exclude original.mp4, HLS .ts segments, and other video files
            if ($ext === 'mp4' && preg_match('/^compressed_\d+p\.mp4$/', $filename)) {
                $stats['videos_count']++;
            } elseif ($filename === 'thumbnail.webp') {
                // Only count the final thumbnail, not original_thumbnail files
                $stats['thumbnails_count']++;
            } elseif ($filename === 'master.m3u8') {
                $stats['hls_count']++;
            }
            // Note: .ts segment files and other files contribute to total_size but not counts
        } elseif ($file->isDir()) {
            // Check if this is a post directory (has compressed video or HLS)
            $path = $file->getPathname();
            if (file_exists($path . '/compressed_480p.mp4') || file_exists($path . '/hls/master.m3u8')) {
                $postDirs[$path] = true;
            }
        }
    }
    
    $stats['total_posts'] = count($postDirs);
    
    return $stats;
}

/**
 * Get all processed posts with their complete file structure and URLs
 * This provides detailed debugging information for URL issues
 */
function getAllProcessedPosts($contentDir, $baseUrl, $limit = 20) {
    $posts = [];
    
    if (!is_dir($contentDir)) {
        return $posts;
    }
    
    $realContentDir = realpath($contentDir);
    $baseUrl = rtrim($baseUrl, '/');
    
    // Find all post directories (pattern: /YYYY/MM/POST_ID/)
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($contentDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $postDirectories = [];
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            $path = $file->getPathname();
            // Check if this looks like a post directory (has video or HLS files)
            if (file_exists($path . '/compressed_480p.mp4') || 
                file_exists($path . '/hls/master.m3u8') ||
                file_exists($path . '/thumbnail.webp')) {
                
                $realPath = realpath($path);
                // Validate realpath succeeded and path is within content directory
                if ($realPath === false || strpos($realPath, $realContentDir) !== 0) {
                    continue; // Skip paths outside content directory or invalid paths
                }
                
                $relativePath = substr($realPath, strlen($realContentDir));
                
                // Extract year, month, post_id from path
                if (preg_match('#/(\d{4})/(\d{2})/(\d+)$#', $relativePath, $matches)) {
                    $postDirectories[] = [
                        'path' => $path,
                        'relative_path' => $relativePath,
                        'year' => $matches[1],
                        'month' => $matches[2],
                        'post_id' => $matches[3],
                        'mtime' => filemtime($path)
                    ];
                }
            }
        }
    }
    
    // Sort by modification time (newest first)
    usort($postDirectories, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });
    
    // Limit results
    $postDirectories = array_slice($postDirectories, 0, $limit);
    
    // Build detailed file info for each post
    foreach ($postDirectories as $postDir) {
        $path = $postDir['path'];
        $relativePath = $postDir['relative_path'];
        $urlBase = $baseUrl . '/content' . $relativePath;
        
        $post = [
            'post_id' => $postDir['post_id'],
            'year' => $postDir['year'],
            'month' => $postDir['month'],
            'path' => $path,
            'url_base' => $urlBase,
            'total_size' => 0,
            'files' => []
        ];
        
        // Check for all expected files
        $expectedFiles = [
            'original.mp4' => 'Original Video',
            'compressed_480p.mp4' => '480p Video',
            'compressed_360p.mp4' => '360p Video',
            'compressed_240p.mp4' => '240p Video',
            'compressed_144p.mp4' => '144p Video',
            'original_thumbnail.jpg' => 'Original Thumbnail',
            'original_thumbnail.jpeg' => 'Original Thumbnail (JPEG)',
            'original_thumbnail.png' => 'Original Thumbnail (PNG)',
            'thumbnail.webp' => 'Compressed Thumbnail (WebP)',
            'hls/master.m3u8' => 'HLS Master Playlist',
            'hls/480p.m3u8' => 'HLS 480p Playlist',
            'hls/360p.m3u8' => 'HLS 360p Playlist',
            'hls/240p.m3u8' => 'HLS 240p Playlist',
            'hls/144p.m3u8' => 'HLS 144p Playlist'
        ];
        
        foreach ($expectedFiles as $file => $description) {
            $filePath = $path . '/' . $file;
            if (file_exists($filePath)) {
                $size = filesize($filePath);
                $post['total_size'] += $size;
                $post['files'][$file] = [
                    'exists' => true,
                    'description' => $description,
                    'size' => $size,
                    'size_formatted' => formatBytes($size),
                    'url' => $urlBase . '/' . $file,
                    'mtime' => date('Y-m-d H:i:s', filemtime($filePath))
                ];
            } else {
                $post['files'][$file] = [
                    'exists' => false,
                    'description' => $description,
                    'url' => $urlBase . '/' . $file
                ];
            }
        }
        
        // Scan HLS segments with details
        $hlsDir = $path . '/hls';
        $post['hls_segments'] = 0;
        $post['hls_segments_size'] = 0;
        $post['hls_segment_files'] = [];
        
        if (is_dir($hlsDir)) {
            $tsFiles = glob($hlsDir . '/*.ts');
            $post['hls_segments'] = count($tsFiles);
            
            // Get details for each segment (limit to first 10 for display)
            $segmentCount = 0;
            foreach ($tsFiles as $tsFile) {
                $segmentSize = filesize($tsFile);
                $post['hls_segments_size'] += $segmentSize;
                $post['total_size'] += $segmentSize;
                
                if ($segmentCount < 10) {
                    $segmentName = basename($tsFile);
                    $post['hls_segment_files'][] = [
                        'name' => $segmentName,
                        'size' => $segmentSize,
                        'size_formatted' => formatBytes($segmentSize),
                        'url' => $urlBase . '/hls/' . $segmentName,
                        'mtime' => date('Y-m-d H:i:s', filemtime($tsFile))
                    ];
                }
                $segmentCount++;
            }
        }
        
        // Add creation date for post directory
        $post['created_at'] = date('Y-m-d H:i:s', $postDir['mtime']);
        
        $posts[] = $post;
    }
    
    return $posts;
}

function getLogs($logFile, $lines = 50) {
    if (!file_exists($logFile)) {
        return [];
    }
    
    $file = new SplFileObject($logFile);
    $file->seek(PHP_INT_MAX);
    $totalLines = $file->key();
    
    $start = max(0, $totalLines - $lines);
    $file->seek($start);
    
    $logs = [];
    while (!$file->eof()) {
        $line = trim($file->current());
        if ($line) {
            $logs[] = $line;
        }
        $file->next();
    }
    
    return array_reverse($logs);
}

function getDirectoryTree($dir, $baseUrl = '', $maxDepth = 3, $currentDepth = 0) {
    $tree = [];
    if (!is_dir($dir) || $currentDepth >= $maxDepth) {
        return $tree;
    }
    
    try {
        $iterator = new DirectoryIterator($dir);
        foreach ($iterator as $item) {
            if ($item->isDot()) continue;
            
            $name = $item->getFilename();
            $path = $item->getPathname();
            $isDir = $item->isDir();
            
            $entry = [
                'name' => $name,
                'path' => $path,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : $item->getSize(),
                'mtime' => $item->getMTime(),
                'url' => $baseUrl ? $baseUrl . '/' . $name : ''
            ];
            
            if ($isDir) {
                $entry['children'] = getDirectoryTree($path, $entry['url'], $maxDepth, $currentDepth + 1);
                $entry['size'] = array_reduce($entry['children'], function($sum, $child) {
                    return $sum + ($child['is_dir'] ? $child['size'] : $child['size']);
                }, 0);
            }
            
            $tree[] = $entry;
        }
        
        usort($tree, function($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $b['is_dir'] - $a['is_dir'];
            }
            return strcmp($a['name'], $b['name']);
        });
    } catch (Exception $e) {
        error_log("Error reading directory {$dir}: " . $e->getMessage());
    }
    
    return $tree;
}

function renderDirectoryTree($tree, $baseDir = '', $level = 0) {
    if (empty($tree)) {
        return '<div class="no-files">No files found</div>';
    }
    
    $html = '<ul class="file-tree' . ($level === 0 ? ' root' : '') . '">';
    foreach ($tree as $item) {
        $isDir = $item['is_dir'];
        $name = htmlspecialchars($item['name']);
        $size = formatBytes($item['size']);
        $mtime = date('Y-m-d H:i', $item['mtime']);
        $relativePath = str_replace($baseDir, '', $item['path']);
        
        $html .= '<li class="' . ($isDir ? 'directory' : 'file') . '">';
        
        if ($isDir) {
            $hasChildren = !empty($item['children']);
            $html .= '<div class="tree-item folder-header' . ($hasChildren ? ' has-children' : '') . '">';
            $html .= '<span class="toggle-icon">' . ($hasChildren ? '▶' : '•') . '</span>';
            $html .= '<span class="folder-icon">📁</span>';
            $html .= '<span class="item-name">' . $name . '</span>';
            $html .= '<span class="item-meta">' . $size . '</span>';
            $html .= '</div>';
            
            if ($hasChildren) {
                $html .= '<div class="folder-contents collapsed">';
                $html .= renderDirectoryTree($item['children'], $baseDir, $level + 1);
                $html .= '</div>';
            }
        } else {
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $icon = '📄';
            if (in_array($extension, ['mp4', 'webm', 'mov', 'avi'])) $icon = '🎬';
            elseif (in_array($extension, ['m3u8', 'ts'])) $icon = '📺';
            elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $icon = '🖼️';
            elseif (in_array($extension, ['json'])) $icon = '📋';
            
            $html .= '<div class="tree-item file-item">';
            $html .= '<span class="file-icon">' . $icon . '</span>';
            if (!empty($item['url'])) {
                $html .= '<a href="' . htmlspecialchars($item['url']) . '" target="_blank" class="item-name file-link">' . $name . '</a>';
            } else {
                $html .= '<span class="item-name">' . $name . '</span>';
            }
            $html .= '<span class="item-meta">' . $size . ' • ' . $mtime . '</span>';
            $html .= '</div>';
        }
        
        $html .= '</li>';
    }
    $html .= '</ul>';
    
    return $html;
}

function formatElapsedTime($startTime) {
    $start = strtotime($startTime);
    $elapsed = time() - $start;
    
    if ($elapsed < 60) {
        return $elapsed . 's';
    } elseif ($elapsed < 3600) {
        return floor($elapsed / 60) . 'm ' . ($elapsed % 60) . 's';
    } else {
        $hours = floor($elapsed / 3600);
        $minutes = floor(($elapsed % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

function formatProcessingTime($seconds) {
    if ($seconds < 60) {
        return round($seconds, 1) . 's';
    } elseif ($seconds < 3600) {
        return floor($seconds / 60) . 'm ' . round($seconds % 60) . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

function getProcessingJobs($redis, $processingQueueName = 'compression_processing') {
    $jobs = [];
    try {
        $length = $redis->lLen($processingQueueName);
        for ($i = 0; $i < $length; $i++) {
            $item = $redis->lIndex($processingQueueName, $i);
            if ($item) {
                $jobData = json_decode($item, true);
                if ($jobData) {
                    $jobId = $jobData['jobId'] ?? 'unknown';
                    $jobInfo = $redis->hGetAll("job:{$jobId}");
                    $jobs[] = [
                        'jobId' => $jobId,
                        'postId' => $jobData['postId'] ?? null,
                        'data' => $jobData,
                        'status' => $jobInfo['status'] ?? 'processing',
                        'started_at' => $jobInfo['updated_at'] ?? date('Y-m-d H:i:s'),
                        'attempts' => $jobInfo['attempts'] ?? 1
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error getting processing jobs: " . $e->getMessage());
    }
    return $jobs;
}

function getCompletedJobs($redis, $limit = 50) {
    $jobs = [];
    try {
        $completedJobIds = $redis->sMembers('completed_jobs');
        
        if (empty($completedJobIds)) {
            return $jobs;
        }
        
        $jobsWithTimestamp = [];
        foreach ($completedJobIds as $jobId) {
            $jobInfo = $redis->hGetAll("job:{$jobId}");
            if (!empty($jobInfo)) {
                $data = isset($jobInfo['data']) ? json_decode($jobInfo['data'], true) : [];
                $result = isset($jobInfo['result']) ? json_decode($jobInfo['result'], true) : [];
                
                $processingTime = null;
                if (isset($result['processing_time'])) {
                    $processingTime = $result['processing_time'];
                } elseif (isset($jobInfo['created_at']) && isset($jobInfo['completed_at'])) {
                    $processingTime = strtotime($jobInfo['completed_at']) - strtotime($jobInfo['created_at']);
                }
                
                $compressionRatio = null;
                if (isset($result['compression_ratio'])) {
                    $compressionRatio = $result['compression_ratio'];
                } elseif (isset($result['original_size']) && isset($result['compressed_size']) && $result['original_size'] > 0) {
                    $compressionRatio = round((1 - ($result['compressed_size'] / $result['original_size'])) * 100, 1);
                }
                
                $jobsWithTimestamp[] = [
                    'jobId' => $jobId,
                    'postId' => $data['postId'] ?? null,
                    'completed_at' => $jobInfo['completed_at'] ?? $jobInfo['updated_at'] ?? null,
                    'created_at' => $jobInfo['created_at'] ?? null,
                    'processing_time' => $processingTime,
                    'compression_ratio' => $compressionRatio,
                    'result' => $result,
                    'timestamp' => strtotime($jobInfo['completed_at'] ?? $jobInfo['updated_at'] ?? 'now')
                ];
            }
        }
        
        usort($jobsWithTimestamp, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        $jobs = array_slice($jobsWithTimestamp, 0, $limit);
        
    } catch (Exception $e) {
        error_log("Error getting completed jobs: " . $e->getMessage());
    }
    return $jobs;
}

function getAverageProcessingTime($completedJobs) {
    $validTimes = array_filter(array_column($completedJobs, 'processing_time'), function($time) {
        return $time !== null && $time > 0;
    });
    
    if (empty($validTimes)) {
        return null;
    }
    
    return array_sum($validTimes) / count($validTimes);
}

exec(escapeshellarg($config['ffmpeg_binary']) . ' -version 2>&1', $ffmpegOutput, $ffmpegStatus);
$ffmpegInstalled = ($ffmpegStatus === 0);
$ffmpegVersion = $ffmpegInstalled ? $ffmpegOutput[0] : 'Not installed';

$videosSize = getDirectorySize($config['videos_dir']);
$hlsSize = getDirectorySize($config['hls_dir']);
$videoCount = getVideoCount($config['videos_dir']);
$hlsCount = getHLSFolderCount($config['hls_dir']);

// Get media content stats (new path)
$mediaContentDir = $config['media_content_dir'] ?? __DIR__ . '/media/content';
$mediaContentStats = getMediaContentStats($mediaContentDir);

// Combined totals
$totalSize = $videosSize + $hlsSize + $mediaContentStats['total_size'];
$totalProcessedVideos = $hlsCount + $mediaContentStats['hls_count'];

$recentVideos = getRecentVideos($config['hls_dir'], $config, 10);
$recentLogs = getLogs($config['log_file'], 100);

$redisQueue = new RedisQueue([
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    'log_file' => $config['log_file']
]);

$redisConnected = $redisQueue->isConnected();
$queueStats = $redisQueue->getStats();
$processingJobs = [];
$completedJobs = [];
$avgProcessingTime = null;

if ($redisConnected) {
    try {
        $redis = new Redis();
        $redis->pconnect(
            getenv('REDIS_HOST') ?: '127.0.0.1',
            (int)(getenv('REDIS_PORT') ?: 6379),
            2.5
        );
        
        $processingJobs = getProcessingJobs($redis);
        $completedJobs = getCompletedJobs($redis, 50);
        $avgProcessingTime = getAverageProcessingTime($completedJobs);
    } catch (Exception $e) {
        error_log("Redis connection error in dashboard: " . $e->getMessage());
    }
}

$wpAdminBaseUrl = 'https://ogtemplate.com/wp-admin/post.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Dashboard - Video Processing</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .header .subtitle {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .queue-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stat-card.pending { border-left: 4px solid #ffc107; }
        .stat-card.processing { border-left: 4px solid #17a2b8; }
        .stat-card.completed { border-left: 4px solid #28a745; }
        .stat-card.failed { border-left: 4px solid #dc3545; }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }
        
        .stat-value.pending { color: #ffc107; }
        .stat-value.processing { color: #17a2b8; }
        .stat-value.completed { color: #28a745; }
        .stat-value.failed { color: #dc3545; }
        
        .stat-sub {
            color: #999;
            font-size: 13px;
            margin-top: 5px;
        }
        
        .section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .status-ok { background: #46b450; }
        .status-error { background: #dc3545; }
        .status-warning { background: #ffc107; }
        .status-processing { background: #17a2b8; animation: pulse 1.5s infinite; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
            font-size: 13px;
        }
        
        .info-value {
            color: #333;
            margin-top: 5px;
        }
        
        .video-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .video-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .video-item:hover {
            background: #f9f9f9;
        }
        
        .video-id {
            font-family: monospace;
            color: #667eea;
            font-weight: 600;
        }
        
        .video-meta {
            color: #999;
            font-size: 13px;
        }
        
        .current-job {
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            border: 2px solid #667eea40;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .current-job-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .current-job-title {
            font-size: 16px;
            font-weight: 600;
            color: #667eea;
        }
        
        .post-id-large {
            font-size: 48px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .post-id-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .job-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .job-detail-item {
            background: white;
            padding: 12px;
            border-radius: 5px;
        }
        
        .job-detail-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
        }
        
        .job-detail-value {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-top: 3px;
        }
        
        .jobs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .jobs-table th {
            text-align: left;
            padding: 12px;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
            font-weight: 600;
            color: #666;
        }
        
        .jobs-table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .jobs-table tr:hover {
            background: #f9f9f9;
        }
        
        .jobs-table-container {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .wp-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .wp-link:hover {
            text-decoration: underline;
            color: #764ba2;
        }
        
        .log-viewer {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 5px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
        }
        
        .log-line {
            margin-bottom: 5px;
        }
        
        .file-browser-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .file-browser-panel {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .file-browser-panel h3 {
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
            color: #333;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .file-browser-panel h3 .panel-icon {
            font-size: 18px;
        }
        
        .file-browser-panel h3 .panel-size {
            margin-left: auto;
            font-weight: normal;
            color: #666;
            font-size: 12px;
        }
        
        .file-tree {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 13px;
        }
        
        .file-tree ul {
            list-style: none;
            padding-left: 20px;
            margin: 0;
        }
        
        .file-tree li {
            margin: 2px 0;
        }
        
        .tree-item {
            display: flex;
            align-items: center;
            padding: 5px 8px;
            border-radius: 4px;
            cursor: default;
            gap: 6px;
        }
        
        .tree-item:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        
        .folder-header.has-children {
            cursor: pointer;
        }
        
        .toggle-icon {
            font-size: 10px;
            width: 12px;
            color: #666;
            transition: transform 0.2s;
        }
        
        .folder-header.expanded .toggle-icon {
            transform: rotate(90deg);
        }
        
        .folder-icon, .file-icon {
            font-size: 14px;
        }
        
        .item-name {
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #333;
        }
        
        .file-link {
            color: #667eea;
            text-decoration: none;
        }
        
        .file-link:hover {
            text-decoration: underline;
        }
        
        .item-meta {
            font-size: 11px;
            color: #888;
            white-space: nowrap;
        }
        
        .folder-contents {
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .folder-contents.collapsed {
            display: none;
        }
        
        .no-files {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }
        
        .path-label {
            font-family: monospace;
            font-size: 11px;
            color: #888;
            margin-bottom: 10px;
            word-break: break-all;
        }
        
        .post-files-section {
            margin-bottom: 30px;
        }
        
        .post-files-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .post-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        
        .post-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #667eea30;
        }
        
        .post-id-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .post-id-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 18px;
        }
        
        .post-path {
            font-family: monospace;
            font-size: 12px;
            color: #666;
            background: white;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .post-meta {
            display: flex;
            gap: 20px;
            font-size: 13px;
            color: #666;
        }
        
        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
        }
        
        .file-entry {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        
        .file-entry.exists {
            border-left: 3px solid #28a745;
        }
        
        .file-entry.missing {
            border-left: 3px solid #dc3545;
            opacity: 0.6;
        }
        
        .file-name {
            font-family: monospace;
            font-size: 12px;
            color: #333;
        }
        
        .file-meta {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .file-size {
            font-size: 11px;
            color: #888;
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
        }
        
        .file-url-btn {
            background: #667eea;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 10px;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .file-url-btn:hover {
            background: #764ba2;
        }
        
        .file-url-btn.disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .url-debug-box {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            margin-top: 10px;
            word-break: break-all;
        }
        
        .url-debug-box .url-line {
            margin: 5px 0;
            padding: 5px;
            background: rgba(255,255,255,0.05);
            border-radius: 3px;
        }
        
        .url-debug-box .url-label {
            color: #89d185;
            margin-right: 10px;
        }
        
        .url-debug-box .url-value {
            color: #4fc1ff;
        }
        
        .show-urls-btn {
            background: none;
            border: 1px solid #667eea;
            color: #667eea;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .show-urls-btn:hover {
            background: #667eea;
            color: white;
        }
        
        .log-error { color: #f48771; }
        .log-success { color: #89d185; }
        .log-info { color: #4fc1ff; }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #764ba2;
        }
        
        .redis-error {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 40px;
            font-style: italic;
        }
        
        .refresh-note {
            text-align: center;
            color: #999;
            font-size: 13px;
            margin-top: 20px;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        
        .config-debug-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .config-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #667eea;
        }
        
        .config-item.warning {
            border-left-color: #ffc107;
            background: #fff8e6;
        }
        
        .config-item.error {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        
        .config-item.success {
            border-left-color: #28a745;
            background: #f0fff4;
        }
        
        .config-key {
            font-weight: 600;
            color: #333;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .config-value {
            font-family: monospace;
            font-size: 12px;
            color: #555;
            background: rgba(0,0,0,0.05);
            padding: 8px 10px;
            border-radius: 4px;
            word-break: break-all;
            margin-top: 5px;
        }
        
        .config-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            font-size: 12px;
        }
        
        .config-status .exists {
            color: #28a745;
        }
        
        .config-status .missing {
            color: #dc3545;
        }
        
        .validation-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .validation-warning.critical {
            background: #f8d7da;
            border-color: #dc3545;
        }
        
        .validation-warning.error {
            background: #fff5f5;
            border-color: #dc3545;
        }
        
        .validation-warning h4 {
            margin: 0 0 8px 0;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .validation-warning p {
            margin: 5px 0;
            font-size: 13px;
            color: #555;
        }
        
        .validation-warning code {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .test-url-section {
            background: #1e1e1e;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .test-url-section h4 {
            color: #89d185;
            margin: 0 0 15px 0;
            font-size: 14px;
        }
        
        .test-url-item {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: rgba(255,255,255,0.05);
            border-radius: 4px;
        }
        
        .test-url-label {
            color: #4fc1ff;
            font-size: 12px;
            min-width: 150px;
        }
        
        .test-url-value {
            color: #d4d4d4;
            font-family: monospace;
            font-size: 12px;
            word-break: break-all;
            flex: 1;
        }
        
        .test-url-value a {
            color: #89d185;
            text-decoration: none;
        }
        
        .test-url-value a:hover {
            text-decoration: underline;
        }
        
        .media-browser-section {
            margin-top: 30px;
        }
        
        .media-browser-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .media-browser-controls input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            min-width: 200px;
            font-size: 14px;
        }
        
        .media-browser-controls select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .file-type-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .file-type-filter {
            padding: 5px 12px;
            background: #f0f0f0;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .file-type-filter:hover,
        .file-type-filter.active {
            background: #667eea;
            color: white;
        }
        
        .file-type-filter .count {
            background: rgba(0,0,0,0.1);
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
            font-size: 10px;
        }
        
        .expanded-file-list {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .file-group {
            margin-bottom: 25px;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .file-group-header {
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            border-bottom: 1px solid #e9ecef;
        }
        
        .file-group-header:hover {
            background: linear-gradient(135deg, #667eea25 0%, #764ba225 100%);
        }
        
        .file-group-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .file-group-title .year-month {
            font-size: 14px;
            color: #666;
        }
        
        .file-group-title .post-id {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
        }
        
        .file-group-meta {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: #666;
        }
        
        .file-group-body {
            padding: 15px 20px;
            background: white;
        }
        
        .file-list-table {
            width: 100%;
            font-size: 13px;
        }
        
        .file-list-table th {
            text-align: left;
            padding: 8px 10px;
            background: #f8f9fa;
            font-weight: 600;
            color: #666;
            border-bottom: 2px solid #e9ecef;
        }
        
        .file-list-table td {
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .file-list-table tr:hover {
            background: #f9f9f9;
        }
        
        .file-list-table .file-icon {
            font-size: 16px;
            margin-right: 8px;
        }
        
        .file-list-table .file-name-cell {
            font-family: monospace;
            font-size: 12px;
        }
        
        .file-list-table .file-url {
            color: #667eea;
            text-decoration: none;
            font-size: 11px;
            word-break: break-all;
        }
        
        .file-list-table .file-url:hover {
            text-decoration: underline;
        }
        
        .open-btn {
            display: inline-block;
            padding: 4px 10px;
            background: #667eea;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 11px;
            transition: background 0.2s;
        }
        
        .open-btn:hover {
            background: #764ba2;
        }
        
        .open-btn.secondary {
            background: #6c757d;
        }
        
        .open-btn.secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div>
                <h1>🎬 Video Processing API Dashboard</h1>
                <div class="subtitle">Web B Server - v2.0 | Processing Status Dashboard</div>
            </div>
            <a href="?logout" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (!$redisConnected): ?>
        <div class="redis-error">
            <strong>⚠️ Redis Not Available</strong><br>
            Queue statistics are unavailable. Error: <?php echo htmlspecialchars($queueStats['error'] ?? 'Connection failed'); ?>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>📊 Queue Statistics</h2>
            <?php if ($redisConnected): ?>
            <div class="queue-stats-grid">
                <div class="stat-card pending">
                    <div class="stat-label">Pending Jobs</div>
                    <div class="stat-value pending"><?php echo $queueStats['pending'] ?? 0; ?></div>
                    <div class="stat-sub">Waiting in queue</div>
                </div>
                
                <div class="stat-card processing">
                    <div class="stat-label">Processing</div>
                    <div class="stat-value processing"><?php echo $queueStats['processing'] ?? 0; ?></div>
                    <div class="stat-sub">Currently active</div>
                </div>
                
                <div class="stat-card completed">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value completed"><?php echo $queueStats['completed'] ?? 0; ?></div>
                    <div class="stat-sub">Successfully processed</div>
                </div>
                
                <div class="stat-card failed">
                    <div class="stat-label">Failed</div>
                    <div class="stat-value failed"><?php echo $queueStats['failed'] ?? 0; ?></div>
                    <div class="stat-sub">Errors encountered</div>
                </div>
            </div>
            
            <div class="info-grid" style="margin-top: 15px;">
                <div class="info-item">
                    <div class="info-label">Redis Version</div>
                    <div class="info-value"><?php echo htmlspecialchars($queueStats['redis_version'] ?? 'Unknown'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Redis Memory</div>
                    <div class="info-value"><?php echo htmlspecialchars($queueStats['used_memory_human'] ?? 'Unknown'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Average Processing Time</div>
                    <div class="info-value"><?php echo $avgProcessingTime !== null ? formatProcessingTime($avgProcessingTime) : 'N/A'; ?></div>
                </div>
            </div>
            <?php else: ?>
            <div class="no-data">Redis connection required for queue statistics</div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($processingJobs)): ?>
        <div class="section">
            <h2>🔄 Currently Processing</h2>
            <?php foreach ($processingJobs as $job): ?>
            <div class="current-job">
                <div class="current-job-header">
                    <div class="current-job-title">
                        <span class="status-indicator status-processing"></span>
                        Active Job
                    </div>
                    <span class="badge badge-info">Attempt #<?php echo $job['attempts']; ?></span>
                </div>
                
                <div class="post-id-label">WordPress Post ID</div>
                <div class="post-id-large">
                    <?php if ($job['postId']): ?>
                        <a href="<?php echo $wpAdminBaseUrl; ?>?post=<?php echo urlencode($job['postId']); ?>&action=edit" target="_blank" class="wp-link">
                            #<?php echo htmlspecialchars($job['postId']); ?>
                        </a>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </div>
                
                <div class="job-details">
                    <div class="job-detail-item">
                        <div class="job-detail-label">Job ID</div>
                        <div class="job-detail-value"><?php echo htmlspecialchars($job['jobId']); ?></div>
                    </div>
                    <div class="job-detail-item">
                        <div class="job-detail-label">Started At</div>
                        <div class="job-detail-value"><?php echo htmlspecialchars($job['started_at']); ?></div>
                    </div>
                    <div class="job-detail-item">
                        <div class="job-detail-label">Elapsed Time</div>
                        <div class="job-detail-value"><?php echo formatElapsedTime($job['started_at']); ?></div>
                    </div>
                    <div class="job-detail-item">
                        <div class="job-detail-label">Status</div>
                        <div class="job-detail-value"><?php echo htmlspecialchars(ucfirst($job['status'])); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($redisConnected): ?>
        <div class="section">
            <h2>🔄 Currently Processing</h2>
            <div class="no-data">No jobs currently processing</div>
        </div>
        <?php endif; ?>
        
        <?php if ($redisConnected): ?>
        <div class="section">
            <h2>✅ Recent Completed Jobs (Last 50)</h2>
            <?php if (!empty($completedJobs)): ?>
            <div class="jobs-table-container">
                <table class="jobs-table">
                    <thead>
                        <tr>
                            <th>WP Post ID</th>
                            <th>Job ID</th>
                            <th>Processing Time</th>
                            <th>Compression Ratio</th>
                            <th>Completed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completedJobs as $job): ?>
                        <tr>
                            <td>
                                <?php if ($job['postId']): ?>
                                    <a href="<?php echo $wpAdminBaseUrl; ?>?post=<?php echo urlencode($job['postId']); ?>&action=edit" target="_blank" class="wp-link">
                                        #<?php echo htmlspecialchars($job['postId']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($job['jobId']); ?></td>
                            <td>
                                <?php if ($job['processing_time'] !== null): ?>
                                    <?php echo formatProcessingTime($job['processing_time']); ?>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($job['compression_ratio'] !== null): ?>
                                    <span class="badge badge-success"><?php echo $job['compression_ratio']; ?>%</span>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($job['completed_at'] ?? 'Unknown'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="no-data">No completed jobs yet</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Processed Videos (Total)</div>
                <div class="stat-value"><?php echo $totalProcessedVideos; ?></div>
                <div class="stat-sub">New: <?php echo $mediaContentStats['hls_count']; ?> | Legacy: <?php echo $hlsCount; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">MP4 Files</div>
                <div class="stat-value"><?php echo $mediaContentStats['videos_count'] + $videoCount; ?></div>
                <div class="stat-sub">Content: <?php echo $mediaContentStats['videos_count']; ?> | Source: <?php echo $videoCount; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Thumbnails</div>
                <div class="stat-value"><?php echo $mediaContentStats['thumbnails_count']; ?></div>
                <div class="stat-sub">WebP compressed images</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Storage</div>
                <div class="stat-value"><?php echo formatBytes($totalSize); ?></div>
                <div class="stat-sub">Content: <?php echo formatBytes($mediaContentStats['total_size']); ?> | Legacy: <?php echo formatBytes($hlsSize); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">FFmpeg Status</div>
                <div class="stat-value">
                    <span class="status-indicator <?php echo $ffmpegInstalled ? 'status-ok' : 'status-error'; ?>"></span>
                    <?php echo $ffmpegInstalled ? 'Active' : 'Error'; ?>
                </div>
                <div class="stat-sub"><?php echo substr($ffmpegVersion, 0, 50); ?></div>
            </div>
        </div>
        
        <div class="section">
            <h2>System Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">PHP Version</div>
                    <div class="info-value"><?php echo PHP_VERSION; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">FFmpeg Binary</div>
                    <div class="info-value"><?php echo $config['ffmpeg_binary']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Videos Directory</div>
                    <div class="info-value"><?php echo $config['videos_dir']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">HLS Directory (DEPRECATED)</div>
                    <div class="info-value" style="color: #888; text-decoration: line-through;"><?php echo $config['hls_dir']; ?></div>
                    <div style="font-size: 11px; color: #ffc107;">HLS files now stored in /content/.../hls/</div>
                </div>
                <div class="info-item">
                    <div class="info-label">HLS URL Base (DEPRECATED)</div>
                    <div class="info-value" style="color: #888; text-decoration: line-through;"><?php echo $config['hls_url_base']; ?></div>
                    <div style="font-size: 11px; color: #ffc107;">Use /content/{YYYY}/{MM}/{POST_ID}/hls/ path instead</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Parallel Limit</div>
                    <div class="info-value"><?php echo $config['parallel_limit']; ?></div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>⚙️ Configuration Debug</h2>
            <p style="color: #666; margin-bottom: 20px; font-size: 14px;">
                Current configuration settings and environment variable status. Verify these match your VPS deployment.
            </p>
            
            <?php if (!empty($configWarnings)): ?>
            <div style="margin-bottom: 25px;">
                <h3 style="font-size: 16px; margin-bottom: 15px; color: #dc3545;">⚠️ Configuration Warnings</h3>
                <?php foreach ($configWarnings as $warning): ?>
                <div class="validation-warning <?php echo htmlspecialchars($warning['level']); ?>">
                    <h4>
                        <?php 
                        $levelIcon = match($warning['level']) {
                            'critical' => '🚨',
                            'error' => '❌',
                            'warning' => '⚠️',
                            default => 'ℹ️'
                        };
                        echo $levelIcon;
                        ?>
                        <code><?php echo htmlspecialchars($warning['key']); ?></code>
                        <span class="badge badge-<?php echo $warning['level'] === 'warning' ? 'warning' : 'danger'; ?>">
                            <?php echo ucfirst(htmlspecialchars($warning['level'])); ?>
                        </span>
                    </h4>
                    <p><?php echo htmlspecialchars($warning['message']); ?></p>
                    <p><strong>Current value:</strong> <code><?php echo htmlspecialchars($warning['current_value']); ?></code></p>
                    <p><strong>Recommendation:</strong> <?php echo htmlspecialchars($warning['recommendation']); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="padding: 15px; background: #d4edda; border-radius: 8px; margin-bottom: 25px; color: #155724;">
                ✅ All configuration checks passed!
            </div>
            <?php endif; ?>
            
            <div class="config-debug-grid">
                <div class="config-item <?php echo env_get('BASE_URL') ? 'success' : 'warning'; ?>">
                    <div class="config-key">BASE_URL</div>
                    <div class="config-value"><?php echo htmlspecialchars($config['base_url']); ?></div>
                    <div class="config-status">
                        <?php if (env_get('BASE_URL')): ?>
                            <span class="exists">✓ Explicitly set via environment</span>
                        <?php else: ?>
                            <span class="missing">⚠ Using auto-detected domain</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="config-item <?php echo is_dir($config['media_content_dir']) ? 'success' : 'error'; ?>">
                    <div class="config-key">MEDIA_CONTENT_DIR</div>
                    <div class="config-value"><?php echo htmlspecialchars($config['media_content_dir']); ?></div>
                    <div class="config-status">
                        <?php if (is_dir($config['media_content_dir'])): ?>
                            <span class="exists">✓ Directory exists</span>
                        <?php else: ?>
                            <span class="missing">✗ Directory does not exist</span>
                        <?php endif; ?>
                        <?php if (env_get('MEDIA_CONTENT_DIR')): ?>
                            <span class="exists">(explicitly set)</span>
                        <?php else: ?>
                            <span style="color: #666;">(using default)</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="config-item <?php echo is_dir($config['media_uploads_dir']) ? 'success' : 'error'; ?>">
                    <div class="config-key">MEDIA_UPLOADS_DIR</div>
                    <div class="config-value"><?php echo htmlspecialchars($config['media_uploads_dir']); ?></div>
                    <div class="config-status">
                        <?php if (is_dir($config['media_uploads_dir'])): ?>
                            <span class="exists">✓ Directory exists</span>
                        <?php else: ?>
                            <span class="missing">✗ Directory does not exist</span>
                        <?php endif; ?>
                        <?php if (env_get('MEDIA_UPLOADS_DIR')): ?>
                            <span class="exists">(explicitly set)</span>
                        <?php else: ?>
                            <span style="color: #666;">(using default)</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="config-item <?php echo is_dir($config['hls_dir']) ? 'success' : 'warning'; ?>">
                    <div class="config-key">HLS Directory (Legacy)</div>
                    <div class="config-value"><?php echo htmlspecialchars($config['hls_dir']); ?></div>
                    <div class="config-status">
                        <?php if (is_dir($config['hls_dir'])): ?>
                            <span class="exists">✓ Directory exists</span>
                        <?php else: ?>
                            <span class="missing">⚠ Directory does not exist</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="config-item">
                    <div class="config-key">HLS_URL_BASE</div>
                    <div class="config-value"><?php echo htmlspecialchars($config['hls_url_base']); ?></div>
                    <div class="config-status">
                        <?php if (env_get('HLS_URL_BASE')): ?>
                            <span class="exists">✓ Explicitly set</span>
                        <?php else: ?>
                            <span style="color: #666;">(auto-generated from BASE_URL)</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="config-item">
                    <div class="config-key">WordPress Webhook URL</div>
                    <div class="config-value"><?php echo htmlspecialchars($config['wordpress_webhook_url'] ?: '(not set)'); ?></div>
                    <div class="config-status">
                        <?php if ($config['wordpress_webhook_url']): ?>
                            <span class="exists">✓ Configured</span>
                        <?php else: ?>
                            <span style="color: #666;">Not configured - callbacks disabled</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="test-url-section">
                <h4>🔗 Test URL Generation</h4>
                <p style="color: #888; font-size: 12px; margin-bottom: 15px;">
                    These are example URLs that would be generated for a post. Verify they match your server configuration.
                </p>
                
                <?php 
                $testPostId = '12345';
                $testYear = date('Y');
                $testMonth = date('m');
                $testBaseUrl = rtrim($config['base_url'], '/');
                ?>
                
                <div class="test-url-item">
                    <span class="test-url-label">Example Post ID:</span>
                    <span class="test-url-value"><?php echo $testPostId; ?> (<?php echo $testYear; ?>/<?php echo $testMonth; ?>)</span>
                </div>
                
                <div class="test-url-item">
                    <span class="test-url-label">Server File Path:</span>
                    <span class="test-url-value"><?php echo htmlspecialchars($config['media_content_dir'] . '/' . $testYear . '/' . $testMonth . '/' . $testPostId . '/'); ?></span>
                </div>
                
                <div class="test-url-item">
                    <span class="test-url-label">480p Video URL:</span>
                    <span class="test-url-value">
                        <a href="<?php echo htmlspecialchars($testBaseUrl . '/content/' . $testYear . '/' . $testMonth . '/' . $testPostId . '/compressed_480p.mp4'); ?>" target="_blank">
                            <?php echo htmlspecialchars($testBaseUrl . '/content/' . $testYear . '/' . $testMonth . '/' . $testPostId . '/compressed_480p.mp4'); ?>
                        </a>
                    </span>
                </div>
                
                <div class="test-url-item">
                    <span class="test-url-label">HLS Master URL:</span>
                    <span class="test-url-value">
                        <a href="<?php echo htmlspecialchars($testBaseUrl . '/content/' . $testYear . '/' . $testMonth . '/' . $testPostId . '/hls/master.m3u8'); ?>" target="_blank">
                            <?php echo htmlspecialchars($testBaseUrl . '/content/' . $testYear . '/' . $testMonth . '/' . $testPostId . '/hls/master.m3u8'); ?>
                        </a>
                    </span>
                </div>
                
                <div class="test-url-item">
                    <span class="test-url-label">Thumbnail URL:</span>
                    <span class="test-url-value">
                        <a href="<?php echo htmlspecialchars($testBaseUrl . '/content/' . $testYear . '/' . $testMonth . '/' . $testPostId . '/thumbnail.webp'); ?>" target="_blank">
                            <?php echo htmlspecialchars($testBaseUrl . '/content/' . $testYear . '/' . $testMonth . '/' . $testPostId . '/thumbnail.webp'); ?>
                        </a>
                    </span>
                </div>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; font-size: 13px;">
                <strong>📋 VPS Environment Variables Checklist:</strong>
                <pre style="margin-top: 10px; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; overflow-x: auto;">
# Required for correct URL generation
export BASE_URL="https://api.yourdomain.com"

# Required for file storage (usually defaults work)
export MEDIA_CONTENT_DIR="/var/www/html/media/content"
export MEDIA_UPLOADS_DIR="/var/www/html/media/uploads"

# Security (REQUIRED)
export API_KEY="$(openssl rand -base64 32)"
export ADMIN_PASSWORD="$(openssl rand -base64 24)"

# Redis (for queue processing)
export REDIS_HOST="127.0.0.1"
export REDIS_PORT="6379"

# WordPress callback (optional)
export WORDPRESS_WEBHOOK_URL="https://yoursite.com/wp-json/cvp/v1/webhook"
                </pre>
            </div>
        </div>
        
        <div class="section">
            <h2>Recently Processed Videos</h2>
            <div class="video-list">
                <?php if (empty($recentVideos)): ?>
                    <div style="text-align: center; color: #999; padding: 40px;">
                        No processed videos yet
                    </div>
                <?php else: ?>
                    <?php foreach ($recentVideos as $video): ?>
                        <div class="video-item" style="flex-wrap: wrap; gap: 10px;">
                            <div style="flex: 1; min-width: 200px;">
                                <div class="video-id">
                                    <?php echo htmlspecialchars($video['id']); ?>
                                    <?php if (isset($video['source'])): ?>
                                        <span class="badge <?php echo $video['source'] === 'media_content' ? 'badge-success' : 'badge-warning'; ?>" style="font-size: 10px; margin-left: 8px;">
                                            <?php echo $video['source'] === 'media_content' ? 'New Path' : 'Legacy'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="video-meta">
                                    <?php echo formatBytes($video['size']); ?> | 
                                    <?php echo date('Y-m-d H:i:s', $video['created']); ?>
                                </div>
                                <?php if (!empty($video['qualities'])): ?>
                                <div style="margin-top: 5px;">
                                    <?php foreach ($video['qualities'] as $quality): ?>
                                        <span class="badge badge-info" style="font-size: 10px; margin-right: 3px;"><?php echo $quality; ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!empty($video['has_thumbnail'])): ?>
                                        <span class="badge badge-success" style="font-size: 10px; margin-left: 5px;">WebP</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <a href="<?php echo htmlspecialchars($video['url'] . '/master.m3u8'); ?>" target="_blank" class="btn" style="font-size: 11px; padding: 5px 10px;">HLS</a>
                                <?php if ($video['source'] === 'media_content'): ?>
                                    <?php 
                                    $baseVideoUrl = str_replace('/hls', '', $video['url']);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($baseVideoUrl . '/compressed_480p.mp4'); ?>" target="_blank" class="btn" style="font-size: 11px; padding: 5px 10px; background: #28a745;">MP4</a>
                                    <?php if (!empty($video['has_thumbnail'])): ?>
                                        <a href="<?php echo htmlspecialchars($baseVideoUrl . '/thumbnail.webp'); ?>" target="_blank" class="btn" style="font-size: 11px; padding: 5px 10px; background: #17a2b8;">Thumb</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        $mediaContentDir = $config['media_content_dir'] ?? __DIR__ . '/media/content';
        $mediaUploadsDir = $config['media_uploads_dir'] ?? __DIR__ . '/media/uploads';
        $hlsDir = $config['hls_dir'] ?? __DIR__ . '/hls';
        $baseUrl = $config['base_url'] ?? '';
        
        $contentTree = getDirectoryTree($mediaContentDir, $baseUrl . '/content', 5);
        $hlsTree = getDirectoryTree($hlsDir, $baseUrl . '/hls', 3);
        $uploadsTree = getDirectoryTree($mediaUploadsDir, $baseUrl . '/media/uploads', 3);
        
        $contentSize = getDirectorySize($mediaContentDir);
        $hlsSize = getDirectorySize($hlsDir);
        $uploadsSize = getDirectorySize($mediaUploadsDir);
        
        // Get detailed file information for recent posts
        $processedPosts = getAllProcessedPosts($mediaContentDir, $baseUrl, 10);
        ?>
        
        <div class="section">
            <h2>File Browser</h2>
            <p style="color: #666; margin-bottom: 20px; font-size: 14px;">
                Browse files in the video processing directories. Click folders to expand, click files to view/download.
            </p>
            
            <div class="file-browser-container">
                <div class="file-browser-panel">
                    <h3>
                        <span class="panel-icon">📂</span>
                        Media Content (New Path)
                        <span class="panel-size"><?php echo formatBytes($contentSize); ?></span>
                    </h3>
                    <div class="path-label"><?php echo htmlspecialchars($mediaContentDir); ?></div>
                    <?php echo renderDirectoryTree($contentTree, $mediaContentDir); ?>
                </div>
                
                <div class="file-browser-panel">
                    <h3>
                        <span class="panel-icon">📺</span>
                        HLS Output (Legacy Path)
                        <span class="panel-size"><?php echo formatBytes($hlsSize); ?></span>
                    </h3>
                    <div class="path-label"><?php echo htmlspecialchars($hlsDir); ?></div>
                    <?php echo renderDirectoryTree($hlsTree, $hlsDir); ?>
                </div>
                
                <div class="file-browser-panel">
                    <h3>
                        <span class="panel-icon">📁</span>
                        Media Uploads
                        <span class="panel-size"><?php echo formatBytes($uploadsSize); ?></span>
                    </h3>
                    <div class="path-label"><?php echo htmlspecialchars($mediaUploadsDir); ?></div>
                    <?php echo renderDirectoryTree($uploadsTree, $mediaUploadsDir); ?>
                </div>
            </div>
        </div>
        
        <div class="section post-files-section">
            <h2>Processed Posts - File Details & URLs</h2>
            <p style="color: #666; margin-bottom: 20px; font-size: 14px;">
                Detailed view of all files for each processed post with direct URLs. Use this to debug URL mismatches.
                <br><small>Path format: <code>/content/{YYYY}/{MM}/{POST_ID}/</code></small>
            </p>
            
            <?php if (empty($processedPosts)): ?>
            <div class="no-data">No processed posts found in media content directory</div>
            <?php else: ?>
            
            <?php foreach ($processedPosts as $post): ?>
            <div class="post-card">
                <div class="post-card-header">
                    <div class="post-id-info">
                        <a href="<?php echo $wpAdminBaseUrl; ?>?post=<?php echo urlencode($post['post_id']); ?>&action=edit" target="_blank" class="post-id-badge" title="Edit in WordPress">
                            #<?php echo htmlspecialchars($post['post_id']); ?>
                        </a>
                        <span class="post-path"><?php echo htmlspecialchars($post['year'] . '/' . $post['month'] . '/' . $post['post_id']); ?></span>
                        <span style="color: #888; font-size: 11px; margin-left: 10px;">📅 <?php echo $post['created_at']; ?></span>
                    </div>
                    <div class="post-meta">
                        <span>Size: <?php echo formatBytes($post['total_size']); ?></span>
                        <?php if ($post['hls_segments'] > 0): ?>
                        <span>HLS Segments: <?php echo $post['hls_segments']; ?> (<?php echo formatBytes($post['hls_segments_size']); ?>)</span>
                        <?php endif; ?>
                        <button class="show-urls-btn" onclick="toggleUrlDebug(this)">Show All URLs</button>
                        <button class="show-urls-btn" onclick="toggleHlsSegments(this)" style="background: #28a745;">Show TS Files</button>
                    </div>
                </div>
                
                <div class="files-grid">
                    <?php 
                    foreach ($post['files'] as $fileName => $fileInfo):
                        if (!$fileInfo['exists']) continue;
                    ?>
                    <div class="file-entry exists">
                        <div class="file-name">
                            <?php 
                            $icon = '📄';
                            $fileType = 'file';
                            if (strpos($fileName, '.mp4') !== false) { $icon = '🎬'; $fileType = 'mp4'; }
                            elseif (strpos($fileName, '.webp') !== false || strpos($fileName, '.jpg') !== false || strpos($fileName, '.png') !== false || strpos($fileName, '.jpeg') !== false) { $icon = '🖼️'; $fileType = 'thumbnail'; }
                            elseif (strpos($fileName, '.m3u8') !== false) { $icon = '📺'; $fileType = 'hls'; }
                            echo $icon . ' '; 
                            echo htmlspecialchars($fileName); 
                            ?>
                            <span class="badge badge-<?php echo $fileType === 'mp4' ? 'success' : ($fileType === 'hls' ? 'info' : 'warning'); ?>" style="font-size: 9px; margin-left: 5px;">
                                <?php echo strtoupper($fileType); ?>
                            </span>
                        </div>
                        <div class="file-meta">
                            <span class="file-size"><?php echo $fileInfo['size_formatted']; ?></span>
                            <span style="color: #888; font-size: 10px; margin-left: 5px;"><?php echo $fileInfo['mtime']; ?></span>
                            <a href="<?php echo htmlspecialchars($fileInfo['url']); ?>" target="_blank" class="file-url-btn">Open</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (!empty($post['hls_segment_files'])): ?>
                <div class="hls-segments-box" style="display: none; margin-top: 15px; background: #f8f9fa; border-radius: 8px; padding: 15px;">
                    <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">📼 HLS Segment Files (<?php echo $post['hls_segments']; ?> total, <?php echo formatBytes($post['hls_segments_size']); ?>)</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px;">
                        <?php foreach ($post['hls_segment_files'] as $segment): ?>
                        <div style="background: white; padding: 8px; border-radius: 4px; border: 1px solid #e9ecef; font-size: 11px;">
                            <div style="font-family: monospace; margin-bottom: 3px;">📼 <?php echo htmlspecialchars($segment['name']); ?></div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="color: #666;"><?php echo $segment['size_formatted']; ?></span>
                                <a href="<?php echo htmlspecialchars($segment['url']); ?>" target="_blank" class="open-btn secondary" style="padding: 2px 6px;">Open</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($post['hls_segments'] > 10): ?>
                    <p style="margin: 10px 0 0 0; color: #888; font-size: 11px;">+ <?php echo $post['hls_segments'] - 10; ?> more segment files...</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="url-debug-box" style="display: none;">
                    <div class="url-line">
                        <span class="url-label">Base URL:</span>
                        <span class="url-value"><?php echo htmlspecialchars($post['url_base']); ?></span>
                    </div>
                    <div class="url-line">
                        <span class="url-label">Server Path:</span>
                        <span class="url-value"><?php echo htmlspecialchars($post['path']); ?></span>
                    </div>
                    <?php 
                    // Show important URLs
                    $keyFiles = [
                        'compressed_480p.mp4' => 'Compressed Video (480p)',
                        'hls/master.m3u8' => 'HLS Master Playlist',
                        'thumbnail.webp' => 'Thumbnail WebP'
                    ];
                    foreach ($keyFiles as $key => $label):
                        if (isset($post['files'][$key]) && $post['files'][$key]['exists']):
                    ?>
                    <div class="url-line">
                        <span class="url-label"><?php echo htmlspecialchars($label); ?>:</span>
                        <span class="url-value"><?php echo htmlspecialchars($post['files'][$key]['url']); ?></span>
                    </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>URL Structure Reference</h2>
            <div class="url-debug-box">
                <div class="url-line">
                    <span class="url-label">NEW PATH FORMAT (VideoCompressor + HLSConverter):</span>
                </div>
                <div class="url-line" style="margin-left: 20px;">
                    <span class="url-value"><?php echo htmlspecialchars($baseUrl); ?>/content/{YYYY}/{MM}/{POST_ID}/compressed_480p.mp4</span>
                </div>
                <div class="url-line" style="margin-left: 20px;">
                    <span class="url-value"><?php echo htmlspecialchars($baseUrl); ?>/content/{YYYY}/{MM}/{POST_ID}/hls/master.m3u8</span>
                </div>
                <div class="url-line" style="margin-left: 20px;">
                    <span class="url-value"><?php echo htmlspecialchars($baseUrl); ?>/content/{YYYY}/{MM}/{POST_ID}/thumbnail.webp</span>
                </div>
                <div class="url-line" style="margin-top: 15px; background: #fff3cd; padding: 10px; border-radius: 4px;">
                    <span class="url-label" style="color: #856404;">⚠️ LEGACY PATH (DEPRECATED - No longer supported):</span>
                </div>
                <div class="url-line" style="margin-left: 20px; text-decoration: line-through; color: #888;">
                    <span class="url-value"><?php echo htmlspecialchars($baseUrl); ?>/hls/{video_id}/master.m3u8</span>
                </div>
                <div class="url-line" style="margin-top: 15px; color: #28a745;">
                    <span class="url-label">✅ All HLS files should now use /content/{YYYY}/{MM}/{POST_ID}/hls/ path structure.</span>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>Recent Activity Logs</h2>
            <div class="log-viewer">
                <?php if (empty($recentLogs)): ?>
                    <div style="text-align: center; opacity: 0.5;">No logs available</div>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <?php
                        $class = '';
                        if (stripos($log, 'error') !== false) $class = 'log-error';
                        elseif (stripos($log, 'success') !== false) $class = 'log-success';
                        else $class = 'log-info';
                        ?>
                        <div class="log-line <?php echo $class; ?>"><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="refresh-note">
            Last updated: <?php echo date('Y-m-d H:i:s'); ?> • <a href="dashboard.php" style="color: #667eea;">Refresh</a>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.folder-header.has-children').forEach(function(header) {
            header.addEventListener('click', function() {
                var contents = this.nextElementSibling;
                if (contents && contents.classList.contains('folder-contents')) {
                    contents.classList.toggle('collapsed');
                    this.classList.toggle('expanded');
                }
            });
        });
    });
    
    function toggleUrlDebug(button) {
        var postCard = button.closest('.post-card');
        var debugBox = postCard.querySelector('.url-debug-box');
        if (debugBox) {
            if (debugBox.style.display === 'none') {
                debugBox.style.display = 'block';
                button.textContent = 'Hide URLs';
            } else {
                debugBox.style.display = 'none';
                button.textContent = 'Show All URLs';
            }
        }
    }
    
    function toggleHlsSegments(button) {
        var postCard = button.closest('.post-card');
        var segmentsBox = postCard.querySelector('.hls-segments-box');
        if (segmentsBox) {
            if (segmentsBox.style.display === 'none') {
                segmentsBox.style.display = 'block';
                button.textContent = 'Hide TS Files';
            } else {
                segmentsBox.style.display = 'none';
                button.textContent = 'Show TS Files';
            }
        }
    }
    </script>
</body>
</html>
