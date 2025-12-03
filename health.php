<?php
/**
 * Health Check Endpoint for Coolify/Docker
 * Simple endpoint that returns 200 OK without authentication
 * Used by reverse proxy and orchestration tools
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'timestamp' => time(),
    'service' => 'vps-api'
]);
exit;
