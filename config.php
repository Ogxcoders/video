<?php
/**
 * Enhanced Configuration for Web B Video Processing API
 * Version 2.3
 * Updated for Replit environment with improved environment variable resolution
 * 
 * ============================================================================
 * REQUIRED ENVIRONMENT VARIABLES FOR VPS DEPLOYMENT:
 * ============================================================================
 * 
 * BASE_URL (CRITICAL)
 *   - The public URL of your VPS server
 *   - Example: https://api.yourdomain.com
 *   - Without this, all generated URLs will use Replit domain or localhost
 * 
 * MEDIA_CONTENT_DIR (Recommended)
 *   - Server path where processed video content is stored
 *   - Default: {project_dir}/media/content
 *   - On VPS typically: /var/www/html/media/content
 * 
 * MEDIA_UPLOADS_DIR (Recommended)
 *   - Server path where uploaded files are temporarily stored
 *   - Default: {project_dir}/media/uploads
 *   - On VPS typically: /var/www/html/media/uploads
 * 
 * API_KEY (CRITICAL for security)
 *   - Random secure key for API authentication
 *   - Generate with: openssl rand -base64 32
 * 
 * ADMIN_PASSWORD (CRITICAL for security)
 *   - Password for dashboard access
 *   - Generate with: openssl rand -base64 24
 * 
 * REDIS_HOST, REDIS_PORT (For queue processing)
 *   - Redis server connection details
 *   - Default: 127.0.0.1:6379
 * 
 * WORDPRESS_WEBHOOK_URL (For WordPress integration)
 *   - URL to receive processing completion callbacks
 *   - Example: https://yoursite.com/wp-json/cvp/v1/webhook
 * 
 * ============================================================================
 */

/**
 * Get environment variable with multi-source fallback
 * 
 * Tries sources in order: getenv() → $_ENV → $_SERVER → default
 * This ensures compatibility with PHP-FPM (which may have clear_env=yes),
 * mod_php, and CLI modes.
 * 
 * @param string $key Environment variable name
 * @param mixed $default Default value if not found
 * @return mixed
 */
if (!function_exists('env_get')) {
    function env_get($key, $default = null) {
        // Try getenv() first (works in CLI and most configurations)
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        
        // Try $_ENV (superglobal, may be populated depending on variables_order)
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        
        // Try $_SERVER (often populated by web servers)
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        
        return $default;
    }
}

/**
 * Configuration validation warnings
 * These are collected and can be displayed in the dashboard
 */
if (!function_exists('getConfigValidationWarnings')) {
    function getConfigValidationWarnings($config) {
        $warnings = [];
        
        // Check BASE_URL
        $baseUrlEnv = env_get('BASE_URL');
        if (empty($baseUrlEnv)) {
            $warnings[] = [
                'level' => 'warning',
                'key' => 'BASE_URL',
                'message' => 'BASE_URL environment variable is not set. Using auto-detected domain.',
                'current_value' => $config['base_url'] ?? 'Not set',
                'recommendation' => 'Set BASE_URL to your production domain (e.g., https://api.yourdomain.com)'
            ];
        }
        
        // Check MEDIA_CONTENT_DIR
        $mediaContentDir = $config['media_content_dir'] ?? '';
        if (!empty($mediaContentDir) && !is_dir($mediaContentDir)) {
            $warnings[] = [
                'level' => 'error',
                'key' => 'MEDIA_CONTENT_DIR',
                'message' => 'MEDIA_CONTENT_DIR points to a non-existent directory.',
                'current_value' => $mediaContentDir,
                'recommendation' => 'Create the directory or update MEDIA_CONTENT_DIR environment variable'
            ];
        }
        
        // Check MEDIA_UPLOADS_DIR
        $mediaUploadsDir = $config['media_uploads_dir'] ?? '';
        if (!empty($mediaUploadsDir) && !is_dir($mediaUploadsDir)) {
            $warnings[] = [
                'level' => 'error',
                'key' => 'MEDIA_UPLOADS_DIR',
                'message' => 'MEDIA_UPLOADS_DIR points to a non-existent directory.',
                'current_value' => $mediaUploadsDir,
                'recommendation' => 'Create the directory or update MEDIA_UPLOADS_DIR environment variable'
            ];
        }
        
        // Check API_KEY
        $apiKey = $config['api_key'] ?? '';
        if (empty($apiKey) || $apiKey === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
            $warnings[] = [
                'level' => 'critical',
                'key' => 'API_KEY',
                'message' => 'API_KEY is not set or using default insecure value.',
                'current_value' => '(default/insecure)',
                'recommendation' => 'Set a secure random API key: openssl rand -base64 32'
            ];
        }
        
        // Check if HLS directory exists
        $hlsDir = $config['hls_dir'] ?? '';
        if (!empty($hlsDir) && !is_dir($hlsDir)) {
            $warnings[] = [
                'level' => 'warning',
                'key' => 'hls_dir',
                'message' => 'HLS directory does not exist (legacy path).',
                'current_value' => $hlsDir,
                'recommendation' => 'This may be fine if using new /media/content/ path only'
            ];
        }
        
        // Check FFmpeg
        $ffmpegBinary = $config['ffmpeg_binary'] ?? '';
        if (empty($ffmpegBinary) || !file_exists($ffmpegBinary)) {
            $warnings[] = [
                'level' => 'critical',
                'key' => 'ffmpeg_binary',
                'message' => 'FFmpeg binary not found.',
                'current_value' => $ffmpegBinary ?: '(not set)',
                'recommendation' => 'Install FFmpeg or set FFMPEG_PATH environment variable'
            ];
        }
        
        return $warnings;
    }
}

