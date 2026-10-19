<?php

/**
 * Canyon Framework — component bootstrap.
 *
 * Discovers `components/<name>/component.json` manifests and works out which
 * components are enabled, so the autoloader can be told where each namespace
 * lives. This file runs *before* any framework class can be autoloaded (the
 * autoloader it configures is what makes those classes reachable), so it
 * deliberately depends on nothing but PHP's standard library.
 *
 * The richer, object-oriented API lives in Canyon\Core\Component, which calls
 * these functions once the autoloader is live.
 */

if (!function_exists('canyon_discover_components')) {
 /**
  * Read every component manifest under $componentsPath.
  *
  * @return array<string, array<string, mixed>> keyed by component name, sorted by name.
  *   Each manifest gains a `__path` key holding its absolute directory.
  */
 function canyon_discover_components(string $componentsPath): array
 {
  if (!is_dir($componentsPath)) {
   return [];
  }

  $manifests = [];

  foreach (glob(rtrim($componentsPath, '/') . '/*/component.json') ?: [] as $file) {
   $decoded = json_decode((string) @file_get_contents($file), true);

   if (!is_array($decoded) || !isset($decoded['name']) || !is_string($decoded['name'])) {
    // A malformed manifest must not take the whole site down; skip it.
    continue;
   }

   $decoded['__path'] = dirname($file);
   $decoded['__manifest'] = $file;
   $manifests[$decoded['name']] = $decoded;
  }

  ksort($manifests);

  return $manifests;
 }
}

if (!function_exists('canyon_resolve_components')) {
 /**
  * Work out the enabled set: manifest defaults, minus overrides, plus
  * anything pulled in through `requires` (transitively).
  *
  * `core` is mandatory and can never be disabled — without it nothing loads.
  *
  * @param array<string, array<string, mixed>> $manifests
  * @param string[]       $disabled names to force off
  * @return array<string, array<string, mixed>>
  */
 function canyon_resolve_components(array $manifests, array $disabled = []): array
 {
  $enabled = [];

  foreach ($manifests as $name => $manifest) {
   $wanted = ($manifest['enabled'] ?? true) !== false;
   if ($wanted && !in_array($name, $disabled, true)) {
    $enabled[$name] = $manifest;
   }
  }

  // Core is the kernel: always present.
  if (isset($manifests['core'])) {
   $enabled['core'] = $manifests['core'];
  }

  // Pull in dependencies, and their dependencies, until the set settles.
  do {
   $changed = false;
   foreach ($enabled as $manifest) {
    foreach ((array) ($manifest['requires'] ?? []) as $dependency) {
     if (!isset($enabled[$dependency]) && isset($manifests[$dependency])) {
      $enabled[$dependency] = $manifests[$dependency];
      $changed = true;
     }
    }
   }
  } while ($changed);

  ksort($enabled);

  return $enabled;
 }
}

if (!function_exists('canyon_component_psr4')) {
 /**
  * Flatten the enabled components' PSR-4 maps into prefix => [directories].
  *
  * Several components may share one prefix (every Canyon component currently
  * declares `Canyon\Core\`), so the value is always a list of directories —
  * the autoloader walks them in order.
  *
  * @param array<string, array<string, mixed>> $enabled
  * @return array<string, string[]>
  */
 function canyon_component_psr4(array $enabled): array
 {
  $map = [];

  foreach ($enabled as $manifest) {
   $base = rtrim((string) ($manifest['__path'] ?? ''), '/');

   foreach ((array) ($manifest['autoload']['psr-4'] ?? []) as $prefix => $relative) {
    $directory = $base . '/' . trim((string) $relative, '/');

    if (!in_array($directory, $map[$prefix] ?? [], true)) {
     $map[$prefix][] = $directory;
    }
   }
  }

  return $map;
 }
}

if (!function_exists('canyon_component_files')) {
 /**
  * Absolute paths of the plain PHP files enabled components ask to be
  * `require`d up front (manifest `files` key). Used for helper functions
  * that are not autoloadable.
  *
  * @param array<string, array<string, mixed>> $enabled
  * @return string[]
  */
 function canyon_component_files(array $enabled): array
 {
  $files = [];

  foreach ($enabled as $manifest) {
   $base = rtrim((string) ($manifest['__path'] ?? ''), '/');

   foreach ((array) ($manifest['files'] ?? []) as $relative) {
    $path = $base . '/' . ltrim((string) $relative, '/');
    if (is_file($path)) {
     $files[] = $path;
    }
   }
  }

  return $files;
 }
}
