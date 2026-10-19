<?php

/**
 * Canyon Framework - Application Entry Point (Shared Hosting Optimized)
 *
 * Changes for shared hosting:
 * 1. Uses WordPress-style config.php (single file configuration)
 * 2. Lazy loads classes only when needed
 * 3. Memory-optimized for low-resource environments
 * 4. Works without composer/vendor directory
 * 5. Graceful degradation when extensions are missing
 *
 * The framework itself is assembled from components: a mandatory `core` kernel
 * plus optional addons (ai, social, security, performance). An application may
 * run its own checkout of Canyon, or consume a shared one by defining
 * CANYON_SHARED_PATH in its config.php.
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

// Where the Canyon framework itself lives. An application that consumes Canyon
// from a shared install sets CANYON_SHARED_PATH in its config.php; a standalone
// Canyon checkout leaves it unset, so it resolves to its own directory.
defined('CANYON_SHARED_PATH')     || define('CANYON_SHARED_PATH', CANYON_BASE_PATH);
defined('CANYON_FRAMEWORK_PATH')  || define('CANYON_FRAMEWORK_PATH', CANYON_SHARED_PATH);
defined('CANYON_COMPONENTS_PATH') || define('CANYON_COMPONENTS_PATH', CANYON_FRAMEWORK_PATH . '/components');

defined('CANYON_APP_PATH')       || define('CANYON_APP_PATH', CANYON_BASE_PATH . '/app');
defined('CANYON_STORAGE_PATH')   || define('CANYON_STORAGE_PATH', CANYON_BASE_PATH . '/storage');
defined('CANYON_RESOURCES_PATH') || define('CANYON_RESOURCES_PATH', CANYON_BASE_PATH . '/resources');
defined('CANYON_ROUTES_PATH')    || define('CANYON_ROUTES_PATH', CANYON_BASE_PATH . '/routes');
defined('CANYON_PLUGINS_PATH')   || define('CANYON_PLUGINS_PATH', CANYON_BASE_PATH . '/plugins');

// ============================================================================
// Components — the framework kernel (`core`) plus optional addons.
// ============================================================================
// Each component is a self-contained directory holding a component.json
// manifest (name, version, dependencies, autoload map, enabled flag).
// Discovery has to happen before the autoloader is registered, because
// registering the autoloader is what makes those classes reachable.

require_once CANYON_FRAMEWORK_PATH . '/app/Helpers/components.php';

$canyonDisabledComponents = defined('CANYON_COMPONENTS_DISABLED')
    ? (array) CANYON_COMPONENTS_DISABLED
    : [];

// Persisted enable/disable decisions live in components.json, alongside the
// components directory (written by `canyon component enable|disable`).
$canyonComponentsConfig = dirname(CANYON_COMPONENTS_PATH) . '/components.json';
if (is_file($canyonComponentsConfig)) {
    $canyonDecoded = json_decode((string) @file_get_contents($canyonComponentsConfig), true);
    if (is_array($canyonDecoded) && isset($canyonDecoded['disabled'])) {
        $canyonDisabledComponents = array_values(array_unique(array_merge(
            $canyonDisabledComponents,
            array_map('strval', (array) $canyonDecoded['disabled'])
        )));
    }
}

$canyonComponents        = canyon_discover_components(CANYON_COMPONENTS_PATH);
$canyonEnabledComponents = canyon_resolve_components($canyonComponents, $canyonDisabledComponents);
$canyonComponentPsr4     = canyon_component_psr4($canyonEnabledComponents);
$canyonComponentFiles    = canyon_component_files($canyonEnabledComponents);

// ============================================================================
// Lightweight autoloader (no composer required)
// ============================================================================
// Resolution order: application classes (so an app may override a framework
// default), then the framework's components, then the framework's own app
// skeleton (the base Controller).

spl_autoload_register(function ($class) use ($canyonComponentPsr4) {
    // Only handle Canyon classes
    if (!str_starts_with($class, 'Canyon\\')) {
        return;
    }

    $relativeClass = str_replace('\\', '/', substr($class, strlen('Canyon\\'))) . '.php';

    // 1. Application classes.
    $appFile = CANYON_APP_PATH . '/' . $relativeClass;
    if (is_file($appFile)) {
        require $appFile;
        return;
    }

    // 2. Framework components (core kernel + enabled addons).
    foreach ($canyonComponentPsr4 as $prefix => $directories) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $componentRelative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        foreach ($directories as $directory) {
            $file = $directory . '/' . $componentRelative;
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }

    // 3. The framework's own app skeleton.
    $frameworkFile = CANYON_FRAMEWORK_PATH . '/app/' . $relativeClass;
    if (is_file($frameworkFile)) {
        require $frameworkFile;
    }
});

// ============================================================================
// Load helpers (lightweight)
// ============================================================================

require_once CANYON_FRAMEWORK_PATH . '/app/Helpers/env.php';
require_once CANYON_FRAMEWORK_PATH . '/app/Helpers/functions.php';

// Plain PHP files that enabled components ask to be required up front.
foreach ($canyonComponentFiles as $canyonComponentFile) {
    require_once $canyonComponentFile;
}

// Application helpers (view helpers, formatting) are optional — the framework
// itself ships none.
if (is_file(CANYON_APP_PATH . '/Helpers/app.php')) {
    require_once CANYON_APP_PATH . '/Helpers/app.php';
}

// ============================================================================
// Bridge config.php constants into the env() helper
// ============================================================================
// config.php is the single source of truth; env() reads from $_ENV. Copy the
// core values across so framework classes and views see the same settings.

foreach ([
    'APP_NAME'      => 'CANYON_APP_NAME',
    'APP_ENV'       => 'CANYON_ENV',
    'APP_URL'       => 'CANYON_SITE_URL',
    'APP_DEBUG'     => 'CANYON_DEBUG',
    'DB_CONNECTION' => 'CANYON_DB_CONNECTION',
    'DB_DATABASE'   => 'CANYON_DB_PATH',
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
// Bootstrap: components, shared application data + view paths
// ============================================================================

\Canyon\Core\Component::boot(CANYON_COMPONENTS_PATH, $canyonDisabledComponents);

\Canyon\Core\Application::init();

// Application views are registered first so an app can override a framework
// view of the same name (View::findView returns the first match).
\Canyon\Core\View::addPath(CANYON_RESOURCES_PATH . '/views');

if (CANYON_FRAMEWORK_PATH !== CANYON_BASE_PATH && is_dir(CANYON_FRAMEWORK_PATH . '/resources/views')) {
    \Canyon\Core\View::addPath(CANYON_FRAMEWORK_PATH . '/resources/views');
}

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