$replitDomain = env_get('REPLIT_DOMAINS') ?: env_get('REPLIT_DEV_DOMAIN') ?: 'localhost:5000';
$baseDir = __DIR__;

// Ensure we get the proper domain for Replit
if (strpos($replitDomain, ',') !== false) {
    $domains = explode(',', $replitDomain);
    $replitDomain = $domains[0];
}

return [
    // API Settings
    'api_key' => env_get('API_KEY', 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY'),
    'allowed_origins' => explode(',', env_get('ALLOWED_ORIGINS', '*')),
    
    // Directory Settings
    'videos_dir' => $baseDir . '/videos',
    'hls_dir' => $baseDir . '/hls',
    
    // Public URL Settings
    'base_url' => env_get('BASE_URL') ?: 'https://' . $replitDomain,
    'hls_url_base' => env_get('HLS_URL_BASE') ?: 'https://' . $replitDomain . '/hls',
    
    // FFmpeg Settings (auto-detect path)
    'ffmpeg_binary' => env_get('FFMPEG_PATH') ?: trim(shell_exec('which ffmpeg 2>/dev/null') ?: '/usr/bin/ffmpeg'),
    'ffmpeg_timeout' => 600,
    
    // Video Processing Settings (4 quality levels with height-based scaling)
    'resolutions' => [
        '144p' => [
            'scale' => '-2:144',
            'bitrate' => '150k',
            'maxrate' => '200k',
            'bufsize' => '300k'
        ],
        '240p' => [
            'scale' => '-2:240',
            'bitrate' => '200k',
            'maxrate' => '250k',
            'bufsize' => '400k'
        ],
        '360p' => [
            'scale' => '-2:360',
            'bitrate' => '350k',
            'maxrate' => '400k',
            'bufsize' => '700k'
        ],
        '480p' => [
            'scale' => '-2:480',
            'bitrate' => '500k',
            'maxrate' => '600k',
            'bufsize' => '1000k'
        ]
    ],
    
    // HLS Settings
    'hls_time' => 6,
    'hls_list_size' => 0,
    
    // Cleanup Settings
    'cleanup_original' => true,
    'max_video_age_days' => 30,
    
    // Logging
    'log_file' => $baseDir . '/logs/all.log',
    'debug' => true,
    
    // Parallel Processing
    'parallel_limit' => env_get('PARALLEL_LIMIT', 1),
    
    // WordPress Integration
    'wordpress_webhook_url' => env_get('WORDPRESS_WEBHOOK_URL', ''),
    
    // Email Notifications for Critical Failures
    'notify_email' => env_get('NOTIFY_EMAIL', ''),
    'email_from' => env_get('EMAIL_FROM') ?: 'noreply@' . $replitDomain,
    'email_notifications_enabled' => filter_var(env_get('EMAIL_NOTIFICATIONS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
    
    // Media Directory (Replit-compatible paths)
    'media_uploads_dir' => env_get('MEDIA_UPLOADS_DIR') ?: $baseDir . '/media/uploads',
    'media_content_dir' => env_get('MEDIA_CONTENT_DIR') ?: $baseDir . '/media/content',
    
    // Security: Allowed download domains for remote media files
    // Parse allowed domains with wildcard support
    // Use ALLOWED_DOWNLOAD_DOMAINS env var, fallback to ALLOWED_ORIGINS, or use wildcard '*' 
    'allowed_download_domains' => (function() {
        $domains = env_get('ALLOWED_DOWNLOAD_DOMAINS');
        if (empty($domains)) {
            $domains = env_get('ALLOWED_ORIGINS');
        }
        if (empty($domains) || $domains === '*') {
            return ['*'];
        }
        return array_filter(array_map('trim', explode(',', $domains)));
    })(),
    'verify_ssl_downloads' => filter_var(env_get('VERIFY_SSL_DOWNLOADS', 'true'), FILTER_VALIDATE_BOOLEAN),
    
    // Compression settings
    // Set to true only on high-resource servers (4+ CPU cores, 8GB+ RAM)
    'parallel_compression' => filter_var(env_get('PARALLEL_COMPRESSION', 'false'), FILTER_VALIDATE_BOOLEAN),
    
    // Redis Settings
    'redis' => [
        'host' => env_get('REDIS_HOST', '127.0.0.1'),
        'port' => (int)env_get('REDIS_PORT', 6379),
        'password' => env_get('REDIS_PASSWORD'),
        'database' => (int)env_get('REDIS_DATABASE', 0),
    ],
    
    // Admin Dashboard
    'admin_password' => env_get('ADMIN_PASSWORD', 'admin123')
];
