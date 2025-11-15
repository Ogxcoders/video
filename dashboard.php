<?php
/**
 * Professional Admin Dashboard for Web B Video Processing API
 * Version 2.0
 */

$config = require __DIR__ . '/config.php';

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
                        'created' => $folder->getCTime()
                    ];
                }
            }
        }
    }
    
    usort($videos, function($a, $b) {
        return $b['created'] - $a['created'];
    });
    
    return array_slice($videos, 0, $limit);
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

exec(escapeshellarg($config['ffmpeg_binary']) . ' -version 2>&1', $ffmpegOutput, $ffmpegStatus);
$ffmpegInstalled = ($ffmpegStatus === 0);
$ffmpegVersion = $ffmpegInstalled ? $ffmpegOutput[0] : 'Not installed';

$videosSize = getDirectorySize($config['videos_dir']);
$hlsSize = getDirectorySize($config['hls_dir']);
$videoCount = getVideoCount($config['videos_dir']);
$hlsCount = getHLSFolderCount($config['hls_dir']);
$totalSize = $videosSize + $hlsSize;

$recentVideos = getRecentVideos($config['hls_dir'], $config, 10);
$recentLogs = getLogs($config['log_file'], 100);

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
        
        .refresh-note {
            text-align: center;
            color: #999;
            font-size: 13px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div>
                <h1>🎬 Video Processing API Dashboard</h1>
                <div class="subtitle">Web B Server - v2.0</div>
            </div>
            <a href="?logout" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Processed Videos</div>
                <div class="stat-value"><?php echo $hlsCount; ?></div>
                <div class="stat-sub">HLS folders ready</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Source Videos</div>
                <div class="stat-value"><?php echo $videoCount; ?></div>
                <div class="stat-sub">Pending cleanup</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Storage</div>
                <div class="stat-value"><?php echo formatBytes($totalSize); ?></div>
                <div class="stat-sub">Videos: <?php echo formatBytes($videosSize); ?> | HLS: <?php echo formatBytes($hlsSize); ?></div>
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
                    <div class="info-label">HLS Directory</div>
                    <div class="info-value"><?php echo $config['hls_dir']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">HLS URL Base</div>
                    <div class="info-value"><?php echo $config['hls_url_base']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Parallel Limit</div>
                    <div class="info-value"><?php echo $config['parallel_limit']; ?></div>
                </div>
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
                        <div class="video-item">
                            <div>
                                <div class="video-id"><?php echo htmlspecialchars($video['id']); ?></div>
                                <div class="video-meta">
                                    <?php echo formatBytes($video['size']); ?> • 
                                    <?php echo date('Y-m-d H:i:s', $video['created']); ?>
                                </div>
                            </div>
                            <a href="<?php echo htmlspecialchars($video['url'] . '/master.m3u8'); ?>" target="_blank" class="btn" style="font-size: 12px; padding: 6px 12px;">View HLS</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
</body>
</html>
