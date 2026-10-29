<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Throttle Middleware
 *
 * Rate-limits requests per IP using the Cache system.
 * Usage in route groups: 'throttle:60,1' (60 requests per 1 minute).
 */
class ThrottleMiddleware
{
  /** @var int Max attempts */
  protected int $maxAttempts;

  /** @var int Decay window in seconds */
  protected int $decaySeconds;

  /**
   * Create from "max,decay" string or use defaults.
   */
  public static function parse(string $config): self
  {
    $parts = array_map('intval', explode(',', $config));
    $max = $parts[0] ?? 60;
    $decay = $parts[1] ?? 60;

    return new self(max(1, $max), max(1, $decay));
  }

  /**
   * Constructor.
   */
  public function __construct(int $maxAttempts = 60, int $decaySeconds = 60)
  {
    $this->maxAttempts = $maxAttempts;
    $this->decaySeconds = $decaySeconds;
  }

  /**
   * Handle the request.
   */
  public function handle(?Request $request, \Closure $next): string
  {
    $ip = $request?->ip() ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = 'throttle_' . md5($ip);

    $bucket = Cache::get($key);
    $now = time();

    if (!is_array($bucket) || ($bucket['reset'] ?? 0) < $now) {
      $bucket = [
        'count' => 0,
        'reset' => $now + $this->decaySeconds,
      ];
    }

    $bucket['count']++;

    if ($bucket['count'] > $this->maxAttempts) {
      Cache::put($key, $bucket, $this->decaySeconds);
      http_response_code(429);
      header('Content-Type: application/json; charset=UTF-8');
      header('Retry-After: ' . ($bucket['reset'] - $now));
      echo json_encode([
        'error' => [
          'code'    => 429,
          'message' => 'Too many requests. Please try again later.',
        ],
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    Cache::put($key, $bucket, $this->decaySeconds);

    return $next();
  }
}
