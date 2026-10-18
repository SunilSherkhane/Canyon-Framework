<?php

/**
 * Canyon Framework — Master Configuration (WordPress-style)
 * 
 * This is the ONLY file you need to edit to configure your Canyon application.
 * Like WordPress wp-config.php, everything is in one place.
 * 
 * For shared hosting (cPanel, Plesk, GoDaddy, HostGator, etc.):
 * 1. Upload all files to your hosting account
 * 2. Edit this file with your database credentials
 * 3. Visit yourdomain.com/install.php in your browser
 * 4. Follow the on-screen instructions
 */

// ============================================================================
// DATABASE SETTINGS
// ============================================================================

/**
 * Database host. On shared hosting, this is usually 'localhost'.
 * Some hosts use a different host (e.g., 'mysql123.example.com').
 * Check your hosting control panel for the exact value.
 */
define('CANYON_DB_HOST', 'localhost');

/**
 * Database port. Usually 3306 for MySQL.
 */
define('CANYON_DB_PORT', 3306);

/**
 * Database name. Create this in your hosting control panel first.
 */
define('CANYON_DB_NAME', 'canyon');

/**
 * Database username. Create this in your hosting control panel first.
 */
define('CANYON_DB_USER', 'root');

/**
 * Database password.
 */
define('CANYON_DB_PASS', '');

/**
 * Database character set. Leave as-is unless you know what you're doing.
 */
define('CANYON_DB_CHARSET', 'utf8mb4');

// ============================================================================
// APPLICATION SETTINGS
// ============================================================================

/**
 * Your site URL. Include http:// or https:// but NO trailing slash.
 * Examples:
 *   define('CANYON_SITE_URL', 'https://example.com');
 *   define('CANYON_SITE_URL', 'https://example.com/myapp');
 */
define('CANYON_SITE_URL', 'http://localhost');

/**
 * Application name shown in page titles and emails.
 */
define('CANYON_APP_NAME', 'Canyon App');

/**
 * Timezone for your application.
 * See: https://www.php.net/manual/en/timezones.php
 */
define('CANYON_TIMEZONE', 'UTC');

/**
 * Default language.
 */
define('CANYON_LOCALE', 'en');

// ============================================================================
// SECURITY SETTINGS
// ============================================================================

/**
 * Application key for encryption and HMAC signatures.
 * Generate one at: https://yourdomain.com/install.php
 * 
 * To generate manually, run this in terminal:
 *   php -r "echo bin2hex(random_bytes(32));"
 * 
 * Or use this quick generator:
 *   echo md5(uniqid(mt_rand(), true));
 */
define('CANYON_APP_KEY', 'CHANGE_ME_TO_A_RANDOM_STRING');

/**
 * Allowed hostnames (comma-separated).
 * Your domain MUST be in this list or the site won't load.
 * 
 * Examples:
 *   define('CANYON_ALLOWED_HOSTS', 'example.com,www.example.com');
 *   define('CANYON_ALLOWED_HOSTS', '*'); // Allow all (not recommended for production)
 */
define('CANYON_ALLOWED_HOSTS', 'localhost,127.0.0.1');

// ============================================================================
// ENVIRONMENT & DEBUG
// ============================================================================

/**
 * Environment: 'production' or 'development'
 * 
 * production: Optimized, no error display, faster
 * development: Shows errors, debug toolbar, slower
 */
define('CANYON_ENV', 'development');

/**
 * Enable error display (only in development).
 */
define('CANYON_DEBUG', true);

/**
 * Log errors to file (always recommended).
 */
define('CANYON_LOG_ERRORS', true);

// ============================================================================
// PERFORMANCE SETTINGS
// ============================================================================

/**
 * Enable caching (recommended for production).
 */
define('CANYON_CACHE_ENABLED', false);

/**
 * Cache lifetime in seconds.
 */
define('CANYON_CACHE_TTL', 3600);

/**
 * Enable asset minification (CSS/JS).
 */
define('CANYON_ASSET_MINIFY', false);

/**
 * Enable image optimization on upload.
 */
define('CANYON_IMAGE_OPTIMIZE', false);

/**
 * Enable database cleanup (runs occasionally).
 */
define('CANYON_DB_CLEANUP', true);

// ============================================================================
// MAIL SETTINGS
// ============================================================================

/**
 * Mail method: 'mail', 'smtp', or 'sendmail'
 * 
 * 'mail' = PHP mail() function (works on most shared hosts)
 * 'smtp' = SMTP server (requires SMTP settings below)
 */
