<?php

namespace Canyon\Core;

use Closure;

/**
 * Canyon Framework Router
 *
 * Resolves the incoming HTTP request to a registered route handler.
 * Laravel and CodeIgniter routers.
 *
 * Security: strict method allow-list, URI sanitization, handler validation,
 * and sanitized error responses with no information disclosure.
 */
class Router
{
  /** @var array<int, array{method: string, uri: string, handler: mixed, name: ?string, middleware: array<int, string>}> */
  protected static array $routes = [];

  /** @var array<int, string> */
  protected static array $groupMiddleware = [];

  /** @var int Current depth in route group nesting */
  protected static int $groupDepth = 0;

  /** HTTP methods the router will accept */
  protected const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

  /** Maximum URI length to prevent DoS via overly long paths */
  protected const MAX_URI_LENGTH = 2048;

  // -----------------------------------------------------------------------
  // Route registration
  // -----------------------------------------------------------------------

  public static function get(string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    self::add('GET', $uri, $handler, $name, $middleware);
  }

  public static function post(string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    self::add('POST', $uri, $handler, $name, $middleware);
  }

  public static function put(string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    self::add('PUT', $uri, $handler, $name, $middleware);
  }

  public static function delete(string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    self::add('DELETE', $uri, $handler, $name, $middleware);
  }

  public static function patch(string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    self::add('PATCH', $uri, $handler, $name, $middleware);
  }

  public static function options(string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    self::add('OPTIONS', $uri, $handler, $name, $middleware);
  }

  /**
   * Register a route that responds to every allowed HTTP method.
   */
  public static function any(string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    foreach (self::ALLOWED_METHODS as $method) {
      self::add($method, $uri, $handler, $name, $middleware);
    }
  }

  /**
   * Add a route after validating the method, URI, and handler.
   * Middleware is merged with group middleware if specified.
   */
  public static function add(string $method, string $uri, mixed $handler, ?string $name = null, array $middleware = []): void
  {
    if (!in_array($method, self::ALLOWED_METHODS, true)) {
      throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
    }

    $uri = self::validateUri($uri);

    self::assertValidHandler($handler);

    // Merge with any group middleware
    $allMiddleware = array_merge(self::$groupMiddleware, $middleware);

    self::$routes[] = [
      'method' => $method,
      'uri'   => $uri,
      'handler' => $handler,
      'name'  => $name,
      'middleware' => $allMiddleware,
    ];
  }

  /**
   * Group routes under a shared set of middleware.
   * Middleware is merged into each route's middleware stack.
   */
  public static function group(array|string $middleware, callable $callback): void
  {
    // Store current group middleware and depth
    $previousMiddleware = self::$groupMiddleware;
    $previousDepth = self::$groupDepth;

    // Merge new middleware
    self::$groupMiddleware = array_merge(self::$groupMiddleware, (array) $middleware);
    self::$groupDepth++;

    $callback();

    // Restore previous state
    self::$groupMiddleware = $previousMiddleware;
    self::$groupDepth = $previousDepth;
  }

  // -----------------------------------------------------------------------
  // Dispatch
  // -----------------------------------------------------------------------

