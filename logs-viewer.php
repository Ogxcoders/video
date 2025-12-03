<?php
/**
 * VPS API - Complete Logs Viewer
 * SECURITY: Requires API key authentication
 */

// Load configuration for API key
$config = require __DIR__ . '/config.php';

// Check for API key in query parameter
$providedKey = $_GET['api_key'] ?? '';

if (empty($config['api_key']) || $config['api_key'] === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
    http_response_code(500);
    die('Server configuration error: API key not configured');
}

if ($providedKey !== $config['api_key']) {
    http_response_code(401);
    header('Content-Type: text/html');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Authentication Required</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f5f5f5;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
            }
            .auth-box {
                background: white;
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                max-width: 400px;
                text-align: center;
            }
            h1 { color: #d32f2f; margin-bottom: 20px; }
            p { color: #666; margin-bottom: 20px; }
            input {
                width: 100%;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 5px;
                font-size: 14px;
                margin-bottom: 10px;
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
            }
            button:hover { background: #5568d3; }
        </style>
    </head>
    <body>
        <div class="auth-box">
            <h1>🔒 Authentication Required</h1>
            <p>Please enter your API key to access the logs viewer.</p>
            <form method="GET">
                <input type="password" name="api_key" placeholder="Enter API Key" required autofocus>
                <button type="submit">Access Logs</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Store API key in session-like variable for JavaScript
$apiKey = $providedKey;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VPS API - Complete Logs Viewer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        
        .header h1 {
            color: #667eea;
            font-size: 2em;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 1.1em;
        }
        
        .controls {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .controls button, .controls select, .controls input {
            padding: 12px 24px;
            border-radius: 8px;
            border: 2px solid #667eea;
            background: white;
            color: #667eea;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .controls button:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.3);
        }
        
        .controls select, .controls input {
            cursor: pointer;
        }
        
        .controls .search-box {
            flex: 1;
            min-width: 250px;
        }
        
        .stats {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .stat-item {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px;
            border-radius: 10px;
            color: white;
            text-align: center;
        }
        
        .stat-item .label {
            font-size: 0.9em;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .stat-item .value {
            font-size: 2em;
            font-weight: bold;
        }
        
        .log-container {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative;
        }
        
        .log-content {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            max-height: 70vh;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .log-line {
            padding: 4px 0;
            border-bottom: 1px solid #2d2d2d;
        }
        
        .log-line:hover {
            background: #2d2d2d;
        }
        
        .log-line.error {
            background: #4d1f1f;
            color: #ff6b6b;
        }
        
        .log-line.info {
            color: #4ec9b0;
        }
        
        .log-line.warning {
            color: #dcdcaa;
        }
        
        .log-line.success {
            color: #6adb6a;
        }
        
        .highlight {
            background: yellow;
            color: black;
            padding: 2px 4px;
            border-radius: 3px;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #667eea;
            font-size: 1.2em;
        }
        
        .loading::after {
            content: '...';
            animation: dots 1.5s steps(4, end) infinite;
        }
        
        @keyframes dots {
            0%, 20% { content: '.'; }
            40% { content: '..'; }
            60%, 100% { content: '...'; }
        }
        
        .no-logs {
            text-align: center;
            padding: 40px;
            color: #666;
            font-size: 1.1em;
        }
        
        .scroll-to-bottom {
            position: sticky;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            margin-top: 20px;
            z-index: 100;
        }
        
        .auto-refresh {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .auto-refresh input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
            }
            
            .controls button, .controls select, .controls input {
                width: 100%;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 VPS API Complete Logs Viewer</h1>
            <p>Real-time access to all system logs - View, search, and download complete log files</p>
        </div>
        
        <div class="controls">
            <button onclick="loadLogs()">🔄 Refresh Logs</button>
            <button onclick="downloadLogs()">⬇️ Download Complete Log</button>
            <select id="filterLevel" onchange="applyFilters()">
                <option value="all">All Levels</option>
                <option value="ERROR">Errors Only</option>
                <option value="INFO">Info Only</option>
                <option value="WARNING">Warnings Only</option>
            </select>
            <select id="filterComponent" onchange="applyFilters()">
                <option value="all">All Components</option>
                <option value="API">API</option>
                <option value="COMPRESS">Compress</option>
                <option value="WORKER">Worker</option>
                <option value="REDIS-QUEUE">Redis Queue</option>
                <option value="PROCESSOR">Processor</option>
                <option value="COMPRESSOR">Compressor</option>
                <option value="WEBHOOK">Webhook</option>
            </select>
            <input type="text" id="searchBox" class="search-box" placeholder="🔍 Search in logs..." oninput="applyFilters()">
            <div class="auto-refresh">
                <input type="checkbox" id="autoRefresh" onchange="toggleAutoRefresh()">
                <label for="autoRefresh">Auto-refresh (10s)</label>
            </div>
        </div>
        
        <div class="stats">
            <div class="stat-item">
                <div class="label">Total Lines</div>
                <div class="value" id="totalLines">0</div>
            </div>
            <div class="stat-item">
                <div class="label">Visible Lines</div>
                <div class="value" id="visibleLines">0</div>
            </div>
            <div class="stat-item">
                <div class="label">File Size</div>
                <div class="value" id="fileSize">0 KB</div>
            </div>
            <div class="stat-item">
                <div class="label">Last Updated</div>
                <div class="value" id="lastUpdated">-</div>
            </div>
            <div class="stat-item">
                <div class="label">Errors</div>
                <div class="value" id="errorCount">0</div>
            </div>
        </div>
        
        <div class="log-container">
            <div class="log-content" id="logContent">
                <div class="loading">Loading logs</div>
            </div>
            <div class="scroll-to-bottom">
                <button onclick="scrollToBottom()">⬇️ Scroll to Bottom</button>
                <button onclick="scrollToTop()">⬆️ Scroll to Top</button>
            </div>
        </div>
    </div>
    
    <script>
        let allLogs = [];
        let autoRefreshInterval = null;
        const apiKey = '<?php echo htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8'); ?>';
        
        async function loadLogs() {
            try {
                const response = await fetch('logs-api.php?action=view&all=true&api_key=' + encodeURIComponent(apiKey));
                const data = await response.json();
                
                if (data.success) {
                    allLogs = data.logs || [];
                    
                    // Update stats
                    document.getElementById('totalLines').textContent = data.total_lines || 0;
                    document.getElementById('fileSize').textContent = formatFileSize(data.file_size || 0);
                    document.getElementById('lastUpdated').textContent = data.last_modified || '-';
                    document.getElementById('errorCount').textContent = countErrors(allLogs);
                    
                    applyFilters();
                } else {
                    showError(data.message || 'Failed to load logs');
                }
            } catch (error) {
                showError('Error loading logs: ' + error.message);
            }
        }
        
        function applyFilters() {
            const filterLevel = document.getElementById('filterLevel').value;
            const filterComponent = document.getElementById('filterComponent').value;
            const searchText = document.getElementById('searchBox').value.toLowerCase();
            
            let filteredLogs = allLogs;
            
            // Filter by level
            if (filterLevel !== 'all') {
                filteredLogs = filteredLogs.filter(log => log.includes('[' + filterLevel + ']'));
            }
            
            // Filter by component
            if (filterComponent !== 'all') {
                filteredLogs = filteredLogs.filter(log => log.includes('[' + filterComponent + ']'));
            }
            
            // Filter by search text
            if (searchText) {
                filteredLogs = filteredLogs.filter(log => log.toLowerCase().includes(searchText));
            }
            
            displayLogs(filteredLogs, searchText);
            document.getElementById('visibleLines').textContent = filteredLogs.length;
        }
        
        function displayLogs(logs, searchText = '') {
            const logContent = document.getElementById('logContent');
            
            if (logs.length === 0) {
                logContent.innerHTML = '<div class="no-logs">No logs match your filters</div>';
                return;
            }
            
            let html = '';
            logs.forEach((log, index) => {
                let cssClass = 'log-line';
                if (log.includes('[ERROR]') || log.toLowerCase().includes('failed')) {
                    cssClass += ' error';
                } else if (log.includes('[INFO]')) {
                    cssClass += ' info';
                } else if (log.includes('[WARNING]')) {
                    cssClass += ' warning';
                } else if (log.toLowerCase().includes('success')) {
                    cssClass += ' success';
                }
                
                let displayLog = escapeHtml(log);
                
                // Highlight search text
                if (searchText) {
                    const regex = new RegExp('(' + escapeRegExp(searchText) + ')', 'gi');
                    displayLog = displayLog.replace(regex, '<span class="highlight">$1</span>');
                }
                
                html += `<div class="${cssClass}">${displayLog}</div>`;
            });
            
            logContent.innerHTML = html;
        }
        
        function countErrors(logs) {
            return logs.filter(log => log.includes('[ERROR]') || log.toLowerCase().includes('failed')).length;
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        
        function showError(message) {
            document.getElementById('logContent').innerHTML = 
                `<div class="no-logs" style="color: #ff6b6b;">❌ ${message}</div>`;
        }
        
        async function downloadLogs() {
            window.location.href = 'logs-api.php?action=download&api_key=' + encodeURIComponent(apiKey);
        }
        
        function scrollToBottom() {
            const logContent = document.getElementById('logContent');
            logContent.scrollTop = logContent.scrollHeight;
        }
        
        function scrollToTop() {
            const logContent = document.getElementById('logContent');
            logContent.scrollTop = 0;
        }
        
        function toggleAutoRefresh() {
            const checkbox = document.getElementById('autoRefresh');
            if (checkbox.checked) {
                autoRefreshInterval = setInterval(loadLogs, 10000); // 10 seconds
            } else {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
        }
        
        // Load logs on page load
        loadLogs();
    </script>
</body>
</html>
