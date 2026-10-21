<?php

namespace Canyon\Core;

/**
 * Canyon Framework View Class
 *
 * Renders PHP templates with automatic output escaping (XSS prevention)
 * and path-traversal protection.
 *
 * Laravel Blade and CodeIgniter Views.
 */
class View
{
  /** @var array<string, array<int, string>> */
  protected static array $paths = [];

  /** @var string|null Layout to wrap the rendered view in */
  protected static ?string $layout = null;

  /** @var array<string, string> Named sections captured during rendering */
  protected static array $sections = [];

  /** @var string|null Name of the section currently being captured */
  protected static ?string $currentSection = null;

  // -----------------------------------------------------------------------
  // Path registration
  // -----------------------------------------------------------------------

  /**
   * Register a directory to search for view files.
   */
  public static function addPath(string $path, string $namespace = 'default'): void
  {
    self::$paths[$namespace][] = $path;
  }

  // -----------------------------------------------------------------------
  // Rendering
  // -----------------------------------------------------------------------

  /**
   * Render a view and return the output as a string.
   *
   * @param string $view  Dot-notated view name (e.g. 'home.index')
   * @param array $data  Variables to extract into the view scope
   * @param bool  $escape Whether to auto-escape string values
   */
  public static function make(string $view, array $data = [], bool $escape = true): string
  {
    return (new self())->renderInternal($view, $data, $escape);
  }

  /**
   * Instance-level rendering.
   *
   * Having this as an instance method means $this is available inside the
   * included template file, so views can call $this->layout(),
   * $this->section(), etc.  (PHP 8+ forbids reassigning $this, which is why
   * the old code crashed.)
   */
  private function renderInternal(string $view, array $data = [], bool $escape = true): string
  {
    self::assertValidViewName($view);

    $viewPath = self::findView($view);

    if ($viewPath === null || !file_exists($viewPath)) {
      throw new \RuntimeException('View not found: ' . self::escape($view));
    }

    self::assertPathIsWithinBase($viewPath);

    // Prepare escaped and raw data for the view
    $escapedData = $escape ? self::escapeData($data) : $data;
    $rawData = $data;

    // Reset layout and sections for this render
    self::$layout = null;
    self::$sections = [];
    self::$currentSection = null;

    ob_start();

    extract($escapedData, EXTR_SKIP);

    // Provide raw (unescaped) data via $raw for explicit unescaped output
    $raw = new \stdClass();
    foreach ($rawData as $key => $value) {
      $raw->$key = $value;
    }

    // $this is available because renderInternal() is an instance method.
    // (Previously the code did `$this = new self();` here, which is illegal
    // in PHP 8+ and caused a fatal error.)
    try {
      include $viewPath;
    } catch (\Throwable $exception) {
      ob_end_clean();
      throw $exception;
    }

    $content = ob_get_clean();

    // If a layout was set, render the layout with the captured sections
    if (self::$layout !== null) {
      $layoutData = array_merge($data, self::$sections);
      return self::make(self::$layout, $layoutData, $escape);
    }

    return $content;
  }

  // -----------------------------------------------------------------------
  // Layout support (Laravel-style)
  // -----------------------------------------------------------------------

  /**
   * Set the layout to wrap the rendered view in.
   */
  public function layout(string $name): void
  {
    self::$layout = $name;
  }

  /**
   * Start capturing a named section.
   */
  public function section(string $name): void
  {
    self::$currentSection = $name;
    ob_start();
  }

  /**
   * End capturing the current section.
   */
  public function endSection(): void
  {
    if (self::$currentSection !== null) {
      self::$sections[self::$currentSection] = ob_get_clean();
      self::$currentSection = null;
    }
  }

  /**
   * Output a captured section's content.
   */
  public function yield(string $name): string
  {
    return self::$sections[$name] ?? '';
  }

  /**
   * Render a view and echo the output immediately.
   */
  public static function render(string $view, array $data = [], bool $escape = true): void
  {
    echo self::make($view, $data, $escape);
  }

  /**
   * Check whether a view exists in any registered path.
   */
  public static function exists(string $view): bool
  {
    return self::findView($view) !== null;
  }

  // -----------------------------------------------------------------------
  // Escaping helpers
  // -----------------------------------------------------------------------

  /**
   * Escape a value for safe HTML output.
   */
  public static function escape(mixed $value): string
  {
    if (is_string($value)) {
      return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return (string) $value;
  }

  /**
   * Get the raw (unescaped) value for a view key.
   * Use this only for outputting HTML that was generated by the application.
   */
  public static function raw(mixed $value): string
  {
    if (is_string($value)) {
      return $value;
    }
    if (is_array($value)) {
      return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return (string) $value;
  }

  // -----------------------------------------------------------------------
  // Internal helpers
  // -----------------------------------------------------------------------

  /**
   * Recursively escape string values in an array.
   */
  protected static function escapeArray(array $data): array
  {
    $escaped = [];

    foreach ($data as $key => $value) {
      if (is_string($value)) {
        $escaped[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      } elseif (is_array($value)) {
        $escaped[$key] = self::escapeArray($value);
      } else {
        $escaped[$key] = $value;
      }
    }

    return $escaped;
  }

  /**
   * Escape all string values in the data array.
   */
  protected static function escapeData(array $data): array
  {
    $escaped = [];

    foreach ($data as $key => $value) {
      if (is_string($value)) {
        $escaped[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      } elseif (is_array($value)) {
        $escaped[$key] = self::escapeArray($value);
      } else {
        $escaped[$key] = $value;
      }
    }

    return $escaped;
  }

  /**
   * Find the absolute path to a view file, or null if not found.
   */
  protected static function findView(string $view): ?string
  {
    foreach (self::$paths as $namespace => $paths) {
      foreach ($paths as $path) {
        $file = rtrim($path, '/') . '/' . str_replace('.', '/', $view) . '.php';
        if (file_exists($file) && is_file($file)) {
          return $file;
        }
      }
    }
    return null;
  }

  /**
   * Reject view names that could enable path traversal.
   */
  protected static function assertValidViewName(string $view): void
  {
    if ($view === '') {
      throw new \InvalidArgumentException('View name must be a non-empty string');
    }

    if (str_contains($view, '..') ||
      str_contains($view, '/') ||
      str_contains($view, '\\') ||
      str_contains($view, "\0")) {
      throw new \InvalidArgumentException('Invalid view name');
    }
  }

  /**
   * Ensure the resolved view path is inside the base views directory.
   */
  protected static function assertPathIsWithinBase(string $viewPath): void
  {
    $basePath = realpath(CANYON_RESOURCES_PATH . '/views');
    $realViewPath = realpath($viewPath);

    if ($basePath === false || $realViewPath === false || strpos($realViewPath, $basePath) !== 0) {
      throw new \RuntimeException('Invalid view path');
    }
  }
}
