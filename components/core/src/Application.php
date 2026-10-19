<?php

namespace Canyon\Core;

/**
 * Canyon Framework Application Singleton
 *
 * Provides shared application state, data, and utilities across controllers.
 */
class Application
{
  /** @var array<string, mixed> Shared data available in all views */
  protected static array $sharedData = [];

  /** @var bool Whether the application is running in production mode */
  protected static bool $isProduction = false;

  /**
   * Initialize the application with environment settings.
   */
  public static function init(): void
  {
    self::$isProduction = env('APP_ENV', 'production') === 'production';

    self::$sharedData = [
      'app_name' => env('APP_NAME', 'Canyon Framework'),
      'app_url' => env('APP_URL', 'http://localhost'),
      'debug'  => env('APP_DEBUG', false),
      'csrf_token' => Security::generateCsrfToken(),
      'csrf_token_name' => Security::getCsrfTokenName(),
      'version' => '0.2.0',
    ];
  }

  /**
   * Set shared data for all views.
   */
  public static function set(string $key, mixed $value): void
  {
    self::$sharedData[$key] = $value;
  }

  /**
   * Get shared data.
   */
  public static function get(string $key, mixed $default = null): mixed
  {
    return self::$sharedData[$key] ?? $default;
  }

  /**
   * Get all shared data.
   */
  public static function getSharedData(): array
  {
    return self::$sharedData;
  }

  /**
   * Check if running in production.
   */
  public static function isProduction(): bool
  {
    return self::$isProduction;
  }

  /**
   * Check if running in debug mode.
   */
  public static function isDebug(): bool
  {
    return env('APP_DEBUG', false) === true || env('APP_DEBUG', 'true') === 'true';
  }
}