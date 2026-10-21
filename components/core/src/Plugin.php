<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Plugin System
 *
 * Modular plugin architecture with elgg-plugin.php style configuration.
 * Plugins can register routes, actions, widgets, events, entities, and more.
 */
class Plugin
{
  /** @var array Loaded plugins */
  protected static array $plugins = [];

  /** @var array Plugin configurations */
  protected static array $configs = [];

  /** @var bool Whether plugins have been loaded */
  protected static bool $loaded = false;

  /**
   * Load all plugins from the plugins directory.
   */
  public static function loadAll(): void
  {
    if (self::$loaded) {
      return;
    }

    $pluginDir = CANYON_BASE_PATH . '/plugins';
    if (!is_dir($pluginDir)) {
      mkdir($pluginDir, 0750, true);
      self::$loaded = true;
      return;
    }

    $dirs = glob($pluginDir . '/*', GLOB_ONLYDIR) ?: [];
    sort($dirs);

    foreach ($dirs as $dir) {
      $name = basename($dir);
      $configFile = $dir . '/canyon-plugin.php';
      $composerFile = $dir . '/composer.json';

      // Check for composer.json (Elgg compatibility)
      if (file_exists($composerFile)) {
        $composer = json_decode(file_get_contents($composerFile), true);
        if (isset($composer['type']) && $composer['type'] !== 'canyon-plugin') {
          continue; // Not a Canyon plugin
        }
      }

      // Load plugin config
      $config = [];
      if (file_exists($configFile)) {
        $config = require $configFile;
        if (!is_array($config)) {
          $config = [];
        }
      }

      self::$configs[$name] = $config;
      self::$plugins[$name] = [
        'name' => $name,
        'path' => $dir,
        'active' => $config['plugin']['active'] ?? true,
        'version' => $config['plugin']['version'] ?? '1.0.0',
      ];

      // Register plugin components
      if (self::$plugins[$name]['active']) {
        self::registerComponents($name, $config, $dir);
      }
    }

    self::$loaded = true;
  }

  /**
   * Register plugin components config.
   */
  protected static function registerComponents(string $name, array $config, string $path): void
  {
    // Register routes
    if (isset($config['routes'])) {
      foreach ($config['routes'] as $routeName => $routeConfig) {
        $method = strtoupper($routeConfig['method'] ?? 'GET');
        $uri = $routeConfig['path'] ?? '/';
        $handler = $routeConfig['controller'] ?? null;
        $middleware = $routeConfig['middleware'] ?? [];

        if ($handler) {
          Router::$method($uri, $handler, $routeName, $middleware);
        }
      }
    }

    // Register widgets
    if (isset($config['widgets'])) {
      foreach ($config['widgets'] as $widgetName => $widgetConfig) {
        $callback = $widgetConfig['callback'] ?? null;
        $options = $widgetConfig['options'] ?? [];
        if ($callback && is_callable($callback)) {
          Widget::register($widgetName, $widgetConfig['name'] ?? $widgetName, $callback, $options);
        }
      }
    }

    // Register events
    if (isset($config['events'])) {
      foreach ($config['events'] as $event => $handlers) {
        foreach ($handlers as $handlerName => $handlerConfig) {
          $callback = $handlerConfig['callback'] ?? null;
          $priority = $handlerConfig['priority'] ?? 100;
          if ($callback && is_callable($callback)) {
            EventDispatcher::listen($event, $callback, $priority);
          }
        }
      }
    }

    // Register entities
    if (isset($config['entities'])) {
      foreach ($config['entities'] as $entityConfig) {
        $type = $entityConfig['type'] ?? 'object';
        $subtype = $entityConfig['subtype'] ?? '';
        $class = $entityConfig['class'] ?? '';
        if ($class && class_exists($class)) {
          // Entity is registered via class existence
          // Actual registration happens when the entity is used
        }
      }
    }

    // Register actions
    if (isset($config['actions'])) {
      foreach ($config['actions'] as $actionName => $actionConfig) {
        $access = $actionConfig['access'] ?? 'logged_in';
        $callback = $actionConfig['controller'] ?? null;
        if ($callback) {
          Action::register($actionName, $callback, $access);
        }
      }
    }

    // Register menus
    if (isset($config['menus'])) {
      foreach ($config['menus'] as $menuName => $items) {
        foreach ($items as $itemName => $itemConfig) {
          Menu::add($menuName, $itemName, $itemConfig);
        }
      }
    }
  }

  /**
   * Get all loaded plugins.
   */
  public static function all(): array
  {
    if (!self::$loaded) {
      self::loadAll();
    }
    return self::$plugins;
  }

  /**
   * Get a specific plugin.
   */
  public static function get(string $name): ?array
  {
    if (!self::$loaded) {
      self::loadAll();
    }
    return self::$plugins[$name] ?? null;
  }

  /**
   * Check if a plugin is active.
   */
  public static function isActive(string $name): bool
  {
    if (!self::$loaded) {
      self::loadAll();
    }
    return self::$plugins[$name]['active'] ?? false;
  }

  /**
   * Get plugin config.
   */
  public static function getConfig(string $name): array
  {
    if (!self::$loaded) {
      self::loadAll();
    }
    return self::$configs[$name] ?? [];
  }

  /**
   * Get plugin setting.
   */
  public static function getSetting(string $plugin, string $key, mixed $default = null): mixed
  {
    if (!self::$loaded) {
      self::loadAll();
    }
    $config = self::$configs[$plugin] ?? [];
    return $config['settings'][$key] ?? $default;
  }

  /**
   * Get plugin user setting.
   */
  public static function getUserSetting(string $plugin, string $userId, string $key, mixed $default = null): mixed
  {
    // Load from database or file
    $file = CANYON_BASE_PATH . "/plugins/{$plugin}/user_settings/{$userId}.php";
    if (file_exists($file)) {
      $data = require $file;
      return $data[$key] ?? $default;
    }
    return $default;
  }

  /**
   * Set plugin user setting.
   */
  public static function setUserSetting(string $plugin, string $userId, string $key, mixed $value): bool
  {
    $dir = CANYON_BASE_PATH . "/plugins/{$plugin}/user_settings";
    if (!is_dir($dir)) {
      mkdir($dir, 0750, true);
    }

    $file = $dir . "/{$userId}.php";
    $data = [];
    if (file_exists($file)) {
      $data = require $file;
    }
    $data[$key] = $value;

    $content = "<?php\n\nreturn " . var_export($data, true) . ";\n";
    return @file_put_contents($file, $content) !== false;
  }

  /**
   * Get active plugins count.
   */
  public static function activeCount(): int
  {
    if (!self::$loaded) {
      self::loadAll();
    }
    return count(array_filter(self::$plugins, fn($p) => $p['active']));
  }

  /**
   * Get plugin path.
   */
  public static function getPath(string $name): ?string
  {
    if (!self::$loaded) {
      self::loadAll();
    }
    return self::$plugins[$name]['path'] ?? null;
  }
}