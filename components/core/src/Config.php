<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Configuration
 *
 * Dot-notation config access, CodeIgniter's Config class.
 * Supports config files in config/ directory and environment overrides.
 */
class Config
{
  /** @var array<string, mixed> Loaded config data */
  protected static array $items = [];

  /** @var string Config directory */
  protected static string $configDir;

  /**
   * Initialize config directory.
   */
  public static function init(): void
  {
    self::$configDir = CANYON_CONFIG_PATH;
  }

  /**
   * Get a config value using dot notation.
   *
   * @param string $key Dot-notation key (e.g., 'app.name')
   * @param mixed $default Default value if key not found
   * @return mixed
   */
  public static function get(string $key, mixed $default = null): mixed
  {
    if (empty(self::$items)) {
      self::loadAll();
    }

    $segments = explode('.', $key);
    $value = self::$items;

    foreach ($segments as $segment) {
      if (!is_array($value) || !array_key_exists($segment, $value)) {
        return $default;
      }
      $value = $value[$segment];
    }

    return $value;
  }

  /**
   * Set a config value using dot notation.
   */
  public static function set(string $key, mixed $value): void
  {
    $segments = explode('.', $key);
    $ref = &self::$items;

    foreach ($segments as $segment) {
      if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
        $ref[$segment] = [];
      }
      $ref = &$ref[$segment];
    }

    $ref = $value;
  }

  /**
   * Check if a config key exists.
   */
  public static function has(string $key): bool
  {
    return self::get($key, '__NOT_FOUND__') !== '__NOT_FOUND__';
  }

  /**
   * Get all config items.
   */
  public static function all(): array
  {
    if (empty(self::$items)) {
      self::loadAll();
    }
    return self::$items;
  }

  /**
   * Load all config files from the config directory.
   */
  protected static function loadAll(): void
  {
    self::init();

    if (!is_dir(self::$configDir)) {
      return;
    }

    $files = glob(self::$configDir . '/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
      $name = pathinfo($file, PATHINFO_FILENAME);
      $data = require $file;
      if (is_array($data)) {
        self::$items[$name] = array_merge(self::$items[$name] ?? [], $data);
      }
    }
  }

  /**
   * Load a specific config file.
   */
  public static function load(string $file): void
  {
    $path = self::$configDir . '/' . $file . '.php';
    if (file_exists($path)) {
      $data = require $path;
      if (is_array($data)) {
        self::$items[$file] = array_merge(self::$items[$file] ?? [], $data);
      }
    }
  }

  /**
   * Get a specific config file's data.
   */
  public static function file(string $file): array
  {
    return self::$items[$file] ?? [];
  }
}