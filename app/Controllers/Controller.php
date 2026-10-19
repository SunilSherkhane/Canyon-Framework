<?php

namespace Canyon\Controllers;

use Canyon\Core\View;
use Canyon\Core\Security;
use Canyon\Core\Database;
use Canyon\Core\Application;

/**
 * Canyon Framework Base Controller
 *
 * Provides shared response helpers: view rendering, JSON responses,
 * safe redirects, CSRF protection, and common error responses.
 */
class Controller
{
 /** @var Database|null Database connection for derived controllers */
 protected ?Database $db = null;

 /**
  * Get the database connection instance.
  */
 protected function db(): Database
 {
  if ($this->db === null) {
   $this->db = new Database();
  }
  return $this->db;
 }

 /**
  * Get a view rendered with global data.
  */
 protected function view(string $view, array $data = []): string
 {
  // Merge with application-wide data
  $appData = Application::getSharedData();
  return View::make($view, array_merge($appData, $data));
 }

 // -----------------------------------------------------------------------
 // JSON responses
 // -----------------------------------------------------------------------

 /**
  * Send a JSON response with security headers.
  */
 protected function json(mixed $data, int $status = 200): never
 {
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');

  // JSON_HEX_* flags prevent XSS in JSON responses
  echo json_encode(
   $data,
   JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
  );
  exit;
 }

 /**
  * Send a success JSON response with data wrapper.
  */
 protected function success(mixed $data = null, string $message = 'Success', int $status = 200): never
 {
  $this->json([
   'success' => true,
   'message' => $message,
   'data' => $data,
  ], $status);
 }

 /**
  * Send an error JSON response.
  */
 protected function error(string $message, int $status = 400): never
 {
  $this->json([
   'success' => false,
   'message' => $message,
  ], $status);
 }

 /**
  * Send a success JSON response (alias for backward compatibility).
  */
 protected function successJson(mixed $data = null, string $message = 'Success'): never
 {
  $this->success($data, $message);
 }

 /**
  * Send an error JSON response (alias for backward compatibility).
  */
 protected function errorJson(string $message, int $status = 400): never
 {
  $this->error($message, $status);
 }

 // -----------------------------------------------------------------------
 // Common error responses
 // -----------------------------------------------------------------------

 protected function badRequest(string $message = 'Bad Request'): never
 {
  $this->error($message, 400);
 }

 protected function unauthorized(string $message = 'Unauthorized'): never
 {
  $this->error($message, 401);
 }

 protected function forbidden(string $message = 'Forbidden'): never
 {
  $this->error($message, 403);
 }

 /**
  * Send a JSON 404 response.
  *
  * No return type is declared (instead of `never`) so that application
  * controllers may override this with an HTML 404 page that returns a
  * rendered string to the router. The body still terminates the request
  * via error().
  */
 protected function notFound(string $message = 'Not Found')
 {
  $this->error($message, 404);
 }

 protected function serverError(string $message = 'Internal Server Error'): never
 {
  $this->error($message, 500);
 }

 // -----------------------------------------------------------------------
 // Redirects
 // -----------------------------------------------------------------------

 /**
  * Redirect to a URL after sanitizing it to prevent open redirects.
  * Only same-site relative URLs starting with '/' are allowed.
  */
 protected function redirect(string $url, int $status = 302): never
 {
  $sanitizedUrl = Security::sanitizeRedirectUrl($url);

  if ($sanitizedUrl === null && $url !== '/') {
   Security::logEvent('invalid_redirect', ['url' => $url], 'warning');
   $sanitizedUrl = '/';
  }

  // At this point, $sanitizedUrl is either:
  // - null (shouldn't happen after fallback)
  // - '/' (root path)
  // - A relative path starting with '/'
  // SanitizeRedirectUrl already rejects protocol-relative URLs (//evil.com)

  http_response_code($status);
  header('Location: ' . $sanitizedUrl);
  exit;
 }

