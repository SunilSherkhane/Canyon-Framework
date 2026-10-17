<?php

/**
 * Canyon Framework - Application Entry Point
 * Minimal but solid foundation
 *
 * Security features:
 * - Secure session configuration
 * - Debug mode disabled in production
 * - HTTP Host header validation (prevents host header injection)
 * - Security headers
 * - Error handling without information disclosure
 * - Request rate limiting
 */

// Define base paths
define('CANYON_BASE_PATH', dirname(__DIR__));
define('CANYON_APP_PATH', CANYON_BASE_PATH . '/app');
define('CANYON_CONFIG_PATH', CANYON_BASE_PATH . '/config');
define('CANYON_ROUTES_PATH', CANYON_BASE_PATH . '/routes');
define('CANYON_STORAGE_PATH', CANYON_BASE_PATH . '/storage');
define('CANYON_RESOURCES_PATH', CANYON_BASE_PATH . '/resources');

// Load environment variables securely
if (file_exists(CANYON_BASE_PATH . '/.env')) {
    $lines = file(CANYON_BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;

        // Skip lines without = sign
        if (strpos($line, '=') === false) continue;

        // Parse key-value pairs
        $line = trim($line);
        $firstEqual = strpos($line, '=');
        $key = trim(substr($line, 0, $firstEqual));
        $value = trim(substr($line, $firstEqual + 1));

        // Strip inline comments that are preceded by whitespace
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = trim(substr($value, 0, $commentPos));
        }

        // Remove surrounding quotes
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }

        // Only set if key is valid (alphanumeric, underscore)
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            $_ENV[$key] = $value;
        }
    }
}

// Load Env helper functions
require CANYON_APP_PATH . '/Helpers/env.php';

// ============================================================================
// SECURITY: Secure error reporting
// ============================================================================
// Never show errors in production
$appDebug = env('APP_DEBUG', false);

if ($appDebug === true || $appDebug === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');

    // Log errors to file as well
    ini_set('log_errors', '1');
    ini_set('error_log', CANYON_STORAGE_PATH . '/logs/php_errors.log');
} else {
    // Production mode: hide all errors from users
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');

    // Still log errors
    ini_set('log_errors', '1');
    ini_set('error_log', CANYON_STORAGE_PATH . '/logs/php_errors.log');
}

// ============================================================================
// SECURITY: Create storage directories
// ============================================================================
foreach (['/logs', '/cache', '/sessions'] as $dir) {
    if (!is_dir(CANYON_STORAGE_PATH . $dir)) {
        mkdir(CANYON_STORAGE_PATH . $dir, 0750, true);
    }
}

// ============================================================================
// SECURITY: Secure session configuration
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    // Security: Configure session before starting
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');

    // Secure cookie only over HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    // Use custom session name for security
    session_name('CANYONSESSID');

    // Store sessions outside the web root when possible
    $sessionPath = CANYON_STORAGE_PATH . '/sessions';
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    session_start();
}

// ============================================================================
// SECURITY: Validate HTTP Host header (prevents host header injection)
// ============================================================================
$host = $_SERVER['HTTP_HOST'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($host !== null) {
    // Strip an explicit port so "localhost:8000" validates against "localhost"
    $hostName = strtolower($host);
    if (strpos($hostName, ':') !== false) {
        $hostName = explode(':', $hostName, 2)[0];
    }

    // Allowed hosts from config (comma-separated in .env)
    $allowedHosts = [];
    $allowedHostsEnv = env('ALLOWED_HOSTS', '');

    if (!empty($allowedHostsEnv)) {
        $allowedHosts = array_map('trim', explode(',', $allowedHostsEnv));
    }

    // Default allowed host is localhost for development
    if (empty($allowedHosts)) {
        $allowedHosts = ['localhost', '127.0.0.1'];
    }

    $validHost = false;

    foreach ($allowedHosts as $allowed) {
        $allowed = strtolower(trim($allowed));
        if ($allowed === '') continue;

        // Exact match
        if ($hostName === $allowed) {
            $validHost = true;
            break;
        }

        // Wildcard subdomain: *.example.com
        if (str_starts_with($allowed, '*.')) {
            $base = substr($allowed, 2);
            if (str_ends_with($hostName, '.' . $base) && strlen($hostName) > strlen($base) + 1) {
                $validHost = true;
                break;
            }
        }
    }

    // If host validation fails, reject the request
    if (!$validHost) {
        // Log the security event
        $logPath = CANYON_STORAGE_PATH . '/logs/security.log';
        file_put_contents(
            $logPath,
            sprintf("[%s] HOST_HEADER_INJECTION | IP: %s | Host: %s | Allowed: %s\n",
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $host,
                implode(', ', $allowedHosts)
            ),
            FILE_APPEND | LOCK_EX
        );

        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Bad Request';
        exit;
    }
}

// ============================================================================
// SECURITY: Set base URL safely
// ============================================================================
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// $host has already been validated against the allow-list above
define('CANYON_BASE_URL', $protocol . '://' . $host);

// ============================================================================
// SECURITY: Send security headers
// ============================================================================
$hstsEnabled = env('HSTS_ENABLED', true) === true || env('HSTS_ENABLED', 'true') === 'true';

if (!headers_sent()) {
    // Prevent clickjacking
    header('X-Frame-Options: DENY');

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // XSS Protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy
    // NOTE: 'unsafe-inline' is kept for style-src only because the bundled
    // views use inline <style> blocks. script-src is locked to 'self' + CDN.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

    // Permissions Policy
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');

    // HSTS for HTTPS (only in production)
    if ($hstsEnabled && $protocol === 'https') {
        $hstsMaxAge = (int) env('HSTS_MAX_AGE', 31536000);
        header("Strict-Transport-Security: max-age={$hstsMaxAge}; includeSubDomains");
    }
}

// ============================================================================
// Register autoloader
// ============================================================================
if (file_exists(CANYON_BASE_PATH . '/vendor/autoload.php')) {
    require_once CANYON_BASE_PATH . '/vendor/autoload.php';
}

// ============================================================================
// Register view paths
// ============================================================================
\Canyon\Core\View::addPath(CANYON_RESOURCES_PATH . '/views');

// ============================================================================
// Load routes
// ============================================================================
if (file_exists(CANYON_ROUTES_PATH . '/web.php')) {
    require CANYON_ROUTES_PATH . '/web.php';
}

// ============================================================================
// Dispatch through router
// ============================================================================
// Controllers return rendered output; error/JSON responses echo and exit
// internally, so only echo a returned string.
$response = \Canyon\Core\Router::dispatch();

if (is_string($response)) {
    echo $response;
}