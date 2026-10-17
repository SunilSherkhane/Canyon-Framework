<?php

namespace Canyon\Core;

/**
 * Canyon Framework Router
 * Inspired by Laravel Router and CodeIgniter Router
 *
 * Security features:
 * - Strict HTTP method allow-list
 * - URI sanitization (control chars / null bytes / length limit)
 * - Route pattern matching with {param} and (.*) catch-all support
 * - Handler validation (class + method must exist)
 * - Content-Type headers on every error response
 * - No information disclosure in error output
 */
class Router
{
    protected static $routes = [];
    protected static $groupMiddwares = [];

    /**
     * Allowed HTTP methods
     */
    protected const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    /**
     * Maximum allowed URI length (prevents DoS)
     */
    protected const MAX_URI_LENGTH = 2048;

    /**
     * Register a GET route
     */
    public static function get($uri, $handler, $name = null)
    {
        self::add('GET', $uri, $handler, $name);
    }

    /**
     * Register a POST route
     */
    public static function post($uri, $handler, $name = null)
    {
        self::add('POST', $uri, $handler, $name);
    }

    /**
     * Register a PUT route
     */
    public static function put($uri, $handler, $name = null)
    {
        self::add('PUT', $uri, $handler, $name);
    }

    /**
     * Register a DELETE route
     */
    public static function delete($uri, $handler, $name = null)
    {
        self::add('DELETE', $uri, $handler, $name);
    }

    /**
     * Register a PATCH route
     */
    public static function patch($uri, $handler, $name = null)
    {
        self::add('PATCH', $uri, $handler, $name);
    }

    /**
     * Register a route that responds to every method
     */
    public static function any($uri, $handler, $name = null)
    {
        foreach (self::ALLOWED_METHODS as $method) {
            self::add($method, $uri, $handler, $name);
        }
    }

    /**
     * Add a route with validation
     */
    public static function add($method, $uri, $handler, $name = null)
    {
        // Validate HTTP method
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        // Validate URI
        $uri = self::validateUri($uri);

        // Validate handler
        if (!is_callable($handler) && !is_array($handler) && !is_string($handler)) {
            throw new \InvalidArgumentException("Invalid route handler");
        }

        if (is_array($handler)) {
            if (count($handler) !== 2 || !is_string($handler[0]) || !is_string($handler[1])) {
                throw new \InvalidArgumentException("Invalid controller route handler");
            }
        }

        self::$routes[] = [
            'method' => $method,
            'uri' => $uri,
            'handler' => $handler,
            'name' => $name
        ];
    }

    /**
     * Validate and sanitize a route URI definition
     */
    protected static function validateUri($uri): string
    {
        if (!is_string($uri) || $uri === '') {
            throw new \InvalidArgumentException("URI must be a non-empty string");
        }

        // Must start with /
        if (!str_starts_with($uri, '/')) {
            throw new \InvalidArgumentException("URI must start with /");
        }

        // Reject control characters and null bytes
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            throw new \InvalidArgumentException("URI contains invalid characters");
        }

        // Remove query string if present
        $uri = parse_url($uri, PHP_URL_PATH) ?: $uri;

        // Normalize multiple slashes
        $uri = preg_replace('#/+#', '/', $uri);

