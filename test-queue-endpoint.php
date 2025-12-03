#!/usr/bin/env php
<?php
/**
 * Test queue-compress.php endpoint
 * Simulates a WordPress request to test the new endpoint
 */

$apiUrl = 'https://v.ogtemplate.com/queue-compress.php';
$apiKey = getenv('API_KEY') ?: 'CHANGE_THIS_TO_YOUR_SECURE_API_KEY_64_CHARS';

// Test data (simulating WordPress request)
$testData = [
    'video_url' => 'https://test.example.com/test-video.mp4',
    'post_id' => 12345
];

echo "=========================================\n";
echo "  Testing queue-compress.php Endpoint\n";
echo "=========================================\n\n";

echo "Endpoint: $apiUrl\n";
echo "API Key: " . substr($apiKey, 0, 20) . "...\n";
echo "Test Data:\n";
echo "  - video_url: {$testData['video_url']}\n";
echo "  - post_id: {$testData['post_id']}\n\n";

// Send POST request
echo "Sending POST request...\n\n";

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($testData),
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "=========================================\n";
echo "  Response\n";
echo "=========================================\n\n";

if ($curlError) {
    echo "❌ Connection Error: $curlError\n";
    exit(1);
}

echo "HTTP Status: $httpCode\n";
echo "Response Body:\n";
echo $response . "\n\n";

$data = json_decode($response, true);

if ($httpCode === 200 && isset($data['status']) && $data['status'] === 'success') {
    echo "✅ SUCCESS!\n\n";
    echo "Job Details:\n";
    echo "  - Job ID: " . ($data['jobId'] ?? 'N/A') . "\n";
    echo "  - Post ID: " . ($data['post_id'] ?? 'N/A') . "\n";
    echo "  - Queue Method: " . ($data['queue_method'] ?? 'N/A') . "\n\n";
    
    echo "Next Steps:\n";
    echo "1. Check worker logs: tail -f /var/www/html/logs/all.log | grep WORKER\n";
    echo "2. Check queue: redis-cli LLEN compression_queue\n";
    echo "3. Worker should pick up job within 10 seconds\n";
    exit(0);
} else {
    echo "❌ FAILED\n\n";
    if (isset($data['message'])) {
        echo "Error: " . $data['message'] . "\n";
    }
    exit(1);
}
