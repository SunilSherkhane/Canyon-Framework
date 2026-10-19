<?php

/**
 * Canyon Framework - Environment Helper Functions
 *
 * Provides secure environment variable access with type coercion,
 * default value handling, and URL/path helpers.
 */

if (!function_exists('env')) {
    /**
     * Get an environment variable with automatic type coercion.
     *
     * Checks $_ENV first, then $_SERVER, then getenv().
     * Coerces 'true', 'false', 'null', and numeric strings to native types.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? null;

        if ($value === null) {
            $value = $_SERVER[$key] ?? null;
        }

        if ($value === null) {
            $value = getenv($key);
        }

        if ($value === false || $value === null) {
            return $default;
        }

        if (is_string($value)) {
            $lower = strtolower($value);

            if ($lower === 'true')  return true;
            if ($lower === 'false') return false;
            if ($lower === 'null')  return null;

            if (ctype_digit($value)) return (int) $value;
            if (is_numeric($value))  return (float) $value;
        }

        return $value;
    }
}

if (!function_exists('base_url')) {
    /**
     * Get the base URL of the application.
     */
    function base_url(string $path = ''): string
    {
        if (!defined('CANYON_BASE_URL')) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            define('CANYON_BASE_URL', "{$protocol}://{$host}");
        }

        if ($path !== '') {
            return rtrim(CANYON_BASE_URL, '/') . '/' . ltrim($path, '/');
        }

        return CANYON_BASE_URL;
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the absolute path to the storage directory.
     */
    function storage_path(string $path = ''): string
    {
        if (!defined('CANYON_STORAGE_PATH')) {
            define('CANYON_STORAGE_PATH', dirname(__DIR__) . '/storage');
        }

        if ($path !== '') {
            return CANYON_STORAGE_PATH . '/' . $path;
        }

        return CANYON_STORAGE_PATH;
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the absolute path to the public directory.
     */
    function public_path(string $path = ''): string
    {
        $publicPath = dirname(__DIR__) . '/public';

        if ($path !== '') {
            return $publicPath . '/' . $path;
        }

        return $publicPath;
    }
}