  /**
   * Match the current request against registered routes and execute the handler.
   */
  public static function dispatch(): ?string
  {
    try {
      $method = self::resolveMethod();

      if (!in_array($method, self::ALLOWED_METHODS, true)) {
        self::sendErrorResponse(405, 'Method Not Allowed');
        return null;
      }

      $uri = self::getCurrentUri();

      // Try specific routes first; catch-all is the last resort
      foreach (self::$routes as $route) {
        if ($route['uri'] === '/(.*)') {
          continue;
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

      self::sendErrorResponse(404, 'Not Found');
      return null;

    } catch (\Throwable $exception) {
      @file_put_contents('/tmp/router_error.log', 'DISPATCH ERROR: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine() . chr(10), FILE_APPEND);
      Security::logEvent('router_error', [
        'message' => $exception->getMessage(),
        'file'  => $exception->getFile(),
        'line'  => $exception->getLine(),
      ], 'error');

      self::sendErrorResponse(500, 'Internal Server Error');
      return null;
    }
  }

  // -----------------------------------------------------------------------
  // Route matching
  // -----------------------------------------------------------------------

  /**
   * Match a single route against the request method and URI.
   *
   * @return array{route: array, params: array<string, string>}|null
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

  // -----------------------------------------------------------------------
  // Request method resolution
  // -----------------------------------------------------------------------

  /**
   * Resolve the HTTP method, supporting method spoofing via _method field.
   * Forms can use <input type="hidden" name="_method" value="PUT"> to
   * spoof PUT/PATCH/DELETE requests.
   */
  protected static function resolveMethod(): string
  {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Only POST requests can spoof method
    if ($method === 'POST') {
      $spoofed = $_POST['_method'] ?? null;
      if (is_string($spoofed)) {
        $spoofed = strtoupper($spoofed);
        if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
          return $spoofed;
        }
      }
    }

    return $method;
  }

  // -----------------------------------------------------------------------
  // Request URI
  // -----------------------------------------------------------------------

  /**
   * Get the current request URI, sanitized and length-checked.
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

  // -----------------------------------------------------------------------
  // Handler execution
  // -----------------------------------------------------------------------

  /**
   * Execute the matched route's handler.
   */
  protected static function handleRoute(array $route, array $params = []): ?string
  {
    try {
      $handler = $route['handler'];

      // Controller array syntax: [ControllerClass::class, 'method']
      if (is_array($handler)) {
        [$controllerClass, $methodName] = $handler;

        if (!class_exists($controllerClass)) {
          throw new \RuntimeException('Controller not found');
        }

        if (!method_exists($controllerClass, $methodName)) {
          throw new \RuntimeException('Controller method not found');
        }

        $controller = new $controllerClass();
        
        // Execute middleware pipeline before the handler
        $middlewareStack = $route['middleware'] ?? [];
        $response = self::executeMiddlewarePipeline($middlewareStack, $params, function() use ($controller, $methodName, $params) {
          return $controller->$methodName(...array_values($params));
        });

        return $response;
      }

      // Closure handler
      if (is_callable($handler)) {
        $middlewareStack = $route['middleware'] ?? [];
        $response = self::executeMiddlewarePipeline($middlewareStack, $params, function() use ($handler, $params) {
          return call_user_func_array($handler, array_values($params));
        });
        return $response;
      }

      // String controller class - defaults to index()
      if (is_string($handler) && class_exists($handler)) {
        $controller = new $handler();

        if (!method_exists($controller, 'index')) {
          throw new \RuntimeException('Controller method not found');
        }

        $middlewareStack = $route['middleware'] ?? [];
        $response = self::executeMiddlewarePipeline($middlewareStack, $params, function() use ($controller, $params) {
          return $controller->index(...array_values($params));
        });

        return $response;
      }

      throw new \RuntimeException('Invalid route handler');

    } catch (\Throwable $exception) {
      file_put_contents('/tmp/router_error.log', 'ROUTER HANDLER ERROR: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine() . chr(10), FILE_APPEND);

      self::sendErrorResponse(500, 'Internal Server Error');
      return null;
    }
  }

  /**
   * Execute middleware pipeline using the pipeline pattern.
   * Each middleware can either pass control to the next handler or terminate early.
   */
  protected static function executeMiddlewarePipeline(array $middleware, array $params, Closure $finalHandler): ?string
  {
    // Build the pipeline - inner to outer
    $pipeline = $finalHandler;

    // Reverse middleware to build correct pipeline order
    foreach (array_reverse($middleware) as $middlewareClass) {
      $pipeline = function() use ($middlewareClass, $pipeline, $params) {
        $middleware = new $middlewareClass();
        return $middleware->handle(null, $pipeline);
      };
    }

    return $pipeline();
  }

  // -----------------------------------------------------------------------
  // Validation helpers
  // -----------------------------------------------------------------------

  /**
   * Validate and sanitize a route URI definition.
   */
  protected static function validateUri(string $uri): string
  {
    if ($uri === '') {
      throw new \InvalidArgumentException('URI must be a non-empty string');
    }

    if (!str_starts_with($uri, '/')) {
      throw new \InvalidArgumentException('URI must start with /');
    }

    // Reject control characters and null bytes
    if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
      throw new \InvalidArgumentException('URI contains invalid characters');
    }

    // Strip query string if present
    $uri = parse_url($uri, PHP_URL_PATH) ?: $uri;

    // Normalize multiple slashes
    return preg_replace('#/+#', '/', $uri);
  }

  /**
   * Assert that a route handler is a supported type.
   */
  protected static function assertValidHandler(mixed $handler): void
  {
    if (is_callable($handler) || is_string($handler)) {
      return;
    }

    if (is_array($handler)) {
      if (count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
        return;
      }
      throw new \InvalidArgumentException('Invalid controller route handler');
    }

    throw new \InvalidArgumentException('Invalid route handler');
  }

  // -----------------------------------------------------------------------
  // Error response
  // -----------------------------------------------------------------------

  /**
   * Send a sanitized error response and stop execution.
   */
  protected static function sendErrorResponse(int $statusCode, string $message = 'Error'): void
  {
    http_response_code($statusCode);

    if (!headers_sent()) {
      header('Content-Type: text/html; charset=UTF-8');
      header('X-Content-Type-Options: nosniff');
      header('X-Frame-Options: DENY');
    }

    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>{$statusCode} Error</title></head><body><h1>{$statusCode} - {$safeMessage}</h1></body></html>";
    exit;
  }

  // -----------------------------------------------------------------------
  // Introspection
  // -----------------------------------------------------------------------

  /**
   * Get all registered routes.
   */
  public static function getRoutes(): array
  {
    return self::$routes;
  }

  /**
   * Find a route by its name.
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