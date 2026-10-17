<?php

/**
 * Canyon Framework - Environment Helper Functions
 * 
 * Security features:
 * - Secure environment variable access
 * - Type coercion with validation
 * - Default value handling
 */

// Only define if not already defined
if (!function_exists('env')) {
    /**
     * Get environment variable with type coercion
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env($key, $default = null)
    {
        // Check various sources
        $value = null;
        
        // Try $_ENV first
        if (isset($_ENV[$key])) {
            $value = $_ENV[$key];
        }
        
        // Try $_SERVER as fallback
        if ($value === null && isset($_SERVER[$key])) {
            $value = $_SERVER[$key];
        }
        
        // Try getenv() as last resort
        if ($value === null) {
            $value = getenv($key);
        }
        
        // Return default if not found
        if ($value === false || $value === null) {
            return $default;
        }
        
        // Type coercion
        if (is_string($value)) {
            // Boolean coercion
            if (strtolower($value) === 'true') {
                return true;
            }
            if (strtolower($value) === 'false') {
                return false;
            }
            
            // Null coercion
            if (strtolower($value) === 'null') {
                return null;
            }
            
            // Numeric coercion
            if (ctype_digit($value)) {
                return (int) $value;
            }
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        
        return $value;
    }
}

if (!function_exists('base_url')) {
    /**
     * Get base URL
     */
    function base_url($path = '')
    {
        if (!defined('CANYON_BASE_URL')) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            define('CANYON_BASE_URL', "{$protocol}://{$host}");
        }
        
        $baseUrl = CANYON_BASE_URL;
        
        if (!empty($path)) {
            // Remove leading slashes
            $path = '/' . ltrim($path, '/');
            // Ensure trailing slash is handled
            return rtrim($baseUrl, '/') . $path;
        }
        
        return $baseUrl;
    }
}

if (!function_exists('asset')) {
    /**
     * Generate asset URL
     */
    function asset($path = '')
    {
        // Check for APP_URL first
        $appUrl = env('APP_URL');
        if ($appUrl) {
            return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
        }
        
        return base_url($path);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get storage path
     */
    function storage_path($path = '')
    {
        if (!defined('CANYON_STORAGE_PATH')) {
            define('CANYON_STORAGE_PATH', dirname(__DIR__) . '/storage');
        }
        
        $storagePath = CANYON_STORAGE_PATH;
        
        if (!empty($path)) {
            return $storagePath . '/' . $path;
        }
        
        return $storagePath;
    }
}

if (!function_exists('public_path')) {
    /**
     * Get public path
     */
    function public_path($path = '')
    {
        $publicPath = dirname(__DIR__) . '/public';
        
        if (!empty($path)) {
            return $publicPath . '/' . $path;
        }
        
        return $publicPath;
    }
}