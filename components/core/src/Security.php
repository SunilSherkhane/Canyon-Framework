<?php

namespace Canyon\Core;

/**
 * Canyon Framework Security Class
 *
 * Central security utility: CSRF protection, rate limiting, host validation,
 * output escaping, input sanitization, security headers, and event logging.
 */
class Security
{
  /** @var array<int, string> */
  protected static array $allowedHosts = [];

  protected static string $csrfTokenName = '_canyon_csrf_token';

  /** Token lifetime in seconds (default: 1 hour) */
  protected static int $csrfTokenLifetime = 3600;

  // -----------------------------------------------------------------------
  // Host validation
  // -----------------------------------------------------------------------

  /**
   * Set the allowed hosts for HTTP_HOST validation.
   */
  public static function setAllowedHosts(array $hosts): void
  {
    self::$allowedHosts = array_map('strtolower', $hosts);
  }

  /**
   * Check whether a host is in the allowed list.
   */
  public static function validateHost(string $host): bool
  {
    if (empty(self::$allowedHosts)) {
      return true; // No restrictions if not configured
    }
    return in_array(strtolower($host), self::$allowedHosts, true);
  }

  // -----------------------------------------------------------------------
  // Redirect sanitization
  // -----------------------------------------------------------------------

  /**
   * Sanitize a URL for redirect - prevents open-redirect vulnerabilities.
   *
   * Only same-site relative URLs are allowed. Protocol-relative URLs
   * (//evil.com), absolute URLs, and URLs carrying a scheme are rejected.
   */
  public static function sanitizeRedirectUrl(string $url): ?string
  {
    if ($url === '') {
      return null;
    }

    // Reject control characters, whitespace, and null bytes
    if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
      return null;
    }

    // Reject protocol-relative URLs such as //evil.com
    if (str_starts_with($url, '//')) {
      return null;
    }

    // Allow same-site relative URLs starting with a single slash
    if (str_starts_with($url, '/')) {
      return $url;
    }

    $parsed = parse_url($url);

    // Reject anything unparseable or that carries a scheme/host
    if ($parsed === false || isset($parsed['scheme']) || isset($parsed['host'])) {
      return null;
    }

    // Allow relative paths (no host, no scheme)
    if (isset($parsed['path'])) {
      return $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
    }

