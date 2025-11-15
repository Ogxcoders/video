<?php
/**
 * Enhanced Configuration for Video Processing API
 * Version 3.0 - Supports 10k+ bulk processing with Redis queue
 */

return [
    // API Settings
    'api_key' => getenv('API_KEY') ?: 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY',
    'allowed_origins' => explode(',', getenv('ALLOWED_ORIGINS') ?: ''),
    
    // Redis Configuration
    'redis_host' => getenv('REDIS_HOST') ?: 'redis',
    'redis_port' => getenv('REDIS_PORT') ?: 6379,
    'queue_name' => 'video_compression_queue',
    'max_retries' => 3,
    
    // Worker Configuration
    'worker_count' => getenv('WORKER_COUNT') ?: 5,
    'wordpress_webhook_url' => getenv('WORDPRESS_WEBHOOK_URL') ?: 'https://ogtemplate.com/wp-json/cvp/v1/webhook',
    'webhook_secret' => getenv('WEBHOOK_SECRET') ?: 'CHANGE_ME_TO_A_SECURE_SECRET',
    
    // Media Storage Configuration
    'media_base_path' => getenv('MEDIA_BASE_PATH') ?: '/var/www/media/content',
    'media_base_url' => getenv('MEDIA_BASE_URL') ?: 'https://v.ogtemplate.com/content',
    
    // Legacy directory settings (for backward compatibility)
    'videos_dir' => __DIR__ . '/videos',
    'hls_dir' => __DIR__ . '/hls',
    'base_url' => getenv('BASE_URL') ?: 'https://post.trendss.net',
    'hls_url_base' => getenv('HLS_URL_BASE') ?: 'https://post.trendss.net/hls',
    
    // FFmpeg Settings
    'ffmpeg_binary' => getenv('FFMPEG_PATH') ?: '/usr/bin/ffmpeg',
    'ffmpeg_timeout' => 600,
    
    // Video Quality Settings (4 levels as per QualitiesPlan.md)
    'video_qualities' => [
        '480p' => [
            'width' => 854,
            'height' => 480,
            'scale' => '854:480',
            'bitrate' => '800k',
            'maxrate' => '900k',
            'bufsize' => '1600k',
            'audio_bitrate' => '96k',
            'profile' => 'main',
            'level' => '3.1'
        ],
        '360p' => [
            'width' => 640,
            'height' => 360,
            'scale' => '640:360',
            'bitrate' => '600k',
            'maxrate' => '700k',
            'bufsize' => '1200k',
            'audio_bitrate' => '96k',
            'profile' => 'main',
            'level' => '3.0'
        ],
        '240p' => [
            'width' => 426,
            'height' => 240,
            'scale' => '426:240',
            'bitrate' => '400k',
            'maxrate' => '500k',
            'bufsize' => '800k',
            'audio_bitrate' => '64k',
            'profile' => 'baseline',
            'level' => '3.0'
        ],
        '144p' => [
            'width' => 256,
            'height' => 144,
            'scale' => '256:144',
            'bitrate' => '200k',
            'maxrate' => '250k',
            'bufsize' => '400k',
            'audio_bitrate' => '64k',
            'audio_sample_rate' => '22050',
            'profile' => 'baseline',
            'level' => '3.0'
        ]
    ],
    
    // Image/Thumbnail Settings
    'thumbnail_webp_quality' => 87,
    'thumbnail_webp_compression_level' => 6,
    
    // HLS Settings
    'hls_time' => 10,  // 10-second segments
    'hls_list_size' => 0,
    'hls_playlist_type' => 'vod',
    
    // FFmpeg Presets
    'ffmpeg_preset' => 'faster', // Balance between speed and quality
    'ffmpeg_gop_size' => 30, // GOP size for proper HLS segmentation
    
    // Cleanup Settings
    'cleanup_original' => false, // Keep original files
    'cleanup_temp_files' => true, // Delete temp files after processing
    'max_video_age_days' => 90,
    
    // Logging
    'log_file' => '/var/www/html/logs/api.log',
    'debug' => getenv('DEBUG') === 'true',
    
    // Performance Settings
    'parallel_limit' => getenv('PARALLEL_LIMIT') ?: 1,
    'max_file_size' => 2 * 1024 * 1024 * 1024, // 2GB max
    
    // Security Settings
    'allowed_video_extensions' => ['mp4', 'mov', 'avi', 'webm', 'mkv'],
    'allowed_image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'rate_limit_bulk_requests' => 5, // Max bulk requests per hour
    'rate_limit_window' => 3600, // 1 hour
];
