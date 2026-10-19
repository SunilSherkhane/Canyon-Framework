<?php

namespace Canyon\Core;

/**
 * Component manager.
 *
 * Canyon ships as a mandatory `core` kernel plus optional addons, each a
 * self-contained directory under `components/` with a `component.json`
 * manifest (name, version, dependencies, autoload map, enabled flag).
 *
 * The low-level discovery/registration happens in app/Helpers/components.php,
 * because it must run before the autoloader exists. This class is the
 * object-oriented front end: it records what was loaded, answers questions
 * about the set, and can turn components on and off persistently.
 *
 * @see \Canyon\Core\Component::boot()
 */
class Component
{
    /** @var array<string, array<string, mixed>> Every manifest found on disk. */
    protected static array $manifests = [];

    /** @var array<string, array<string, mixed>> The manifests actually enabled. */
    protected static array $enabled = [];

    /** @var string Absolute path to the components directory. */
    protected static string $componentsPath = '';

    /** @var bool Whether boot() has run. */
    protected static bool $booted = false;

    /**
     * Discover components and record the enabled set.
     *
     * Safe to call more than once; the second call is a no-op so that a
     * bootstrap file and a CLI entry point can both ask for it.
     *
     * @param string   $componentsPath Absolute path to the components directory.
     * @param string[] $disabled       Component names to force off.
     */
    public static function boot(string $componentsPath, array $disabled = []): void
    {
        if (self::$booted) {
            return;
        }

        self::$componentsPath = rtrim($componentsPath, '/');
        self::$manifests = canyon_discover_components(self::$componentsPath);
        self::$enabled = canyon_resolve_components(self::$manifests, $disabled);
        self::$booted = true;
    }

    /**
     * Whether boot() has run — used by tests and the CLI.
     */
    public static function isBooted(): bool
    {
        return self::$booted;
    }

    /** Every component found on disk, enabled or not. @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$manifests;
    }

    /** The enabled components. @return array<string, array<string, mixed>> */
    public static function enabled(): array
    {
        return self::$enabled;
    }

    /** Enabled component names. @return string[] */
    public static function enabledNames(): array
    {
        return array_keys(self::$enabled);
    }

    /** Component names present on disk but not enabled. @return string[] */
    public static function disabledNames(): array
    {
        return array_values(array_diff(array_keys(self::$manifests), array_keys(self::$enabled)));
    }

    /** Is $name enabled? */
    public static function isEnabled(string $name): bool
    {
        return isset(self::$enabled[$name]);
    }

    /** Is $name present on disk? */
    public static function exists(string $name): bool
    {
        return isset(self::$manifests[$name]);
    }

    /** The manifest for $name, or null. @return array<string, mixed>|null */
    public static function manifest(string $name): ?array
    {
        return self::$manifests[$name] ?? null;
    }

    /** Absolute directory of $name, or null. */
    public static function path(string $name): ?string
    {
        $manifest = self::manifest($name);
        return $manifest === null ? null : (string) $manifest['__path'];
    }

    /** Absolute src directory of $name (manifest `autoload.psr-4` target), or null. */
    public static function srcPath(string $name): ?string
    {
        $base = self::path($name);
        if ($base === null) {
            return null;
        }

        $relative = null;
        foreach ((array) (self::manifest($name)['autoload']['psr-4'] ?? []) as $dir) {
            $relative = (string) $dir;
            break;
        }

        return $relative === null ? $base . '/src' : $base . '/' . trim($relative, '/');
    }

    /** Every enabled src directory. @return string[] */
    public static function srcPaths(): array
    {
        $paths = [];
        foreach (array_keys(self::$enabled) as $name) {
            $path = self::srcPath($name);
            if ($path !== null) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /** The flattened PSR-4 map for the enabled set. @return array<string, string[]> */
    public static function psr4Map(): array
    {
        return canyon_component_psr4(self::$enabled);
    }

    /**
     * A plain-data description of the component set — for the CLI, an admin
     * screen, or a JSON endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function info(): array
    {
        $rows = [];

        foreach (self::$manifests as $name => $manifest) {
            $rows[] = [
                'name' => $name,
                'title' => $manifest['title'] ?? $name,
                'version' => $manifest['version'] ?? '0.0.0',
                'type' => $manifest['type'] ?? 'addon',
                'enabled' => self::isEnabled($name),
                'required' => ($manifest['type'] ?? '') === 'core',
                'requires' => array_values((array) ($manifest['requires'] ?? [])),
                'classes' => count((array) ($manifest['classes'] ?? [])),
                'path' => $manifest['__path'] ?? '',
            ];
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Enable / disable
    // -----------------------------------------------------------------------

    /** Path of the override file that records enable/disable decisions. */
    public static function configPath(): string
    {
        return dirname(self::$componentsPath) . '/components.json';
    }

    /**
     * Read the override file.
     *
     * @return array{disabled: string[]}
     */
    public static function config(): array
    {
        $path = self::configPath();

        if (!is_file($path)) {
            return ['disabled' => []];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        if (!is_array($decoded)) {
            return ['disabled' => []];
        }

        return ['disabled' => array_values(array_map('strval', (array) ($decoded['disabled'] ?? [])))];
    }

    /**
     * Persist an enable/disable decision.
     *
     * The `core` component is mandatory, so it can never be disabled. Turning a
     * component off also turns off anything that depends on it, otherwise the
     * next request would fail while trying to autoload a missing parent.
     *
     * @return bool true when the override file was written.
     */
    public static function setEnabled(string $name, bool $enabled): bool
    {
        if (!self::exists($name)) {
            return false;
        }

        if (!$enabled && ($name === 'core' || (self::manifest($name)['type'] ?? '') === 'core')) {
            return false;
        }

        $disabled = self::config()['disabled'];

        if ($enabled) {
            $disabled = array_values(array_diff($disabled, [$name]));

            // Re-enabling a component may need its dependencies back on.
            foreach ((array) (self::manifest($name)['requires'] ?? []) as $dependency) {
                $disabled = array_values(array_diff($disabled, [$dependency]));
            }
        } else {
            $disabled[] = $name;

            // Anything that requires it must go too.
            foreach (self::$manifests as $other => $manifest) {
                if (in_array($name, (array) ($manifest['requires'] ?? []), true)) {
                    $disabled[] = $other;
                }
            }

            $disabled = array_values(array_unique($disabled));
        }

        sort($disabled);

        $path = self::configPath();
        $payload = json_encode(
            ['disabled' => $disabled],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        return $payload !== false && @file_put_contents($path, $payload . "\n") !== false;
    }
}
