<?php

/**
 * Canyon Framework — Web Installer
 * 
 * Visit this file in your browser to install Canyon Framework.
 * Like WordPress, this guides you through the setup process.
 * 
 * Usage: http://yourdomain.com/install.php
 */

// Prevent re-installation
if (file_exists(__DIR__ . '/.installed')) {
    die('Canyon Framework is already installed. Delete the .installed file to reinstall.');
}

// Check PHP version
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    die('Canyon Framework requires PHP 8.1 or higher. Your version: ' . PHP_VERSION);
}

// Required extensions
$requiredExtensions = ['pdo', 'mbstring', 'json'];
$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

// Installation steps
$step = $_GET['step'] ?? '1';
$errors = [];
$success = false;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case '2':
            // Test database connection
            $host = $_POST['db_host'] ?? 'localhost';
            $port = $_POST['db_port'] ?? 3306;
            $name = $_POST['db_name'] ?? '';
            $user = $_POST['db_user'] ?? '';
            $pass = $_POST['db_pass'] ?? '';
            
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$name}";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                
                // Create tables
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `sessions` (
                        `id` VARCHAR(128) NOT NULL,
                        `data` TEXT,
                        `last_activity` INT UNSIGNED NOT NULL,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    
                    CREATE TABLE IF NOT EXISTS `cache` (
                        `key` VARCHAR(255) NOT NULL,
                        `value` TEXT,
                        `expires_at` INT UNSIGNED NOT NULL,
                        PRIMARY KEY (`key`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    
                    CREATE TABLE IF NOT EXISTS `notifications` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `user_id` INT NOT NULL,
                        `title` VARCHAR(255) NOT NULL,
                        `message` TEXT,
                        `is_read` TINYINT(1) DEFAULT 0,
                        `created_at` INT UNSIGNED NOT NULL,
                        INDEX `idx_user_id` (`user_id`),
                        INDEX `idx_is_read` (`is_read`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    
                    CREATE TABLE IF NOT EXISTS `settings` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `key` VARCHAR(255) NOT NULL,
                        `value` TEXT,
                        `user_id` INT DEFAULT NULL,
                        UNIQUE KEY `idx_key_user` (`key`, `user_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    
                    CREATE TABLE IF NOT EXISTS `river` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `type` VARCHAR(50) NOT NULL,
                        `subtype` VARCHAR(50) NOT NULL,
                        `action_type` VARCHAR(50) NOT NULL,
                        `entity_id` INT NOT NULL,
                        `entity_type` VARCHAR(50) NOT NULL,
                        `owner_id` INT NOT NULL,
                        `created_at` INT UNSIGNED NOT NULL,
                        INDEX `idx_entity` (`entity_id`, `entity_type`),
                        INDEX `idx_owner` (`owner_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    
                    CREATE TABLE IF NOT EXISTS `queue` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `queue` VARCHAR(255) DEFAULT 'default',
                        `payload` TEXT NOT NULL,
                        `attempts` TINYINT UNSIGNED DEFAULT 0,
                        `reserved_at` INT UNSIGNED DEFAULT NULL,
                        `available_at` INT UNSIGNED NOT NULL,
                        `created_at` INT UNSIGNED NOT NULL,
                        INDEX `idx_queue` (`queue`, `reserved_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                
                // Save config
                $configContent = "<?php\n\n";
                $configContent .= "define('CANYON_DB_HOST', '" . addslashes($host) . "');\n";
                $configContent .= "define('CANYON_DB_PORT', " . (int)$port . ");\n";
                $configContent .= "define('CANYON_DB_NAME', '" . addslashes($name) . "');\n";
                $configContent .= "define('CANYON_DB_USER', '" . addslashes($user) . "');\n";
                $configContent .= "define('CANYON_DB_PASS', '" . addslashes($pass) . "');\n";
                $configContent .= "define('CANYON_DB_CHARSET', 'utf8mb4');\n\n";
                $configContent .= "define('CANYON_SITE_URL', '" . addslashes($_POST['site_url'] ?? '') . "');\n";
                $configContent .= "define('CANYON_APP_NAME', '" . addslashes($_POST['app_name'] ?? 'Canyon App') . "');\n";
                $configContent .= "define('CANYON_TIMEZONE', '" . addslashes($_POST['timezone'] ?? 'UTC') . "');\n";
                $configContent .= "define('CANYON_LOCALE', 'en');\n\n";
                $configContent .= "define('CANYON_APP_KEY', '" . bin2hex(random_bytes(32)) . "');\n\n";
                $configContent .= "define('CANYON_ALLOWED_HOSTS', '" . addslashes($_POST['allowed_hosts'] ?? '') . "');\n\n";
                $configContent .= "define('CANYON_ENV', 'production');\n";
                $configContent .= "define('CANYON_DEBUG', false);\n";
                $configContent .= "define('CANYON_LOG_ERRORS', true);\n\n";
                $configContent .= "define('CANYON_CACHE_ENABLED', true);\n";
                $configContent .= "define('CANYON_CACHE_TTL', 3600);\n";
                $configContent .= "define('CANYON_ASSET_MINIFY', true);\n";
                $configContent .= "define('CANYON_IMAGE_OPTIMIZE', false);\n";
                $configContent .= "define('CANYON_DB_CLEANUP', true);\n\n";
                $configContent .= "define('CANYON_MAIL_METHOD', 'mail');\n";
                $configContent .= "define('CANYON_MAIL_FROM', 'noreply@" . addslashes($_POST['site_url'] ?? 'example.com') . "');\n";
                $configContent .= "define('CANYON_MAIL_FROM_NAME', '" . addslashes($_POST['app_name'] ?? 'Canyon App') . "');\n\n";
                $configContent .= "define('CANYON_SESSION_LIFETIME', 1440);\n";
                $configContent .= "define('CANYON_UPLOAD_MAX_SIZE', 10);\n";
                $configContent .= "define('CANYON_UPLOAD_ALLOWED', 'jpg,jpeg,png,gif,pdf,doc,docx,zip');\n";
                $configContent .= "define('CANYON_MULTISITE', false);\n";
                $configContent .= "define('CANYON_API_ENABLED', false);\n";
                $configContent .= "define('CANYON_API_RATE_LIMIT', 60);\n\n";
                $configContent .= "define('CANYON_BASE_PATH', '" . addslashes(dirname(__DIR__)) . "');\n";
                $configContent .= "define('CANYON_APP_PATH', CANYON_BASE_PATH . '/app');\n";
                $configContent .= "define('CANYON_STORAGE_PATH', CANYON_BASE_PATH . '/storage');\n";
                $configContent .= "define('CANYON_RESOURCES_PATH', CANYON_BASE_PATH . '/resources');\n";
                $configContent .= "define('CANYON_ROUTES_PATH', CANYON_BASE_PATH . '/routes');\n";
                $configContent .= "define('CANYON_PLUGINS_PATH', CANYON_BASE_PATH . '/plugins');\n";
                
                file_put_contents(dirname(__DIR__) . '/config.php', $configContent);
                
                // Create .installed marker
                file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));
                
                // Create storage directories
                $storageDirs = ['/logs', '/cache', '/sessions'];
                foreach ($storageDirs as $dir) {
                    $path = dirname(__DIR__) . '/storage' . $dir;
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                    }
                }
                
                $success = true;
                $step = '3';
                
            } catch (PDOException $e) {
                $errors[] = 'Database connection failed: ' . $e->getMessage();
            }
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canyon Framework Installer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #3498db;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        .step-number.active {
            background: #27ae60;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"],
        input[type="url"],
        input[type="number"],
        select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        input:focus {
            outline: none;
            border-color: #3498db;
        }
        .help-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #3498db;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover {
            background: #2980b9;
        }
        .btn-success {
            background: #27ae60;
        }
        .btn-success:hover {
            background: #219a52;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }
        .alert-warning {
            background: #fff8e1;
            color: #f57c00;
            border: 1px solid #ffe082;
        }
        .requirements {
            margin: 20px 0;
        }
        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 0;
            font-size: 14px;
        }
        .requirement.ok {
            color: #27ae60;
        }
        .requirement.fail {
            color: #e74c3c;
        }
        .icon {
            width: 20px;
            text-align: center;
        }
        .success-message {
            text-align: center;
            padding: 40px 20px;
        }
        .success-message h2 {
            color: #27ae60;
            margin-bottom: 15px;
        }
        .success-message p {
            color: #7f8c8d;
            margin-bottom: 30px;
        }
        .next-steps {
            text-align: left;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-top: 20px;
        }
        .next-steps h3 {
            margin-bottom: 10px;
            font-size: 16px;
        }
        .next-steps ul {
            margin-left: 20px;
        }
        .next-steps li {
            margin-bottom: 5px;
            font-size: 14px;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <?php if ($step === '1'): ?>
                <!-- Step 1: Welcome -->
                <div class="step">
                    <div class="step-number active">1</div>
                    <div>
                        <h1>Welcome to Canyon Framework</h1>
                        <p class="subtitle">Let's get you set up in a few minutes</p>
                    </div>
                </div>
                
                <p style="margin-bottom: 20px;">Before we begin, let's check if your server meets the requirements:</p>
                
                <div class="requirements">
                    <div class="requirement <?php echo version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail'; ?>">
                        <span class="icon"><?php echo version_compare(PHP_VERSION, '8.1.0', '>=') ? '✓' : '✗'; ?></span>
                        PHP 8.1 or higher (Your version: <?php echo PHP_VERSION; ?>)
                    </div>
                    <div class="requirement <?php echo extension_loaded('pdo') ? 'ok' : 'fail'; ?>">
                        <span class="icon"><?php echo extension_loaded('pdo') ? '✓' : '✗'; ?></span>
                        PDO Extension
                    </div>
                    <div class="requirement <?php echo extension_loaded('mbstring') ? 'ok' : 'fail'; ?>">
                        <span class="icon"><?php echo extension_loaded('mbstring') ? '✓' : '✗'; ?></span>
                        Mbstring Extension
                    </div>
                    <div class="requirement <?php echo extension_loaded('json') ? 'ok' : 'fail'; ?>">
                        <span class="icon"><?php echo extension_loaded('json') ? '✓' : '✗'; ?></span>
                        JSON Extension
                    </div>
                    <div class="requirement <?php echo is_writable(dirname(__DIR__) . '/storage') || @mkdir(dirname(__DIR__) . '/storage', 0755, true) ? 'ok' : 'fail'; ?>">
                        <span class="icon"><?php echo is_writable(dirname(__DIR__) . '/storage') || @mkdir(dirname(__DIR__) . '/storage', 0755, true) ? '✓' : '✗'; ?></span>
                        Storage directory is writable
                    </div>
                </div>
                
                <?php if (!empty($missingExtensions)): ?>
                    <div class="alert alert-error">
                        Please install the missing PHP extensions and refresh this page.
                    </div>
                <?php else: ?>
                    <a href="?step=2" class="btn">Continue →</a>
                <?php endif; ?>
                
            <?php elseif ($step === '2'): ?>
                <!-- Step 2: Database Configuration -->
                <div class="step">
                    <div class="step-number active">2</div>
                    <div>
                        <h1>Database Configuration</h1>
                        <p class="subtitle">Enter your database connection details</p>
                    </div>
                </div>
                
                <p style="margin-bottom: 20px;">
                    You'll need to create a database in your hosting control panel first.
                    If you're not sure how to do this, contact your hosting provider.
                </p>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="?step=2">
                    <div class="form-group">
                        <label for="db_host">Database Host</label>
                        <input type="text" id="db_host" name="db_host" value="localhost" required>
                        <p class="help-text">Usually 'localhost' on shared hosting. Check your hosting control panel.</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_port">Database Port</label>
                        <input type="number" id="db_port" name="db_port" value="3306" required>
                        <p class="help-text">Usually 3306 for MySQL.</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_name">Database Name</label>
                        <input type="text" id="db_name" name="db_name" required>
                        <p class="help-text">Create this in your hosting control panel first.</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_user">Database Username</label>
                        <input type="text" id="db_user" name="db_user" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_pass">Database Password</label>
                        <input type="password" id="db_pass" name="db_pass">
                    </div>
                    
                    <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
                    
                    <div class="form-group">
                        <label for="site_url">Site URL</label>
                        <input type="url" id="site_url" name="site_url" placeholder="https://example.com" required>
                        <p class="help-text">Your domain name. No trailing slash.</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="app_name">Application Name</label>
                        <input type="text" id="app_name" name="app_name" value="Canyon App" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone">
                            <option value="UTC">UTC</option>
                            <option value="America/New_York">Eastern Time (US & Canada)</option>
                            <option value="America/Chicago">Central Time (US & Canada)</option>
                            <option value="America/Denver">Mountain Time (US & Canada)</option>
                            <option value="America/Los_Angeles">Pacific Time (US & Canada)</option>
                            <option value="Europe/London">London</option>
                            <option value="Europe/Paris">Paris</option>
                            <option value="Asia/Tokyo">Tokyo</option>
                            <option value="Asia/Shanghai">Shanghai</option>
                            <option value="Asia/Kolkata">India</option>
                            <option value="Australia/Sydney">Sydney</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="allowed_hosts">Allowed Hosts</label>
                        <input type="text" id="allowed_hosts" name="allowed_hosts" placeholder="example.com,www.example.com">
                        <p class="help-text">Comma-separated list of allowed domain names.</p>
                    </div>
                    
                    <button type="submit" class="btn">Install Canyon Framework</button>
                </form>
                
            <?php elseif ($step === '3'): ?>
                <!-- Step 3: Success -->
                <div class="success-message">
                    <h2>✓ Installation Complete!</h2>
                    <p>Canyon Framework has been successfully installed.</p>
                    
                    <div class="next-steps">
                        <h3>Next Steps:</h3>
                        <ul>
                            <li><strong>Delete <code>install.php</code></strong> for security</li>
                            <li><strong>Delete <code>config.php</code></strong> if you want to re-install</li>
                            <li>Edit <code>config.php</code> to customize settings</li>
                            <li>Start building your application!</li>
                        </ul>
                    </div>
                    
                    <p style="margin-top: 30px;">
                        <a href="/" class="btn btn-success">Visit Your Site</a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>