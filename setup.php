<?php
/**
 * Enhanced Auto-Setup Script for Web B API
 * Automatically installs FFmpeg and configures permissions
 */

echo "==============================================\n";
echo "Web B Video Processing API - Enhanced Setup\n";
echo "Version 2.0 - Auto-Install & Configuration\n";
echo "==============================================\n\n";

if (!file_exists(__DIR__ . '/config.php')) {
    echo "❌ ERROR: config.php not found!\n";
    exit(1);
}

$config = require __DIR__ . '/config.php';

echo "Step 1: Checking API Key Security...\n";
echo "-------------------------------------------\n";

$apiKeyStatus = '❌ INSECURE';
$apiKeyIssues = [];

if (empty($config['api_key'])) {
    $apiKeyIssues[] = "API key is empty";
} elseif ($config['api_key'] === 'CHANGE_ME_TO_A_SECURE_RANDOM_KEY') {
    $apiKeyIssues[] = "Using default placeholder API key";
} elseif (strlen($config['api_key']) < 32) {
    $apiKeyIssues[] = "API key is too short (minimum 32 characters recommended)";
} else {
    $apiKeyStatus = '✓ SECURE';
}

echo "API Key Status: {$apiKeyStatus}\n";
if (!empty($apiKeyIssues)) {
    echo "Issues:\n";
    foreach ($apiKeyIssues as $issue) {
        echo "  - {$issue}\n";
    }
    echo "\nGenerate a secure API key with:\n";
    echo "  openssl rand -hex 32\n";
    echo "\nOr use this one:\n";
    echo "  " . bin2hex(random_bytes(32)) . "\n\n";
}

echo "\nStep 2: Checking FFmpeg Installation...\n";
echo "-------------------------------------------\n";

$ffmpegPath = $config['ffmpeg_binary'];
exec(escapeshellarg($ffmpegPath) . ' -version 2>&1', $output, $returnCode);

if ($returnCode === 0) {
    echo "✓ FFmpeg is installed and accessible\n";
    echo "  Location: {$ffmpegPath}\n";
    echo "  Version: " . $output[0] . "\n";
} else {
    echo "❌ FFmpeg not found at {$ffmpegPath}\n";
    echo "  Attempting auto-install...\n\n";
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        echo "❌ Auto-install not supported on Windows\n";
        echo "   Download from: https://ffmpeg.org/download.html\n";
    } else {
        $isRoot = (posix_getuid() === 0);
        echo "Running as: " . ($isRoot ? "root" : "non-root user") . "\n";
        
        $installCmd = null;
        $pkgManager = null;
        
        if (file_exists('/usr/bin/apt-get')) {
            $pkgManager = 'apt-get';
            $installCmd = $isRoot ? 
                'apt-get update && apt-get install -y ffmpeg' : 
                'sudo apt-get update && sudo apt-get install -y ffmpeg';
        } elseif (file_exists('/usr/bin/yum')) {
            $pkgManager = 'yum';
            $installCmd = $isRoot ? 
                'yum install -y ffmpeg' : 
                'sudo yum install -y ffmpeg';
        } elseif (file_exists('/usr/bin/dnf')) {
            $pkgManager = 'dnf';
            $installCmd = $isRoot ? 
                'dnf install -y ffmpeg' : 
                'sudo dnf install -y ffmpeg';
        } elseif (file_exists('/usr/bin/brew')) {
            $pkgManager = 'homebrew';
            $installCmd = 'brew install ffmpeg';
        }
        
        if ($installCmd) {
            echo "Package manager: {$pkgManager}\n";
            echo "Install command: {$installCmd}\n\n";
            
            if (!$isRoot && $pkgManager !== 'homebrew') {
                exec('sudo -n true 2>&1', $sudoTest, $sudoCode);
                if ($sudoCode !== 0) {
                    echo "⚠ Cannot auto-install: Requires sudo privileges\n";
                    echo "Please run ONE of these commands manually:\n\n";
                    echo "Option 1 - Run this setup as root:\n";
                    echo "  sudo php setup.php\n\n";
                    echo "Option 2 - Install FFmpeg manually:\n";
                    echo "  {$installCmd}\n\n";
                    echo "Option 3 - Use Docker with FFmpeg pre-installed:\n";
                    echo "  See DEPLOYMENT-GUIDE.md for Docker setup\n\n";
                } else {
                    echo "Attempting installation with sudo...\n";
                    passthru($installCmd, $installResult);
                    
                    if ($installResult === 0) {
                        echo "\n✓ FFmpeg installed successfully\n";
                        $commonPaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
                        foreach ($commonPaths as $path) {
                            if (file_exists($path)) {
                                echo "  Location: {$path}\n";
                                break;
                            }
                        }
                    } else {
                        echo "\n❌ Installation failed\n";
                        echo "  Try manually: {$installCmd}\n";
                    }
                }
            } else {
                echo "Attempting installation...\n";
                passthru($installCmd, $installResult);
                
                if ($installResult === 0) {
                    echo "\n✓ FFmpeg installed successfully\n";
                    $commonPaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
                    foreach ($commonPaths as $path) {
                        if (file_exists($path)) {
                            echo "  Location: {$path}\n";
                            if ($path !== $ffmpegPath) {
                                echo "  ⚠ Update config.php: 'ffmpeg_binary' => '{$path}'\n";
                            }
                            break;
                        }
                    }
                } else {
                    echo "\n❌ Installation failed\n";
                    echo "  Try manually: {$installCmd}\n";
                }
            }
        } else {
            echo "❌ Could not detect package manager\n";
            echo "  Please install FFmpeg manually\n";
        }
    }
}

