<?php

/**
 * Canyon Framework — Global Helper Functions
 */

if (!function_exists('asset')) {
    /**
     * Generate a URL for a static asset.
     */
    function asset(string $path = ''): string
    {
        $base = defined('CANYON_BASE_URL') ? CANYON_BASE_URL : base_url();
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('route_path')) {
    /**
     * Generate a URL for a named route, substituting any parameters.
     */
    function route_path(string $name, array $parameters = []): string
    {
        $route = \Canyon\Core\Router::getRouteByName($name);
        $path = $route['uri'] ?? null;

        if ($path === null) {
            // Fallback to sensible defaults
            $defaults = [
                'home'    => '/',
                'about'   => '/about',
                'contact' => '/contact',
            ];
            $path = $defaults[$name] ?? '/' . ltrim($name, '/');
        }

        foreach ($parameters as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        return asset($path);
    }
}

if (!function_exists('response_time')) {
    /**
     * Get the request processing time in seconds.
     */
    function response_time(): string
    {
        return number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 6);
    }
}
