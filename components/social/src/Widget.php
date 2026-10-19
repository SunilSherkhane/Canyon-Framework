<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Widget Dashboard
 *
 * Widget-based dashboard system for customizable layouts.
 * Elgg's dashboard widgets.
 */
class Widget
{
  /** @var array Registered widgets */
  protected static array $widgets = [];

  /** @var array Widget positions */
  protected static array $positions = [];

  /** @var array User widget configurations */
  protected static array $userConfigs = [];

  /**
   * Register a widget.
   *
   * @param string $id Unique widget ID
   * @param string $name Display name
   * @param callable $callback Render callback
   * @param array $options Widget options (access, cache, etc.)
   */
  public static function register(string $id, string $name, callable $callback, array $options = []): void
  {
    self::$widgets[$id] = [
      'id' => $id,
      'name' => $name,
      'callback' => $callback,
      'options' => array_merge([
        'access' => 'public',
        'cache' => false,
        'position' => 'sidebar',
        'order' => 0,
      ], $options),
    ];
  }

  /**
   * Unregister a widget.
   */
  public static function unregister(string $id): void
  {
    unset(self::$widgets[$id]);
  }

  /**
   * Get a registered widget.
   */
  public static function get(string $id): ?array
  {
    return self::$widgets[$id] ?? null;
  }

  /**
   * Get all registered widgets.
   */
  public static function all(): array
  {
    return self::$widgets;
  }

  /**
   * Get widgets by position.
   */
  public static function byPosition(string $position): array
  {
    return array_filter(self::$widgets, fn($w) => $w['options']['position'] === $position);
  }

  /**
   * Render a widget.
   */
  public static function render(string $id, array $data = []): ?string
  {
    $widget = self::$widgets[$id] ?? null;
    if (!$widget) {
      return null;
    }

    // Check access
    if (!self::checkAccess($widget, $data)) {
      return null;
    }

    // Check cache
    if ($widget['options']['cache']) {
      $cacheKey = 'widget_' . $id . '_' . md5(serialize($data));
      $cached = Cache::get($cacheKey);
      if ($cached !== null) {
        return $cached;
      }
    }

    // Render
    $output = call_user_func($widget['callback'], $data);

    // Cache if enabled
    if ($widget['options']['cache']) {
      Cache::put($cacheKey, $output, $widget['options']['cache_ttl'] ?? 300);
    }

    return $output;
  }

  /**
   * Render all widgets for a position.
   */
  public static function renderPosition(string $position, array $data = []): string
  {
    $widgets = self::byPosition($position);
    usort($widgets, fn($a, $b) => $a['options']['order'] <=> $b['options']['order']);

    $output = '';
    foreach ($widgets as $widget) {
      $rendered = self::render($widget['id'], $data);
      if ($rendered) {
        $output .= $rendered;
      }
    }

    return $output;
  }

  /**
   * Set widget position.
   */
  public static function setPosition(string $id, string $position): void
  {
    if (isset(self::$widgets[$id])) {
      self::$widgets[$id]['options']['position'] = $position;
    }
  }

  /**
   * Set widget order.
   */
  public static function setOrder(string $id, int $order): void
  {
    if (isset(self::$widgets[$id])) {
      self::$widgets[$id]['options']['order'] = $order;
    }
  }

  /**
   * Set user widget configuration.
   */
  public static function setUserConfig(string $userId, string $id, array $config): void
  {
    if (!isset(self::$userConfigs[$userId])) {
      self::$userConfigs[$userId] = [];
    }
    self::$userConfigs[$userId][$id] = $config;
  }

  /**
   * Get user widget configuration.
   */
  public static function getUserConfig(string $userId, string $id): array
  {
    return self::$userConfigs[$userId][$id] ?? [];
  }

  /**
   * Check widget access.
   */
  protected static function checkAccess(array $widget, array $data): bool
  {
    $access = $widget['options']['access'];
    if ($access === 'public') {
      return true;
    }
    if ($access === 'logged_in') {
      return isset($data['user_id']);
    }
    if ($access === 'admin') {
      return isset($data['user_id']) && AccessControl::isAdmin($data['user_id']);
    }
    return false;
  }

  /**
   * Get available positions.
   */
  public static function getPositions(): array
  {
    return array_unique(array_column(self::$widgets, 'position'));
  }

  /**
   * Clear all registered widgets.
   */
  public static function clear(): void
  {
    self::$widgets = [];
    self::$positions = [];
    self::$userConfigs = [];
  }
}