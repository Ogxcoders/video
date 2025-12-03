<?php
/**
 * Router for VPS-API
 * Handles routing for PHP built-in server and serves static content files
 * 
 * All media content is served from /content/ path which maps to media/content/ directory.
 * HLS files are stored in /content/{YYYY}/{MM}/{POST_ID}/hls/ structure.
 * 
 * Note: Error handlers are NOT applied here to avoid affecting static file MIME types.
 * Error handling for API endpoints should be done within each endpoint file.
 */

$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

/**
 * Validate that a path is safe and within the allowed base directory
 * This function handles both existing and non-existing files properly
 * 
 * @param string $filePath The file path to validate
 * @param string $baseDir The base directory that the file should be within
 * @return bool True if path is safe, false otherwise
 */
function isPathSafe($filePath, $baseDir) {
    // First check if base directory exists
    $realBaseDir = realpath($baseDir);
    if ($realBaseDir === false) {
        return false;
    }
    
    // For existing files, use realpath
    if (file_exists($filePath)) {
        $realPath = realpath($filePath);
        if ($realPath === false) {
            return false;
        }
        return strpos($realPath, $realBaseDir . DIRECTORY_SEPARATOR) === 0;
    }
    
    // For non-existing files, normalize the path manually
    // This allows us to check if the path WOULD be within the base directory
    $normalizedPath = $baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace($baseDir, '', $filePath), DIRECTORY_SEPARATOR);
    
    // Resolve any .. or . in the path
    $parts = explode(DIRECTORY_SEPARATOR, $normalizedPath);
    $resolvedParts = [];
    
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if (!empty($resolvedParts)) {
                array_pop($resolvedParts);
            }
            continue;
        }
        $resolvedParts[] = $part;
    }
    
    $resolvedPath = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $resolvedParts);
    
    // Check if resolved path starts with the base directory
    return strpos($resolvedPath, $realBaseDir) === 0;
}

if (preg_match('#^/content/(.+)$#', $requestPath, $matches)) {
    $requestedFile = $matches[1];
    
    if (strpos($requestedFile, '..') !== false || strpos($requestedFile, "\0") !== false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit;
    }
    
    $baseDir = __DIR__ . '/media/content';
    $filePath = $baseDir . '/' . $requestedFile;
    
    if (!isPathSafe($filePath, $baseDir)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit;
    }
    
    if (file_exists($filePath) && is_file($filePath)) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        ];
        
        $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
        
        header('Content-Type: ' . $contentType);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Range, Accept-Encoding');
        header('Cache-Control: public, max-age=31536000');
        
        if ($extension === 'm3u8') {
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
        $fileSize = filesize($filePath);
        
        if (isset($_SERVER['HTTP_RANGE']) && in_array($extension, ['mp4', 'webm', 'ts'])) {
            $range = $_SERVER['HTTP_RANGE'];
            if (preg_match('/bytes=(\d+)-(\d*)/', $range, $rangeMatches)) {
                $start = intval($rangeMatches[1]);
                $end = !empty($rangeMatches[2]) ? intval($rangeMatches[2]) : $fileSize - 1;
                
                if ($start > $end || $start >= $fileSize) {
                    http_response_code(416);
                    header("Content-Range: bytes */$fileSize");
                    exit;
                }
                
                $length = $end - $start + 1;
                
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$fileSize");
                header("Content-Length: $length");
                header('Accept-Ranges: bytes');
                
                $fp = fopen($filePath, 'rb');
                fseek($fp, $start);
                echo fread($fp, $length);
                fclose($fp);
                exit;
            }
        }
        
        header('Content-Length: ' . $fileSize);
        header('Accept-Ranges: bytes');
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'File not found']);
        exit;
    }
}

if (preg_match('#^/media/uploads/(.+)$#', $requestPath, $matches)) {
    $requestedFile = $matches[1];
    
    if (strpos($requestedFile, '..') !== false || strpos($requestedFile, "\0") !== false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit;
    }
    
    $baseDir = __DIR__ . '/media/uploads';
    $filePath = $baseDir . '/' . $requestedFile;
    
    if (!isPathSafe($filePath, $baseDir)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit;
    }
    
    if (file_exists($filePath) && is_file($filePath)) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
        
        header('Content-Type: ' . $contentType);
        header('Access-Control-Allow-Origin: *');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'File not found']);
        exit;
    }
}

/**
 * DEPRECATED: Legacy /hls/ path is no longer used.
 * All HLS files are now served from /content/{YYYY}/{MM}/{POST_ID}/hls/ path.
 * 
 * This route returns 410 Gone status to indicate the path has been deprecated.
 */
if (preg_match('#^/hls/(.+)$#', $requestPath, $matches)) {
    http_response_code(410);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'status' => 'error',
        'message' => 'Legacy /hls/ path is deprecated. HLS files are now served from /content/{YYYY}/{MM}/{POST_ID}/hls/ path.',
        'deprecated' => true,
        'new_path_format' => '/content/{YYYY}/{MM}/{POST_ID}/hls/master.m3u8',
        'example' => '/content/2024/11/12345/hls/master.m3u8'
    ]);
    exit;
}

return false;
