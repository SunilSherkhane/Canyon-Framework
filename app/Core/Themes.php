<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Themes
 *
 * Theme system with variables and overrides.
 * Inspired by Elgg's theme system.
 */
class Themes
{
    /** @var array Registered themes */
    protected static array $themes = [];

    /** @var string Current theme */
    protected static string $currentTheme = 'default';

    /** @var array Theme variables */
    protected static array $variables = [];

    /** @var array Theme CSS files */
    protected static array $css = [];

    /** @var array Theme JS files */
    protected static array $js = [];

    /** @var string Theme directory */
    protected static string $themeDir;

    /**
     * Initialize theme system.
     */
    public static function init(): void
    {
        self::$themeDir = CANYON_RESOURCES_PATH . '/themes';
        if (!is_dir(self::$themeDir)) {
            mkdir(self::$themeDir, 0750, true);
        }
    }

    /**
     * Register a theme.
     */
    public static function register(string $name, string $description = '', array $options = []): void
    {
        self::init();

        self::$themes[$name] = [
            'name' => $name,
            'description' => $description,
            'version' => $options['version'] ?? '1.0.0',
            'author' => $options['author'] ?? '',
            'path' => $options['path'] ?? self::$themeDir . '/' . $name,
            'parent' => $options['parent'] ?? null,
            'active' => false,
        ];
    }

    /**
     * Activate a theme.
     */
    public static function activate(string $name): bool
    {
        if (!isset(self::$themes[$name])) {
            return false;
        }

        // Deactivate current
        if (isset(self::$themes[self::$currentTheme])) {
            self::$themes[self::$currentTheme]['active'] = false;
        }

        self::$currentTheme = $name;
        self::$themes[$name]['active'] = true;

        // Load theme variables
        self::loadVariables($name);

        return true;
    }

    /**
     * Get the current theme.
     */
    public static function getCurrent(): string
    {
        return self::$currentTheme;
    }

    /**
     * Get current theme info.
     */
    public static function getCurrentInfo(): ?array
    {
        return self::$themes[self::$currentTheme] ?? null;
    }

    /**
     * Get all registered themes.
     */
    public static function all(): array
    {
        return self::$themes;
    }

    /**
     * Check if theme exists.
     */
    public static function exists(string $name): bool
    {
        return isset(self::$themes[$name]);
    }

    /**
     * Set a theme variable.
     */
    public static function setVariable(string $key, mixed $value): void
    {
        self::$variables[$key] = $value;
    }

    /**
     * Get a theme variable.
     */
    public static function getVariable(string $key, mixed $default = null): mixed
    {
        return self::$variables[$key] ?? $default;
    }

    /**
     * Get all theme variables.
     */
    public static function getVariables(): array
    {
        return self::$variables;
    }

    /**
     * Add CSS file to theme.
     */
    public static function addCss(string $path, int $priority = 100): void
    {
        self::$css[$path] = ['path' => $path, 'priority' => $priority];
        uasort(self::$css, fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Add JS file to theme.
     */
    public static function addJs(string $path, int $priority = 100): void
    {
        self::$js[$path] = ['path' => $path, 'priority' => $priority];
        uasort(self::$js, fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Get CSS files.
     */
    public static function getCss(): array
    {
        return array_values(self::$css);
    }

    /**
     * Get JS files.
     */
    public static function getJs(): array
    {
        return array_values(self::$js);
    }

    /**
     * Load theme variables from file.
     */
    protected static function loadVariables(string $name): void
    {
        $file = self::$themes[$name]['path'] . '/variables.php';
        if (file_exists($file)) {
            $vars = require $file;
            if (is_array($vars)) {
                self::$variables = array_merge(self::$variables, $vars);
            }
        }
    }

    /**
     * Render a theme view.
     */
    public static function render(string $view, array $data = []): string
    {
        // Merge theme variables with view data
        $data = array_merge(self::$variables, $data);

        // Try current theme view first
        $themeView = self::$themes[self::$currentTheme]['path'] . '/views/' . $view . '.php';
        if (file_exists($themeView)) {
            return View::make($themeView, $data);
        }

        // Fall back to default view
        return View::make($view, $data);
    }

    /**
     * Get theme path.
     */
    public static function getPath(string $name = ''): string
    {
        $name = $name ?: self::$currentTheme;
        return self::$themes[$name]['path'] ?? self::$themeDir;
    }

    /**
     * Clear all themes.
     */
    public static function clear(): void
    {
        self::$themes = [];
        self::$variables = [];
        self::$css = [];
        self::$js = [];
    }
}