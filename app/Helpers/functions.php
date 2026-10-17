<?php

/**
 * Canyon Framework - Helper Functions
 */

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = $_ENV[$key] ?? $_ENV[str_replace(' ', '_', strtoupper($key))] ?? $default;

        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;

        return $value;
    }
}

if (!function_exists('asset')) {
    function asset($path = '')
    {
        // CANYON_BASE_URL is defined in public/index.php; fall back to base_url()
        $base = defined('CANYON_BASE_URL') ? CANYON_BASE_URL : base_url();

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('route_path')) {
    function route_path($name, $parameters = [])
    {
        $route = \Canyon\Core\Router::getRouteByName($name);
        $path = $route['uri'] ?? null;

        if ($path === null) {
            // Fallback to the default public paths
            $routes = [
                'home' => '/',
                'about' => '/about',
                'contact' => '/contact',
            ];
            $path = $routes[$name] ?? '/' . ltrim($name, '/');
        }

        foreach ($parameters as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        return asset($path);
    }
}

if (!function_exists('response_time')) {
    function response_time()
    {
        return number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 6);
    }
}