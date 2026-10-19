<?php

namespace Canyon\Core;

/**
 * Canyon Framework - View Partials
 *
 * Reusable view components with data isolation.
 * CodeIgniter's View Cells and Laravel's view partials.
 */
class Partial
{
    /** @var array<string, array<string, mixed>> Partial data storage */
    protected static array $sections = [];

    /** @var string Current section name */
    protected static string $currentSection = '';

    /**
     * Render a partial view.
     *
     * @param string $view View name (dot notation)
     * @param array $data Data to pass to the partial
     * @param bool $return Whether to return the rendered output
     * @return string|null
     */
    public static function render(string $view, array $data = [], bool $return = false): ?string
    {
        $output = View::make($view, $data);

        if ($return) {
            return $output;
        }

        echo $output;
        return null;
    }

    /**
     * Start a section.
     */
    public static function section(string $name): void
    {
        self::$currentSection = $name;
        ob_start();
    }

    /**
     * End a section and store its content.
     */
    public static function endSection(): void
    {
        if (self::$currentSection === '') {
            throw new \RuntimeException('No section started.');
        }
        self::$sections[self::$currentSection] = ob_get_clean();
        self::$currentSection = '';
    }

    /**
     * Yield a section's content.
     */
    public static function yield(string $name, string $default = ''): void
    {
        echo (string) (self::$sections[$name] ?? $default);
    }

    /**
     * Check if a section has been defined.
     */
    public static function has(string $name): bool
    {
        return isset(self::$sections[$name]);
    }

    /**
     * Include a partial view with isolated data.
     *
     * @param string $view View name
     * @param array $data Data for this partial only
     */
    public static function include(string $view, array $data = []): void
    {
        echo View::make($view, $data);
    }

    /**
     * Extend a layout with sections.
     */
    public static function extend(string $layout): void
    {
        echo View::make($layout);
    }

    /**
     * Clear all sections.
     */
    public static function clear(): void
    {
        self::$sections = [];
        self::$currentSection = '';
    }
}