<?php

/**
 * Canyon Framework - Global Helper Functions
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
    'home' => '/',
    'about' => '/about',
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

if (!function_exists('view')) {
 /**
  * Render a view and return the output as a string.
  */
 function view(string $view, array $data = []): string
 {
  return \Canyon\Core\View::make($view, $data);
 }
}

if (!function_exists('e')) {
 /**
  * Escape a value for safe HTML output.
  */
 function e(mixed $value): string
 {
  return \Canyon\Core\Security::escape($value);
 }
}

if (!function_exists('flash')) {
 /**
  * Get a flash session value (and remove it).
  */
 function flash(string $key, mixed $default = null): mixed
 {
  if (session_status() !== PHP_SESSION_ACTIVE) {
   session_start();
  }
  $value = $_SESSION['_flash_' . $key] ?? $default;
  unset($_SESSION['_flash_' . $key]);
  return $value;
 }
}

if (!function_exists('abort')) {
 /**
  * Send an HTTP error response and stop execution.
  */
 function abort(int $status, string $message = ''): never
 {
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  echo json_encode(['error' => $message ?: 'Error'], JSON_UNESCAPED_UNICODE);
  exit;
 }
}

if (!function_exists('auth')) {
 /**
  * Get the currently authenticated user from the database.
  *
  * Returns a User model object when a valid session exists,
  * or a Guest object (id=0, roles=['guest']) when not authenticated.
  */
 function auth(): object
  {
   $userId = $_SESSION['canyon_user_id'] ?? 0;

   if ($userId > 0) {
    try {
     $user = \Canyon\Models\User::findById((int) $userId);
     if ($user) {
      return (object) [
       'id'    => (int) $user['id'],
       'name'  => $user['name'],
       'email' => $user['email'],
       'roles' => $user['roles'] ?? ['user'],
      ];
     }
    } catch (\Throwable $e) {
     // Database unavailable — fall through to guest
    }
   }

   // Unauthenticated guest — never return a fake admin.
   return (object) [
    'id'    => 0,
    'name'  => 'Guest',
    'email' => '',
    'roles' => ['guest'],
   ];
  }
}