        return $uri;
    }

    /**
     * Group routes with middleware
     */
    public static function group($middleware, $callback)
    {
        $previous = self::$groupMiddwares;
        self::$groupMiddwares = array_merge($previous, (array) $middleware);

        if (is_callable($callback)) {
            $callback();
        }

        self::$groupMiddwares = $previous;
    }

    /**
     * Get the current request URI, sanitized
     */
    protected static function getCurrentUri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if ($uri === false || $uri === null || $uri === '') {
            $uri = '/';
        }

        // Decode percent-encoding so encoded attacks are visible to the checks below
        $uri = rawurldecode($uri);

        // Reject control characters and null bytes
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            self::sendErrorResponse(400, 'Bad Request');
        }

        // Limit URI length to prevent DoS
        if (strlen($uri) > self::MAX_URI_LENGTH) {
            self::sendErrorResponse(414, 'URI Too Long');
        }

        return $uri;
    }

    /**
     * Match a route against the request method and URI.
     * Returns ['route' => ..., 'params' => [...]] or null.
     */
    protected static function matchRoute(array $route, string $method, string $uri): ?array
    {
        if ($route['method'] !== $method) {
            return null;
        }

        $pattern = $route['uri'];

        // Catch-all route
        if ($pattern === '/(.*)') {
            return ['route' => $route, 'params' => []];
        }

        // Parameterized route: /user/{id}
        if (str_contains($pattern, '{')) {
            $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $uri, $matches) === 1) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return ['route' => $route, 'params' => $params];
            }

            return null;
        }

        // Exact match
        return $pattern === $uri ? ['route' => $route, 'params' => []] : null;
    }

    /**
     * Dispatch the current request
     */
    public static function dispatch()
    {
        try {
            // Validate request method
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (!in_array($method, self::ALLOWED_METHODS, true)) {
                self::sendErrorResponse(405, 'Method Not Allowed');
                return;
            }

            $uri = self::getCurrentUri();

            // Try specific routes first
            foreach (self::$routes as $route) {
                if ($route['uri'] === '/(.*)') {
                    continue; // Catch-all is the last resort
                }

                $match = self::matchRoute($route, $method, $uri);
                if ($match !== null) {
                    return self::handleRoute($match['route'], $match['params']);
                }
            }

            // Try catch-all route
            foreach (self::$routes as $route) {
                if ($route['uri'] === '/(.*)' && $route['method'] === $method) {
                    return self::handleRoute($route, []);
                }
            }

            // 404 - no route found
            self::sendErrorResponse(404, 'Not Found');

        } catch (\Throwable $e) {
            // Log the error
            Security::logEvent('router_error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            // Don't expose internal error details to users
            self::sendErrorResponse(500, 'Internal Server Error');
        }
    }

    /**
     * Handle route execution
     */
    protected static function handleRoute($route, array $params = [])
    {
        try {
            $handler = $route['handler'];

            if (is_array($handler)) {
                [$class, $method] = $handler;

                if (!class_exists($class)) {
                    throw new \RuntimeException("Controller not found");
                }

                if (!method_exists($class, $method)) {
                    throw new \RuntimeException("Controller method not found");
                }

                $controller = new $class();
                return $controller->$method(...array_values($params));
            }

            if (is_callable($handler)) {
                return call_user_func_array($handler, array_values($params));
            }

            if (is_string($handler) && class_exists($handler)) {
                $controller = new $handler();

                if (!method_exists($controller, 'index')) {
                    throw new \RuntimeException("Controller method not found");
                }

                return $controller->index(...array_values($params));
            }

            throw new \RuntimeException("Invalid route handler");

        } catch (\Throwable $e) {
            // Log the error
            Security::logEvent('route_handler_error', [
                'route' => $route['uri'] ?? 'unknown',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            // Don't expose internal error details to users
            self::sendErrorResponse(500, 'Internal Server Error');
        }
    }

    /**
     * Send sanitized error response
     */
    protected static function sendErrorResponse(int $code, string $message = 'Error'): void
    {
        http_response_code($code);

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
        }

        // Sanitized error message (no stack traces)
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>{$code} Error</title></head><body><h1>{$code} - {$safeMessage}</h1></body></html>";
        exit;
    }

    /**
     * Get all registered routes
     */
    public static function getRoutes()
    {
        return self::$routes;
    }

    /**
     * Get route by name
     */
    public static function getRouteByName(string $name): ?array
    {
        foreach (self::$routes as $route) {
            if ($route['name'] === $name) {
                return $route;
            }
        }
        return null;
    }
}