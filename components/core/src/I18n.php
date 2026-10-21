<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Internationalization (i18n)
 *
 * Simple translation system with locale support.
 * Elgg's i18n and Laravel's translation.
 */
class I18n
{
  /** @var string Current locale */
  protected static string $locale = 'en';

  /** @var array Loaded translations */
  protected static array $translations = [];

  /** @var array Available locales */
  protected static array $locales = ['en'];

  /** @var string Translation directory */
  protected static string $dir;

  /**
   * Initialize i18n.
   */
  public static function init(): void
  {
    self::$dir = CANYON_RESOURCES_PATH . '/lang';
    if (!is_dir(self::$dir)) {
      mkdir(self::$dir, 0750, true);
    }
  }

  /**
   * Set the current locale.
   */
  public static function setLocale(string $locale): void
  {
    self::$locale = $locale;
    self::load($locale);
  }

  /**
   * Get the current locale.
   */
  public static function getLocale(): string
  {
    return self::$locale;
  }

  /**
   * Load translations for a locale.
   */
  public static function load(string $locale): void
  {
    $file = self::$dir . '/' . $locale . '.php';
    if (file_exists($file)) {
      self::$translations[$locale] = require $file;
    }
  }

  /**
   * Translate a key.
   *
   * @param string $key Translation key (dot notation supported)
   * @param array $replace Replacement parameters
   * @param string|null $locale Locale (null = current)
   * @return string
   */
  public static function get(string $key, array $replace = [], ?string $locale = null): string
  {
    $locale = $locale ?? self::$locale;

    if (!isset(self::$translations[$locale])) {
      self::load($locale);
    }

    $translation = self::$translations[$locale] ?? [];

    // Dot notation lookup
    $segments = explode('.', $key);
    foreach ($segments as $segment) {
      if (!is_array($translation) || !array_key_exists($segment, $translation)) {
        return $key; // Return key if not found
      }
      $translation = $translation[$segment];
    }

    if (is_array($translation)) {
      return $key;
    }

    // Replace parameters
    foreach ($replace as $search => $value) {
      $translation = str_replace(':' . $search, (string) $value, $translation);
    }

    return $translation;
  }

  /**
   * Translate a key (shorthand).
   */
  public static function trans(string $key, array $replace = [], ?string $locale = null): string
  {
    return self::get($key, $replace, $locale);
  }

  /**
   * Check if a translation key exists.
   */
  public static function has(string $key, ?string $locale = null): bool
  {
    $locale = $locale ?? self::$locale;
    if (!isset(self::$translations[$locale])) {
      self::load($locale);
    }

    $translation = self::$translations[$locale] ?? [];
    $segments = explode('.', $key);
    foreach ($segments as $segment) {
      if (!is_array($translation) || !array_key_exists($segment, $translation)) {
        return false;
      }
      $translation = $translation[$segment];
    }
    return !is_array($translation);
  }

  /**
   * Add a translation.
   */
  public static function add(string $key, string $value, ?string $locale = null): void
  {
    $locale = $locale ?? self::$locale;
    if (!isset(self::$translations[$locale])) {
      self::$translations[$locale] = [];
    }

    $segments = explode('.', $key);
    $ref = &self::$translations[$locale];
    foreach ($segments as $segment) {
      if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
        $ref[$segment] = [];
      }
      $ref = &$ref[$segment];
    }
    $ref = $value;
  }

  /**
   * Get all translations for a locale.
   */
  public static function all(?string $locale = null): array
  {
    $locale = $locale ?? self::$locale;
    if (!isset(self::$translations[$locale])) {
      self::load($locale);
    }
    return self::$translations[$locale] ?? [];
  }

  /**
   * Get available locales.
   */
  public static function getLocales(): array
  {
    return self::$locales;
  }

  /**
   * Add available locale.
   */
  public static function addLocale(string $locale): void
  {
    if (!in_array($locale, self::$locales)) {
      self::$locales[] = $locale;
    }
  }

  /**
   * Create a translation file from array.
   */
  public static function createFile(string $locale, array $translations): bool
  {
    self::init();
    $file = self::$dir . '/' . $locale . '.php';
    $content = "<?php\n\nreturn " . var_export($translations, true) . ";\n";
    return @file_put_contents($file, $content) !== false;
  }

  /**
   * Detect locale from HTTP Accept-Language header.
   */
  public static function detectLocale(): string
  {
    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (empty($header)) {
      return self::$locale;
    }

    $parts = explode(',', $header);
    foreach ($parts as $part) {
      $locale = trim(explode(';', $part)[0]);
      if (in_array($locale, self::$locales)) {
        return $locale;
      }
    }

    return self::$locale;
  }

  /**
   * Get translation with plural support.
   */
  public static function choice(string $key, int $count, array $replace = [], ?string $locale = null): string
  {
    $replace['count'] = $count;
    $translation = self::get($key, $replace, $locale);

    // Simple pluralization: if count > 1, append '_plural' to key
    if ($count > 1) {
      $pluralKey = $key . '_plural';
      if (self::has($pluralKey, $locale)) {
        return self::get($pluralKey, $replace, $locale);
      }
    }

    return $translation;
  }
}