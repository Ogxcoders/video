<?php
/**
 * VPS-API Homepage
 * Lists all available endpoints and their documentation
 */

header('Content-Type: application/json');

$baseUrl = 'https://v.ogtemplate.com';

$response = [
    'service' => 'VPS Video Compression API',
    'version' => '1.0.0',
    'timestamp' => date('Y-m-d H:i:s'),
    'base_url' => $baseUrl,
    'endpoints' => [
        'health_monitoring' => [
            [
                'name' => 'General Health Check',
                'url' => $baseUrl . '/health.php',
                'method' => 'GET',
                'description' => 'Basic server health check - returns uptime and status',
                'auth_required' => false
            ],
            [
                'name' => 'Redis Health Check',
                'url' => $baseUrl . '/redis-health.php',
                'method' => 'GET',
                'description' => 'Detailed Redis server status, version, memory usage, and queue statistics',
                'auth_required' => false
            ],
            [
                'name' => 'Compress API Health',
                'url' => $baseUrl . '/check-compress-api.php',
                'method' => 'GET',
                'description' => 'Compression API configuration status, logs, and Redis connection',
                'auth_required' => false
            ]
        ],
        'api_endpoints' => [
            [
                'name' => 'Compression API (Task 2)',
                'url' => $baseUrl . '/compress.php',
                'method' => 'POST',
                'description' => 'Queue video compression jobs - receives from WordPress plugin',
                'auth_required' => true,
                'headers' => [
                    'Content-Type: application/json',
                    'X-API-Key: YOUR_API_KEY'
                ],
                'payload_example' => [
                    'postId' => 30566,
                    'wpMediaPath' => '/wp-content/uploads/2024/11/video.mp4',
                    'wpThumbnailPath' => '/wp-content/uploads/2024/11/thumb.jpg',
                    'year' => 2024,
                    'month' => 11
                ]
            ],
            [
                'name' => 'Video Processing API (Legacy)',
                'url' => $baseUrl . '/index.php',
                'method' => 'POST',
                'description' => 'Legacy video processing endpoint',
                'auth_required' => true,
                'headers' => [
                    'Content-Type: application/json',
                    'X-API-Key: YOUR_API_KEY'
                ],
                'payload_example' => [
                    'video_url' => 'https://example.com/video.mp4',
                    'post_id' => 12345
                ]
            ]
        ],
        'logging_monitoring' => [
            [
                'name' => 'View Application Logs',
                'url' => $baseUrl . '/view-logs.php',
                'method' => 'GET',
                'description' => 'View compress.log, redis-queue.log, and worker.log entries',
                'auth_required' => false,
                'parameters' => [
                    'lines' => 'Number of lines to show (default: 50, max: 500)',
                    'type' => 'Log type: compress, redis_queue, worker, or all (default)'
                ],
                'examples' => [
                    $baseUrl . '/view-logs.php?lines=100',
                    $baseUrl . '/view-logs.php?type=compress',
                    $baseUrl . '/view-logs.php?type=redis_queue&lines=200'
                ]
            ]
        ],
        'testing_utilities' => [
            [
                'name' => 'Test Compress API',
                'url' => $baseUrl . '/test-compress.php',
                'method' => 'CLI',
                'description' => 'Test script for compression API endpoint',
                'usage' => 'docker exec -it <container> php /var/www/html/test-compress.php',
                'auth_required' => false
            ],
            [
                'name' => 'Test Redis Queue',
                'url' => $baseUrl . '/test-redis-queue.php',
                'method' => 'CLI',
                'description' => 'Comprehensive Redis queue test (12 test steps)',
                'usage' => 'docker exec -it <container> php /var/www/html/test-redis-queue.php',
                'auth_required' => false
            ],
            [
                'name' => 'Test Connection',
                'url' => $baseUrl . '/test-connection.php',
                'method' => 'GET',
                'description' => 'Test basic connectivity and server response',
                'auth_required' => false
            ]
        ],
        'admin_utilities' => [
            [
                'name' => 'Setup',
                'url' => $baseUrl . '/setup.php',
                'method' => 'GET',
                'description' => 'Initial setup and configuration verification',
                'auth_required' => false
            ],
            [
                'name' => 'Dashboard',
                'url' => $baseUrl . '/dashboard.php',
                'method' => 'GET',
                'description' => 'Admin dashboard (if available)',
                'auth_required' => false
            ],
            [
                'name' => 'Cleanup',
                'url' => $baseUrl . '/cleanup.php',
                'method' => 'GET/POST',
                'description' => 'Clean up old files and temporary data',
                'auth_required' => true
            ]
        ]
    ],
    'documentation' => [
        'task_1' => 'WordPress Post Meta Handler - Complete',
        'task_2' => 'Compression API Endpoint - Complete (see /compress.php)',
        'task_3' => 'Redis Queue Setup - Complete (see /redis-health.php)',
        'task_4' => 'Background Worker Service - In Progress',
        'full_docs' => 'See TASKLIST.md in repository'
    ],
    'quick_start' => [
        'health_check' => $baseUrl . '/health.php',
        'redis_status' => $baseUrl . '/redis-health.php',
        'api_health' => $baseUrl . '/check-compress-api.php',
        'view_logs' => $baseUrl . '/view-logs.php?lines=50'
    ],
    'support' => [
        'repository' => 'Check TASKLIST.md for complete documentation',
        'redis_guide' => 'See vps-api/REDIS-SETUP.md',
        'task2_logs' => 'See vps-api/TASK2-LOG-VERIFICATION.md',
        'deployment' => 'See vps-api/DEPLOYMENT-INSTRUCTIONS.md'
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