    return null;
  }

  // -----------------------------------------------------------------------
  // CSRF protection
  // -----------------------------------------------------------------------

  /**
   * Generate a CSRF token, rotating it when expired.
   */
  public static function generateCsrfToken(): string
  {
    self::startSession();

    if (!isset($_SESSION['_canyon_csrf_token']) ||
      (time() - ($_SESSION['_canyon_csrf_token_created'] ?? 0) > self::$csrfTokenLifetime)) {
      $_SESSION['_canyon_csrf_token'] = bin2hex(random_bytes(32));
      $_SESSION['_canyon_csrf_token_created'] = time();
    }

    return $_SESSION['_canyon_csrf_token'];
  }

  /**
   * Verify a CSRF token using timing-safe comparison.
   */
  public static function verifyCsrfToken(string $token): bool
  {
    if ($token === '') {
      return false;
    }

    self::startSession();

    if (!isset($_SESSION['_canyon_csrf_token'])) {
      return false;
    }

    // Reject expired tokens
    if ((time() - ($_SESSION['_canyon_csrf_token_created'] ?? 0)) > self::$csrfTokenLifetime) {
      unset($_SESSION['_canyon_csrf_token'], $_SESSION['_canyon_csrf_token_created']);
      return false;
    }

    return hash_equals($_SESSION['_canyon_csrf_token'], $token);
  }

  /**
   * Get a hidden HTML input field containing the CSRF token.
   */
  public static function csrfField(): string
  {
    $token = self::generateCsrfToken();
    return '<input type="hidden" name="' . self::$csrfTokenName . '" value="'
      . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
  }

  public static function getCsrfTokenName(): string
  {
    return self::$csrfTokenName;
  }

  public static function setCsrfTokenLifetime(int $seconds): void
  {
    self::$csrfTokenLifetime = $seconds;
  }

  // -----------------------------------------------------------------------
  // Output escaping
  // -----------------------------------------------------------------------

  /**
   * Escape a string for safe HTML output.
   */
  public static function escape(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  // -----------------------------------------------------------------------
  // Session management
  // -----------------------------------------------------------------------

  /**
   * Start the session with secure configuration applied.
   */
  protected static function startSession(): void
  {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      self::secureSessionConfig();
      session_start();
    }
  }

  /**
   * Apply secure session cookie settings.
   */
  public static function secureSessionConfig(): void
  {
    if (session_status() === PHP_SESSION_ACTIVE) {
      return;
    }

    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    $config = [
      'cookie_httponly'  => true,
      'cookie_secure'   => $secureCookie,
      'cookie_samesite'  => 'Lax',
      'use_strict_mode'  => true,
      'use_cookies'    => true,
      'use_only_cookies'  => true,
    ];

    foreach ($config as $setting => $value) {
      ini_set('session.' . $setting, $value ? '1' : '0');
    }
  }

  /**
   * Regenerate the session ID.
   */
  public static function regenerateSession(bool $destroyOld = false): void
  {
    self::startSession();
    session_regenerate_id($destroyOld);
  }

  /**
   * Destroy the session completely.
   */
  public static function killSession(): void
  {
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_unset();
      session_destroy();
      session_write_close();

      if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
          '/' . ($params['path'] ?? ''),
          ($params['domain'] ?? ''),
          ($params['secure'] ?? false),
          ($params['httponly'] ?? false)
        );
      }
    }
  }

  public static function getSessionId(): ?string
  {
    self::startSession();
    return session_id();
  }

  public static function getSession(string $key, mixed $default = null): mixed
  {
    self::startSession();
    return $_SESSION[$key] ?? $default;
  }

  public static function setSession(string $key, mixed $value): void
  {
    self::startSession();
    $_SESSION[$key] = $value;
  }

  // -----------------------------------------------------------------------
  // Request helpers
  // -----------------------------------------------------------------------

  public static function isAjax(): bool
  {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  }

  // -----------------------------------------------------------------------
  // Input sanitization
  // -----------------------------------------------------------------------

  /**
   * Validate and sanitize input data using strict type coercion.
   *
   * Uses filter_var() instead of the deprecated FILTER_SANITIZE_* filters
   * (removed in PHP 8.1).
   *
   * @param array $data Raw input
   * @param array $rules Per-key type rules: 'int', 'float', 'email', 'url', 'string'
   */
  public static function sanitizeInput(array $data, array $rules = []): array
  {
    $sanitized = [];

    foreach ($data as $key => $value) {
      if (is_string($value)) {
        $value = trim($value);

        if (isset($rules[$key])) {
          $value = match ($rules[$key]) {
            'int'  => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : 0,
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT) !== false ? (float) $value : 0.0,
            'email' => self::sanitizeEmail($value),
            'url'  => self::sanitizeUrl($value),
            'string' => self::escape($value),
            default => $value,
          };
        }
      } elseif (is_array($value)) {
        $value = self::sanitizeInput($value, $rules[$key] ?? []);
      }

      $sanitized[$key] = $value;
    }

    return $sanitized;
  }

  /**
   * Validate an email address; returns empty string when invalid.
   */
  public static function sanitizeEmail(string $value): string
  {
    $email = filter_var($value, FILTER_VALIDATE_EMAIL);
    return $email === false ? '' : $email;
  }

  /**
   * Validate a URL; returns empty string when invalid.
   */
  public static function sanitizeUrl(string $value): string
  {
    $url = filter_var($value, FILTER_VALIDATE_URL);
    return $url === false ? '' : $url;
  }

  // -----------------------------------------------------------------------
  // Security headers
  // -----------------------------------------------------------------------

  /**
   * Send recommended security headers on every response.
   *
   * Note: 'unsafe-inline' is intentionally kept for style-src because the
   * bundled views use inline <style> blocks. script-src is locked down to
   * 'self' plus the pinned CDN - there are no inline <script> blocks.
   */
  public static function sendSecurityHeaders(): void
  {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
  }

  /**
   * Send the HSTS header (only effective over HTTPS).
   */
  public static function setHsts(int $maxAge = 31536000, bool $includeSubDomains = true): void
  {
    $hstsValue = "max-age={$maxAge};" . ($includeSubDomains ? ' includeSubDomains' : '');
    header("Strict-Transport-Security: {$hstsValue}");
  }

  // -----------------------------------------------------------------------
  // CORS
  // -----------------------------------------------------------------------

  /**
   * Check whether an Origin header value is in the CORS allow-list.
   */
  public static function isValidOrigin(string $origin): bool
  {
    $allowedOrigins = self::getAllowedOrigins();
    if (empty($allowedOrigins)) {
      return false;
    }
    return in_array($origin, $allowedOrigins, true);
  }

  /**
   * Read allowed CORS origins from the environment.
   */
  protected static function getAllowedOrigins(): array
  {
    $envOrigins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
    if (empty($envOrigins)) {
      return [];
    }
    return array_map('trim', explode(',', $envOrigins));
  }

  // -----------------------------------------------------------------------
  // Rate limiting
  // -----------------------------------------------------------------------

  /**
   * Per-session rate limiter.
   *
   * Returns true when the caller is still within the allowed number of
   * attempts for the given key, false when the limit is exceeded.
   */
  public static function throttle(string $key, int $maxAttempts = 60, int $decaySeconds = 60): bool
  {
    self::startSession();

    $bucketKey = 'throttle_' . md5($key);
    $now = time();

    if (!isset($_SESSION[$bucketKey])) {
      $_SESSION[$bucketKey] = ['count' => 0, 'reset' => $now + $decaySeconds];
    }

    if ($now > $_SESSION[$bucketKey]['reset']) {
      $_SESSION[$bucketKey] = ['count' => 0, 'reset' => $now + $decaySeconds];
    }

    $_SESSION[$bucketKey]['count']++;

    return $_SESSION[$bucketKey]['count'] <= $maxAttempts;
  }

  /**
   * Per-IP rate limiter.
   *
   * Secondary layer on top of session-based throttling.
   * Returns true when the caller is within the allowed number of
   * attempts for the given key, false when the limit is exceeded.
   */
  public static function throttleIp(string $key, int $maxAttempts = 30, int $decaySeconds = 60): bool
  {
    self::startSession();

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $bucketKey = 'throttle_ip_' . md5($key . '_' . $ip);
    $now = time();

    if (!isset($_SESSION[$bucketKey])) {
      $_SESSION[$bucketKey] = ['count' => 0, 'reset' => $now + $decaySeconds];
    }

    if ($now > $_SESSION[$bucketKey]['reset']) {
      $_SESSION[$bucketKey] = ['count' => 0, 'reset' => $now + $decaySeconds];
    }

    $_SESSION[$bucketKey]['count']++;

    return $_SESSION[$bucketKey]['count'] <= $maxAttempts;
  }

  /**
   * Send a JSON 429 response for a rate-limited request.
   */
  public static function rateLimitResponse(int $retryAfter = 60): void
  {
    http_response_code(429);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Retry-After: ' . $retryAfter);
    echo json_encode([
      'success' => false,
      'message' => 'Too many requests. Please try again later.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // -----------------------------------------------------------------------
  // Security event logging
  // -----------------------------------------------------------------------

  /**
   * Write a structured security event to the log file.
   */
  public static function logEvent(string $event, array $data = [], string $level = 'info'): void
  {
    $logDir = CANYON_BASE_PATH . '/storage/logs';
    if (!is_dir($logDir)) {
      @mkdir($logDir, 0750, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 512);
    $userAgent = str_replace(["\r", "\n"], ' ', $userAgent);

    $logEntry = sprintf(
      "[%s] %s | IP: %s | UA: %s | Event: %s | Data: %s\n",
      $timestamp,
      strtoupper($level),
      $ip,
      $userAgent,
      $event,
      json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
    );

    @file_put_contents($logDir . '/security.log', $logEntry, FILE_APPEND | LOCK_EX);
  }
}
