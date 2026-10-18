<?php

/**
 * Canyon Framework — Application Entry Point
 *
 * Bootstraps the application: loads environment, configures security,
 * starts the session, and dispatches the request through the router.
 */

// ---------------------------------------------------------------------------
// Base path constants
// ---------------------------------------------------------------------------

define('CANYON_BASE_PATH',     dirname(__DIR__));
define('CANYON_APP_PATH',      CANYON_BASE_PATH . '/app');
define('CANYON_CONFIG_PATH',   CANYON_BASE_PATH . '/config');
define('CANYON_ROUTES_PATH',   CANYON_BASE_PATH . '/routes');
define('CANYON_STORAGE_PATH',  CANYON_BASE_PATH . '/storage');
define('CANYON_RESOURCES_PATH', CANYON_BASE_PATH . '/resources');

// ---------------------------------------------------------------------------
// Load environment variables from .env
// ---------------------------------------------------------------------------

if (file_exists(CANYON_BASE_PATH . '/.env')) {
    $envLines = file(CANYON_BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($envLines as $line) {
        $line = trim($line);

        // Skip comments and lines without a key=value pair
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        $separatorPos = strpos($line, '=');
        $key   = trim(substr($line, 0, $separatorPos));
        $value = trim(substr($line, $separatorPos + 1));

        // Strip inline comments (preceded by whitespace)
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = trim(substr($value, 0, $commentPos));
        }

        // Remove surrounding quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        // Only accept valid variable names
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            $_ENV[$key] = $value;
        }
    }
}

require CANYON_APP_PATH . '/Helpers/env.php';

// Initialize application state
\Canyon\Core\Application::init();

// ---------------------------------------------------------------------------
// Error reporting — never expose details in production
// ---------------------------------------------------------------------------

$isDebug = env('APP_DEBUG', false) === true || env('APP_DEBUG', 'true') === 'true';

if ($isDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

ini_set('log_errors', '1');
ini_set('error_log', CANYON_STORAGE_PATH . '/logs/php_errors.log');

// ---------------------------------------------------------------------------
// Ensure storage directories exist
// ---------------------------------------------------------------------------

foreach (['/logs', '/cache', '/sessions'] as $directory) {
    $fullPath = CANYON_STORAGE_PATH . $directory;
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0750, true);
    }
}

// ---------------------------------------------------------------------------
// Secure session configuration
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');

    // Only mark the cookie as secure over HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_name('CANYONSESSID');

    // Configure session garbage collection to prevent accumulation
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', '1440'); // 24 minutes

    $sessionPath = CANYON_STORAGE_PATH . '/sessions';
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    session_start();
}

// ---------------------------------------------------------------------------
// HTTP Host header validation — prevents host header injection
// ---------------------------------------------------------------------------

$requestHost = $_SERVER['HTTP_HOST'] ?? null;

if ($requestHost !== null) {
    // Strip an explicit port so "localhost:8000" validates against "localhost"
    $hostName = strtolower($requestHost);
    if (str_contains($hostName, ':')) {
        $hostName = explode(':', $hostName, 2)[0];
    }

    // Build the allowed-host list from config
    $allowedHostsEnv = env('ALLOWED_HOSTS', '');
    $allowedHosts = !empty($allowedHostsEnv)
        ? array_map('trim', explode(',', $allowedHostsEnv))
        : ['localhost', '127.0.0.1'];

    $isHostValid = false;

    foreach ($allowedHosts as $allowedHost) {
        $allowedHost = strtolower(trim($allowedHost));
        if ($allowedHost === '') {
            continue;
        }

        // Exact match
        if ($hostName === $allowedHost) {
            $isHostValid = true;
            break;
        }

        // Wildcard subdomain: *.example.com
        if (str_starts_with($allowedHost, '*.')) {
            $baseDomain = substr($allowedHost, 2);
            if (str_ends_with($hostName, '.' . $baseDomain) && strlen($hostName) > strlen($baseDomain) + 1) {
                $isHostValid = true;
                break;
            }
        }
    }

    if (!$isHostValid) {
        $logPath = CANYON_STORAGE_PATH . '/logs/security.log';
        file_put_contents(
            $logPath,
            sprintf(
                "[%s] HOST_HEADER_INJECTION | IP: %s | Host: %s | Allowed: %s\n",
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $requestHost,
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

// ---------------------------------------------------------------------------
// Base URL (host already validated above)
// ---------------------------------------------------------------------------

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('CANYON_BASE_URL', $protocol . '://' . $requestHost);

// ---------------------------------------------------------------------------
// Security headers
// ---------------------------------------------------------------------------

if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // CSP: 'unsafe-inline' is kept for style-src only because the bundled
    // views use inline <style> blocks. script-src is locked to 'self' + CDN.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');

    // HSTS only over HTTPS in production
    $hstsEnabled = env('HSTS_ENABLED', true) === true || env('HSTS_ENABLED', 'true') === 'true';
    if ($hstsEnabled && $protocol === 'https') {
        $hstsMaxAge = (int) env('HSTS_MAX_AGE', 31536000);
        header("Strict-Transport-Security: max-age={$hstsMaxAge}; includeSubDomains");
    }
}

// ---------------------------------------------------------------------------
// Autoloader, view paths, routes, dispatch
// ---------------------------------------------------------------------------

if (file_exists(CANYON_BASE_PATH . '/vendor/autoload.php')) {
    require_once CANYON_BASE_PATH . '/vendor/autoload.php';
}

\Canyon\Core\View::addPath(CANYON_RESOURCES_PATH . '/views');

if (file_exists(CANYON_ROUTES_PATH . '/web.php')) {
    require CANYON_ROUTES_PATH . '/web.php';
}

$response = \Canyon\Core\Router::dispatch();

// Controllers return rendered output; error/JSON responses echo and exit
// internally, so only echo a returned string.
if (is_string($response)) {
    echo $response;
}
