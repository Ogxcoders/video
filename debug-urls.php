<?php
/**
 * URL Debugging Endpoint
 * Helps diagnose URL mismatches between WordPress and VPS
 * 
 * Usage: GET /debug-urls.php?post_id=12345&year=2025&month=10
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$config = require __DIR__ . '/config.php';

// Strict admin password check - require non-empty password to be configured
$adminPassword = getenv('ADMIN_PASSWORD');
$providedPassword = $_GET['password'] ?? $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';

// Hard fail if admin password is not configured (don't reveal config state)
if (empty($adminPassword)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Access denied'
    ]);
    error_log('[DEBUG-URLS] Access attempt blocked - admin password not configured');
    exit;
}

// Verify provided password matches
if (!hash_equals($adminPassword, $providedPassword)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized. Provide password via ?password= or X-Admin-Password header'
    ]);
    error_log('[DEBUG-URLS] Unauthorized access attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

// Log successful access
error_log('[DEBUG-URLS] Authorized access from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

$postId = isset($_GET['post_id']) ? (int)$_GET['post_id'] : null;
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// Show configuration
$response = [
    'status' => 'success',
    'configuration' => [
        'base_url' => $config['base_url'],
        'hls_url_base' => $config['hls_url_base'],
        'media_content_dir' => $config['media_content_dir'],
        'media_uploads_dir' => $config['media_uploads_dir'],
        'hls_dir' => $config['hls_dir'],
        'videos_dir' => $config['videos_dir']
    ],
    'directories_exist' => [
        'media_content_dir' => is_dir($config['media_content_dir']),
        'media_uploads_dir' => is_dir($config['media_uploads_dir']),
        'hls_dir' => is_dir($config['hls_dir']),
        'videos_dir' => is_dir($config['videos_dir'])
    ],
    'expected_url_formats' => [
        'compressed_video' => $config['base_url'] . '/content/{YEAR}/{MONTH}/{POST_ID}/compressed_480p.mp4',
        'hls_master' => $config['base_url'] . '/content/{YEAR}/{MONTH}/{POST_ID}/hls/master.m3u8',
        'thumbnail' => $config['base_url'] . '/content/{YEAR}/{MONTH}/{POST_ID}/thumbnail.webp',
        'legacy_hls' => $config['hls_url_base'] . '/video_{POST_ID}_{TIMESTAMP}/master.m3u8'
    ]
];

// Check specific post if provided
if ($postId) {
    $postDir = sprintf('%s/%04d/%02d/%d', rtrim($config['media_content_dir'], '/'), $year, $month, $postId);
    $hlsDir = $postDir . '/hls';
    
    $baseUrl = rtrim($config['base_url'], '/');
    
    $expectedUrls = [
        'compressed_480p' => $baseUrl . "/content/{$year}/{$month}/{$postId}/compressed_480p.mp4",
        'compressed_360p' => $baseUrl . "/content/{$year}/{$month}/{$postId}/compressed_360p.mp4",
        'compressed_240p' => $baseUrl . "/content/{$year}/{$month}/{$postId}/compressed_240p.mp4",
        'compressed_144p' => $baseUrl . "/content/{$year}/{$month}/{$postId}/compressed_144p.mp4",
        'hls_master' => $baseUrl . "/content/{$year}/{$month}/{$postId}/hls/master.m3u8",
        'thumbnail' => $baseUrl . "/content/{$year}/{$month}/{$postId}/thumbnail.webp"
    ];
    
    $actualFiles = [];
    $filesExist = [];
    
    $filesToCheck = [
        'compressed_480p.mp4' => $postDir . '/compressed_480p.mp4',
        'compressed_360p.mp4' => $postDir . '/compressed_360p.mp4',
        'compressed_240p.mp4' => $postDir . '/compressed_240p.mp4',
        'compressed_144p.mp4' => $postDir . '/compressed_144p.mp4',
        'original.mp4' => $postDir . '/original.mp4',
        'thumbnail.webp' => $postDir . '/thumbnail.webp',
        'original_thumbnail.*' => $postDir . '/original_thumbnail.*',
        'hls/master.m3u8' => $hlsDir . '/master.m3u8',
        'hls/480p.m3u8' => $hlsDir . '/480p.m3u8',
        'hls/360p.m3u8' => $hlsDir . '/360p.m3u8',
        'hls/240p.m3u8' => $hlsDir . '/240p.m3u8',
        'hls/144p.m3u8' => $hlsDir . '/144p.m3u8'
    ];
    
    foreach ($filesToCheck as $name => $path) {
        if (strpos($name, '*') !== false) {
            $pattern = $path;
            $matches = glob($pattern);
            if (!empty($matches)) {
                $actualFiles[$name] = $matches;
                $filesExist[$name] = true;
            } else {
                $filesExist[$name] = false;
            }
        } else {
            $filesExist[$name] = file_exists($path);
            if ($filesExist[$name]) {
                $actualFiles[$name] = [
                    'path' => $path,
                    'size' => filesize($path),
                    'modified' => date('Y-m-d H:i:s', filemtime($path))
                ];
            }
        }
    }
    
    // Check legacy HLS directory
    $legacyHlsDir = $config['hls_dir'];
    $legacyHlsFiles = [];
    if (is_dir($legacyHlsDir)) {
        $iterator = new DirectoryIterator($legacyHlsDir);
        foreach ($iterator as $folder) {
            if ($folder->isDir() && !$folder->isDot()) {
                $folderName = $folder->getFilename();
                // Check if folder name contains the post ID
                if (strpos($folderName, "video_{$postId}_") === 0 || strpos($folderName, "_{$postId}") !== false) {
                    $masterPath = $folder->getPathname() . '/master.m3u8';
                    $legacyHlsFiles[] = [
                        'folder' => $folderName,
                        'path' => $folder->getPathname(),
                        'master_exists' => file_exists($masterPath),
                        'url' => $config['hls_url_base'] . '/' . $folderName . '/master.m3u8'
                    ];
                }
            }
        }
    }
    
    $response['post_check'] = [
        'post_id' => $postId,
        'year' => $year,
        'month' => $month,
        'post_directory' => $postDir,
        'post_directory_exists' => is_dir($postDir),
        'hls_directory' => $hlsDir,
        'hls_directory_exists' => is_dir($hlsDir),
        'expected_urls' => $expectedUrls,
        'files_exist' => $filesExist,
        'actual_files' => $actualFiles,
        'legacy_hls_matches' => $legacyHlsFiles
    ];
}

// List all processed posts
$allPosts = [];
$contentDir = $config['media_content_dir'];
if (is_dir($contentDir)) {
    $yearDirs = glob($contentDir . '/*', GLOB_ONLYDIR);
    foreach ($yearDirs as $yearDir) {
        $year = basename($yearDir);
        $monthDirs = glob($yearDir . '/*', GLOB_ONLYDIR);
        foreach ($monthDirs as $monthDir) {
            $month = basename($monthDir);
            $postDirs = glob($monthDir . '/*', GLOB_ONLYDIR);
            foreach ($postDirs as $postPath) {
                $postId = basename($postPath);
                $hasVideo = file_exists($postPath . '/compressed_480p.mp4');
                $hasHls = file_exists($postPath . '/hls/master.m3u8');
                $hasThumbnail = file_exists($postPath . '/thumbnail.webp');
                
                $allPosts[] = [
                    'post_id' => $postId,
                    'year' => $year,
                    'month' => $month,
                    'path' => $postPath,
                    'has_video' => $hasVideo,
                    'has_hls' => $hasHls,
                    'has_thumbnail' => $hasThumbnail,
                    'size' => getDirectorySize($postPath)
                ];
            }
        }
    }
}

$response['all_processed_posts'] = $allPosts;
$response['total_posts'] = count($allPosts);

// List legacy HLS folders
$legacyHls = [];
$hlsDir = $config['hls_dir'];
if (is_dir($hlsDir)) {
    $folders = glob($hlsDir . '/*', GLOB_ONLYDIR);
    foreach ($folders as $folder) {
        $name = basename($folder);
        $legacyHls[] = [
            'folder' => $name,
            'path' => $folder,
            'has_master' => file_exists($folder . '/master.m3u8'),
            'url' => $config['hls_url_base'] . '/' . $name . '/master.m3u8'
        ];
    }
}

$response['legacy_hls_folders'] = $legacyHls;
$response['total_legacy_hls'] = count($legacyHls);

function getDirectorySize($dir) {
    $size = 0;
    if (is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
    }
    return $size;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