 /**
  * Redirect back to the previous page, falling back to a safe URL.
  */
 protected function redirectBack(string $fallback = '/', int $status = 302): never
 {
  $referer = $_SERVER['HTTP_REFERER'] ?? null;

  if ($referer !== null && $this->isValidRedirectUrl($referer)) {
   $this->redirect($referer, $status);
  }

  $this->redirect($fallback, $status);
 }

 /**
  * Check whether a URL is safe to redirect to (same host or relative).
  */
 protected function isValidRedirectUrl(string $url): bool
 {
  $parsed = parse_url($url);

  // Relative URLs (no host) are always safe
  if (!isset($parsed['host'])) {
   return true;
  }

  $currentHost = $_SERVER['HTTP_HOST'] ?? '';
  return strtolower($parsed['host']) === strtolower($currentHost);
 }

 // -----------------------------------------------------------------------
 // Raw response
 // -----------------------------------------------------------------------

 /**
  * Send a raw response with a custom content type.
  */
 protected function response(string $content, int $status = 200, string $contentType = 'text/html; charset=UTF-8'): never
 {
  http_response_code($status);
  header('Content-Type: ' . $contentType);
  header('X-Content-Type-Options: nosniff');
  echo $content;
  exit;
 }

 // -----------------------------------------------------------------------
 // CSRF helpers
 // -----------------------------------------------------------------------

 /**
  * Get a hidden HTML input field containing the CSRF token.
  */
 protected function csrfField(): string
 {
  return Security::csrfField();
 }

 /**
  * Get the CSRF token field name.
  */
 protected function csrfTokenName(): string
 {
  return Security::getCsrfTokenName();
 }

 /**
  * Verify a CSRF token.
  */
 protected function verifyCsrf(?string $token): bool
 {
  if ($token === null || $token === '') {
   return false;
  }
  return Security::verifyCsrfToken($token);
 }

 // -----------------------------------------------------------------------
 // Input helpers
 // -----------------------------------------------------------------------

 /**
  * Get a sanitized input value with optional validation rules.
  */
 protected function input(string $key, mixed $default = null, string $type = 'string'): mixed
 {
  $data = $type === 'string' ? $_POST[$key] ?? $_GET[$key] ?? $default : $_POST[$key] ?? $default;

  if ($data === null) {
   return $default;
  }

  return Security::sanitizeInput([$key => $data], [$key => $type])[$key] ?? $default;
 }

 /**
  * Get all sanitized POST input with validation rules.
  */
 protected function post(array $rules = []): array
 {
  return Security::sanitizeInput($_POST ?? [], $rules);
 }

 /**
  * Get all sanitized GET input with validation rules.
  */
 protected function get(array $rules = []): array
 {
  return Security::sanitizeInput($_GET ?? [], $rules);
 }

 // -----------------------------------------------------------------------
 // Escaping
 // -----------------------------------------------------------------------

 /**
  * Escape a value for safe HTML output.
  */
 protected function escape(mixed $value): string
 {
  return View::escape($value);
 }

 // -----------------------------------------------------------------------
 // Session helpers
 // -----------------------------------------------------------------------

 /**
  * Get a session value.
  */
 protected function session(string $key, mixed $default = null): mixed
 {
  if (session_status() !== PHP_SESSION_ACTIVE) {
   session_start();
  }
  return $_SESSION[$key] ?? $default;
 }

 /**
  * Set a session value.
  */
 protected function setSession(string $key, mixed $value): void
 {
  if (session_status() !== PHP_SESSION_ACTIVE) {
   session_start();
  }
  $_SESSION[$key] = $value;
 }

 /**
  * Flash a session value that persists for the next request.
  */
 protected function flash(string $key, mixed $value): void
 {
  $this->setSession($key, $value);
  $this->setSession('_flash_' . $key, $value);
 }

 /**
  * Get and remove a flash session value.
  */
 protected function getFlash(string $key): mixed
 {
  $value = $this->session('_flash_' . $key);
  if ($value !== null) {
   if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
   }
   unset($_SESSION['_flash_' . $key]);
  }
  return $value;
 }
}