echo "\nStep 3: Creating & Configuring Directories...\n";
echo "-------------------------------------------\n";

$dirs = [
    'Videos' => $config['videos_dir'],
    'HLS' => $config['hls_dir'],
    'Logs' => dirname($config['log_file'])
];

$dirStatus = true;
foreach ($dirs as $name => $dir) {
    if (!is_dir($dir)) {
        echo "Creating {$name} directory: {$dir}\n";
        if (mkdir($dir, 0755, true)) {
            echo "  ✓ Created successfully\n";
        } else {
            echo "  ❌ Failed to create directory\n";
            $dirStatus = false;
        }
    } else {
        $writable = is_writable($dir);
        if ($writable) {
            echo "✓ {$name} directory: {$dir} (writable)\n";
        } else {
            echo "❌ {$name} directory: {$dir} (NOT writable)\n";
            echo "  Attempting to fix permissions...\n";
            
            if (chmod($dir, 0755)) {
                echo "  ✓ Permissions fixed\n";
            } else {
                echo "  ❌ Failed to fix permissions\n";
                echo "  Run manually: chmod -R 755 {$dir}\n";
                $dirStatus = false;
            }
        }
    }
}

echo "\nStep 4: Checking PHP Extensions...\n";
echo "-------------------------------------------\n";

$extensions = ['curl', 'json'];
$extStatus = true;

foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    if ($loaded) {
        echo "✓ {$ext} extension loaded\n";
    } else {
        echo "❌ {$ext} extension NOT loaded\n";
        $extStatus = false;
    }
}

if (!$extStatus) {
    echo "\nInstall missing extensions and restart PHP.\n";
}

echo "\nStep 5: Checking Web Server Configuration...\n";
echo "-------------------------------------------\n";

if (file_exists(__DIR__ . '/.htaccess')) {
    echo "✓ .htaccess file exists\n";
    
    if (function_exists('apache_get_modules')) {
        $modules = apache_get_modules();
        $required = ['mod_rewrite', 'mod_headers', 'mod_expires'];
        
        foreach ($required as $module) {
            if (in_array($module, $modules)) {
                echo "✓ {$module} enabled\n";
            } else {
                echo "❌ {$module} not enabled\n";
                echo "  Enable with: sudo a2enmod " . str_replace('mod_', '', $module) . "\n";
            }
        }
    } else {
        echo "⚠ Cannot check Apache modules (non-Apache or CLI mode)\n";
    }
} else {
    echo "❌ .htaccess file not found\n";
    echo "  Create .htaccess file for proper URL routing\n";
}

echo "\n==============================================\n";
echo "Setup Summary\n";
echo "==============================================\n\n";

$securityScore = 0;
$maxScore = 4;

if ($apiKeyStatus === '✓ SECURE') {
    echo "✓ API Key: Secure\n";
    $securityScore++;
} else {
    echo "❌ API Key: INSECURE - MUST FIX BEFORE DEPLOYMENT\n";
}

if ($returnCode === 0) {
    echo "✓ FFmpeg: Installed\n";
    $securityScore++;
} else {
    echo "❌ FFmpeg: NOT installed\n";
}

if ($dirStatus) {
    echo "✓ Directories: Configured correctly\n";
    $securityScore++;
} else {
    echo "⚠ Directories: Permissions need fixing\n";
}

if ($extStatus) {
    echo "✓ PHP Extensions: All loaded\n";
    $securityScore++;
} else {
    echo "❌ PHP Extensions: Missing extensions\n";
}

echo "\nSetup Score: {$securityScore}/{$maxScore}\n\n";

if ($securityScore < $maxScore) {
    echo "⚠ WARNING: Setup incomplete!\n";
    echo "Please fix the issues above before deploying.\n\n";
    echo "REQUIRED ACTIONS:\n";
    if ($apiKeyStatus !== '✓ SECURE') {
        echo "1. Set a secure API key in config.php\n";
    }
    if ($returnCode !== 0) {
        echo "2. Install FFmpeg\n";
    }
    if (!$dirStatus) {
        echo "3. Fix directory permissions\n";
    }
    if (!$extStatus) {
        echo "4. Install missing PHP extensions\n";
    }
    echo "\n";
    exit(1);
} else {
    echo "✓ Setup complete!\n";
    echo "Your API is ready for deployment.\n\n";
    echo "NEXT STEPS:\n";
    echo "1. Update config.php with your domain and settings\n";
    echo "2. Install SSL certificate (HTTPS required)\n";
    echo "3. Configure WordPress plugin with API endpoint and key\n";
    echo "4. Test with: php test.php [video-url]\n";
    echo "5. Test connection from WordPress settings page\n\n";
    exit(0);
}
