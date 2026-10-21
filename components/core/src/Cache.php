<?php

namespace Canyon\Core;

/**
 * Canyon Framework - File Cache System
 *
 * Simple file-based cache with TTL support, Laravel's cache.
 */
class Cache
{
  /** @var string Cache directory */
  protected static string $cacheDir;

  /** @var int Default TTL in seconds */
  protected static int $defaultTtl = 3600;

  /**
   * Initialize cache directory.
   */
  public static function init(): void
  {
    self::$cacheDir = CANYON_STORAGE_PATH . '/cache';
    if (!is_dir(self::$cacheDir)) {
      mkdir(self::$cacheDir, 0750, true);
    }
  }

  /**
   * Store an item in the cache.
   */
  public static function put(string $key, mixed $value, ?int $ttl = null): void
  {
    self::init();
    $ttl = $ttl ?? self::$defaultTtl;
    $expiresAt = time() + $ttl;
    $data = [
      'expires_at' => $expiresAt,
      'value' => $value,
    ];
    $file = self::getCacheFile($key);
    @file_put_contents($file, serialize($data), LOCK_EX);
  }

  /**
   * Retrieve an item from the cache.
   */
  public static function get(string $key, mixed $default = null): mixed
  {
    self::init();
    $file = self::getCacheFile($key);

    if (!file_exists($file)) {
      return $default;
    }

    $data = unserialize(file_get_contents($file), ['allowed_classes' => false]);

    if (!is_array($data) || !isset($data['expires_at'])) {
      unlink($file);
      return $default;
    }

    // Check if expired
    if (time() > $data['expires_at']) {
      unlink($file);
      return $default;
    }

    return $data['value'] ?? $default;
  }

  /**
   * Check if an item exists and is not expired.
   */
  public static function has(string $key): bool
  {
    self::init();
    $file = self::getCacheFile($key);

    if (!file_exists($file)) {
      return false;
    }

    $data = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    return is_array($data) && isset($data['expires_at']) && time() <= $data['expires_at'];
  }

  /**
   * Remove an item from the cache.
   */
  public static function forget(string $key): void
  {
    self::init();
    $file = self::getCacheFile($key);
    if (file_exists($file)) {
      unlink($file);
    }
  }

  /**
   * Remove all items from the cache.
   */
  public static function flush(): void
  {
    self::init();
    $files = glob(self::$cacheDir . '/*');
    if ($files === false) {
      return;
    }
    foreach ($files as $file) {
      if (is_file($file)) {
        unlink($file);
      }
    }
  }

  /**
   * Remember an item in the cache, or execute the callback and store the result.
   */
  public static function remember(string $key, callable $callback, ?int $ttl = null): mixed
  {
    $value = self::get($key);
    if ($value !== null) {
      return $value;
    }
    $value = $callback();
    self::put($key, $value, $ttl);
    return $value;
  }

  /**
   * Get the cache file path for a key.
   */
  protected static function getCacheFile(string $key): string
  {
    return self::$cacheDir . '/' . md5($key) . '.cache';
  }
}