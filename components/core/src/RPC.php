<?php

namespace Canyon\Core;

/**
 * Canyon Framework - RPC Service
 *
 * JSON-RPC 2.0 web service for external integrations and mobile clients.
 * Elgg's RPC web services.
 */
class RPC
{
    /** @var array Registered methods */
    protected static array $methods = [];

    /** @var array Service middleware */
    protected static array $middleware = [];

    /** @var array Request log */
    protected static array $log = [];

    /**
     * Register an RPC method.
     *
     * @param string $method Method name
     * @param callable $callback Handler function
     * @param array $options Method options (auth required, etc.)
     */
    public static function register(string $method, callable $callback, array $options = []): void
    {
        self::$methods[$method] = [
            'callback' => $callback,
            'options' => array_merge([
                'auth_required' => true,
                'access' => 'public',
            ], $options),
        ];
    }

    /**
     * Unregister an RPC method.
     */
    public static function unregister(string $method): void
    {
        unset(self::$methods[$method]);
    }

    /**
     * Handle an incoming RPC request.
     *
     * @param string $request JSON-RPC request body
     * @return string JSON-RPC response
     */
    public static function handle(string $request): string
    {
        $data = json_decode($request, true);

        if (!$data || !isset($data['method'])) {
            return self::error(-32600, 'Invalid Request');
        }

        $method = $data['method'];
        $params = $data['params'] ?? [];
        $id = $data['id'] ?? null;

        // Log request
        self::$log[] = [
            'method' => $method,
            'params' => $params,
            'time' => time(),
        ];

        // Check method exists
        if (!isset(self::$methods[$method])) {
            return self::error(-32601, 'Method not found', $id);
        }

        $handler = self::$methods[$method];

        // Check authentication
        if ($handler['options']['auth_required'] && !self::authenticate()) {
            return self::error(-32602, 'Authentication required', $id);
        }

        // Check access
        if (!self::checkAccess($handler['options']['access'])) {
            return self::error(-32603, 'Access denied', $id);
        }

        // Execute method
        try {
            $result = call_user_func($handler['callback'], $params);
            return self::success($result, $id);
        } catch (\Throwable $e) {
            return self::error(-32603, $e->getMessage(), $id);
        }
    }

    /**
     * Authenticate the request.
     */
    protected static function authenticate(): bool
    {
        // Check API key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (empty($apiKey)) {
            return false;
        }

        // Validate API key
        $validKeys = explode(',', env('RPC_API_KEYS', ''));
        return in_array($apiKey, $validKeys);
    }

    /**
     * Check access level.
     */
    protected static function checkAccess(string $access): bool
    {
        if ($access === 'public') {
            return true;
        }
        if ($access === 'logged_in') {
            return isset($_SESSION['user_id']);
        }
        if ($access === 'admin') {
            return isset($_SESSION['user_id']) && AccessControl::isAdmin($_SESSION['user_id']);
        }
        return false;
    }

    /**
     * Create a success response.
     */
    protected static function success(mixed $result, mixed $id): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ]);
    }

    /**
     * Create an error response.
     */
    protected static function error(int $code, string $message, mixed $id = null): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ]);
    }

    /**
     * Get all registered methods.
     */
    public static function getMethods(): array
    {
        return array_keys(self::$methods);
    }

    /**
     * Get method info.
     */
    public static function getMethodInfo(string $method): ?array
    {
        return self::$methods[$method] ?? null;
    }

    /**
     * Add middleware.
     */
    public static function middleware(callable $callback): void
    {
        self::$middleware[] = $callback;
    }

    /**
     * Get request log.
     */
    public static function getLog(): array
    {
        return self::$log;
    }

    /**
     * Clear request log.
     */
    public static function clearLog(): void
    {
        self::$log = [];
    }
}