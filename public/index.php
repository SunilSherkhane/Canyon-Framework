<?php

/**
 * Canyon Framework — Application Entry Point (Shared Hosting Optimized)
 *
 * Changes for shared hosting:
 * 1. Uses WordPress-style config.php (single file configuration)
 * 2. Lazy loads classes only when needed
 * 3. Memory-optimized for low-resource environments
 * 4. Works without composer/vendor directory
 * 5. Graceful degradation when extensions are missing
 */

// ============================================================================
// Load WordPress-style configuration
// ============================================================================

$configPath = dirname(__DIR__) . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
} elseif (file_exists(dirname(__DIR__) . '/.env')) {
    // Fallback to .env if config.php doesn't exist
    $envLines = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        $separatorPos = strpos($line, '=');
        $key = trim(substr($line, 0, $separatorPos));
        $value = trim(substr($line, $separatorPos + 1));
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) $value = trim(substr($value, 0, $commentPos));
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            $_ENV[$key] = $value;
        }
    }
}

// ============================================================================
// Constants (from config.php or defaults)
// ============================================================================

defined('CANYON_BASE_PATH')      || define('CANYON_BASE_PATH', dirname(__DIR__));
defined('CANYON_APP_PATH')       || define('CANYON_APP_PATH', CANYON_BASE_PATH . '/app');
defined('CANYON_STORAGE_PATH')   || define('CANYON_STORAGE_PATH', CANYON_BASE_PATH . '/storage');
defined('CANYON_RESOURCES_PATH') || define('CANYON_RESOURCES_PATH', CANYON_BASE_PATH . '/resources');
defined('CANYON_ROUTES_PATH')    || define('CANYON_ROUTES_PATH', CANYON_BASE_PATH . '/routes');
defined('CANYON_PLUGINS_PATH')   || define('CANYON_PLUGINS_PATH', CANYON_BASE_PATH . '/plugins');

// ============================================================================
// Lightweight autoloader (no composer required)
// ============================================================================

spl_autoload_register(function ($class) {
    // Only handle Canyon classes
    if (!str_starts_with($class, 'Canyon\\')) {
        return;
    }

    $prefix = 'Canyon\\';
    $baseDir = CANYON_APP_PATH . '/';

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// ============================================================================
// Load helpers (lightweight)
// ============================================================================

require_once CANYON_APP_PATH . '/Helpers/env.php';
require_once CANYON_APP_PATH . '/Helpers/functions.php';

// ============================================================================
// Bridge config.php constants into the env() helper
// ============================================================================
// config.php is the single source of truth; env() reads from $_ENV. Copy the
// core values across so framework classes and views see the same settings.

foreach ([
    'APP_NAME'  => 'CANYON_APP_NAME',
    'APP_ENV'   => 'CANYON_ENV',
    'APP_URL'   => 'CANYON_SITE_URL',
    'APP_DEBUG' => 'CANYON_DEBUG',
] as $envKey => $constKey) {
    if (defined($constKey) && !array_key_exists($envKey, $_ENV)) {
        $_ENV[$envKey] = constant($constKey);
    }
}

// ============================================================================
// Environment & error reporting
// ============================================================================

$isDebug = defined('CANYON_DEBUG') ? CANYON_DEBUG : (env('APP_DEBUG', 'true') === 'true');

if ($isDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

if (defined('CANYON_LOG_ERRORS') && CANYON_LOG_ERRORS) {
    ini_set('log_errors', '1');
    ini_set('error_log', CANYON_STORAGE_PATH . '/logs/php_errors.log');
}

// ============================================================================
// Ensure storage directories exist (lightweight check)
// ============================================================================

foreach (['/logs', '/cache', '/sessions'] as $directory) {
    $fullPath = CANYON_STORAGE_PATH . $directory;
    if (!is_dir($fullPath)) {
        @mkdir($fullPath, 0755, true);
    }
}

// ============================================================================
// Session configuration (shared hosting safe)
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');
    
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    
    session_name('CANYONSESSID');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', defined('CANYON_SESSION_LIFETIME') ? CANYON_SESSION_LIFETIME : '1440');
    
    $sessionPath = CANYON_STORAGE_PATH . '/sessions';
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    
    session_start();
}

// ============================================================================
// Host header validation (if not disabled)
// ============================================================================

$requestHost = $_SERVER['HTTP_HOST'] ?? null;

if ($requestHost !== null) {
    $hostName = strtolower($requestHost);
    if (str_contains($hostName, ':')) {
        $hostName = explode(':', $hostName, 2)[0];
    }

    $allowedHosts = defined('CANYON_ALLOWED_HOSTS') ? CANYON_ALLOWED_HOSTS : env('ALLOWED_HOSTS', 'localhost,127.0.0.1');
    $allowedHosts = array_map('trim', explode(',', $allowedHosts));

    $isHostValid = false;
    foreach ($allowedHosts as $allowedHost) {
        $allowedHost = strtolower(trim($allowedHost));
        if ($allowedHost === '') continue;
        if ($allowedHost === '*') {
            $isHostValid = true;
            break;
        }
        if ($hostName === $allowedHost) {
            $isHostValid = true;
            break;
        }
        if (str_starts_with($allowedHost, '*.')) {
            $baseDomain = substr($allowedHost, 2);
            if (str_ends_with($hostName, '.' . $baseDomain) && strlen($hostName) > strlen($baseDomain) + 1) {
                $isHostValid = true;
                break;
            }
        }
    }

    if (!$isHostValid) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Bad Request';
        exit;
    }
}

// ============================================================================
// Base URL
// ============================================================================

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$siteUrl = defined('CANYON_SITE_URL') ? CANYON_SITE_URL : $protocol . '://' . $requestHost;
define('CANYON_BASE_URL', rtrim($siteUrl, '/'));

// ============================================================================
// Security headers (lightweight)
// ============================================================================

if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    $csp = "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    header("Content-Security-Policy: $csp");
    
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
    
    if ($protocol === 'https') {
        $hstsEnabled = defined('CANYON_HSTS_ENABLED') ? CANYON_HSTS_ENABLED : true;
        if ($hstsEnabled) {
            $hstsMaxAge = defined('CANYON_HSTS_MAX_AGE') ? CANYON_HSTS_MAX_AGE : 31536000;
            header("Strict-Transport-Security: max-age={$hstsMaxAge}; includeSubDomains");
        }
    }
}

// ============================================================================
// Bootstrap: shared application data + view paths
// ============================================================================

\Canyon\Core\Application::init();
\Canyon\Core\View::addPath(CANYON_RESOURCES_PATH . '/views');

// ============================================================================
// Load routes and dispatch (lazy load everything else)
// ============================================================================

if (file_exists(CANYON_ROUTES_PATH . '/web.php')) {
    require CANYON_ROUTES_PATH . '/web.php';
}

$response = \Canyon\Core\Router::dispatch();

if (is_string($response)) {
    echo $response;
}