define('CANYON_MAIL_METHOD', 'mail');

/**
 * SMTP settings (only needed if CANYON_MAIL_METHOD is 'smtp')
 */
define('CANYON_SMTP_HOST', '');
define('CANYON_SMTP_PORT', 587);
define('CANYON_SMTP_USER', '');
define('CANYON_SMTP_PASS', '');
define('CANYON_SMTP_ENCRYPTION', 'tls');

/**
 * Default "from" address for outgoing emails.
 */
define('CANYON_MAIL_FROM', 'noreply@example.com');
define('CANYON_MAIL_FROM_NAME', 'Canyon App');

// ============================================================================
// ADVANCED SETTINGS (usually no need to change)
// ============================================================================

/**
 * Session lifetime in seconds (default: 24 minutes).
 */
define('CANYON_SESSION_LIFETIME', 1440);

/**
 * Maximum file upload size in MB.
 * Note: Your hosting may have its own limit (check php.ini).
 */
define('CANYON_UPLOAD_MAX_SIZE', 10);

/**
 * Allowed file extensions for upload (comma-separated).
 */
define('CANYON_UPLOAD_ALLOWED', 'jpg,jpeg,png,gif,pdf,doc,docx,zip');

/**
 * Enable multi-site support.
 */
define('CANYON_MULTISITE', false);

/**
 * Enable REST API.
 */
define('CANYON_API_ENABLED', false);

/**
 * API rate limit (requests per minute).
 */
define('CANYON_API_RATE_LIMIT', 60);

// ============================================================================
// PATH SETTINGS (auto-detected, usually no need to change)
// ============================================================================

/**
 * Absolute path to your Canyon installation.
 * Leave as '__DIR__' unless you moved config.php.
 */
define('CANYON_BASE_PATH', __DIR__);

/**
 * Path to the 'app' directory.
 */
define('CANYON_APP_PATH', CANYON_BASE_PATH . '/app');

/**
 * Path to the 'storage' directory (outside web root if possible).
 */
define('CANYON_STORAGE_PATH', CANYON_BASE_PATH . '/storage');

/**
 * Path to the 'resources' directory.
 */
define('CANYON_RESOURCES_PATH', CANYON_BASE_PATH . '/resources');

/**
 * Path to the 'routes' directory.
 */
define('CANYON_ROUTES_PATH', CANYON_BASE_PATH . '/routes');

/**
 * Path to the 'plugins' directory.
 */
define('CANYON_PLUGINS_PATH', CANYON_BASE_PATH . '/plugins');

// ============================================================================
// DO NOT EDIT BELOW THIS LINE
// ============================================================================

/**
 * Shared Hosting Auto-Detection
 * Automatically adjusts settings based on hosting environment.
 */

// Detect if we're on shared hosting
$canyonSharedHosting = (
    strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'Apache') !== false &&
    file_exists(__DIR__ . '/.cpanel') ||
    file_exists(__DIR__ . '/.plesk') ||
    strpos($_SERVER['DOCUMENT_ROOT'] ?? '', 'public_html') !== false ||
    strpos($_SERVER['DOCUMENT_ROOT'] !== false, 'htdocs') !== false ||
    strpos($_SERVER['DOCUMENT_ROOT'] ?? '', 'www') !== false
);

// Shared hosting compatibility mode
if ($canyonSharedHosting || CANYON_ENV === 'production') {
    // Disable features that may cause issues on shared hosts
    if (!defined('CANYON_DISABLE_HTTPS_REDIRECT')) {
        define('CANYON_DISABLE_HTTPS_REDIRECT', true);
    }
    
    // Reduce memory usage
    if (!defined('CANYON_LOW_MEMORY_MODE')) {
        define('CANYON_LOW_MEMORY_MODE', true);
    }
    
    // Use simpler caching
    if (!defined('CANYON_SIMPLE_CACHE')) {
        define('CANYON_SIMPLE_CACHE', true);
    }
}

// Set timezone
if (defined('CANYON_TIMEZONE')) {
    date_default_timezone_set(CANYON_TIMEZONE);
}

// Set error reporting
if (CANYON_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Set error logging
if (CANYON_LOG_ERRORS) {
    ini_set('log_errors', '1');
    ini_set('error_log', CANYON_STORAGE_PATH . '/logs/php_errors.log');
}
