<?php

/**
 * Canyon Framework — Application Entry Point.
 *
 * The bootstrap (config, components, autoloader, helpers, sessions, security
 * headers) lives in the framework's bootstrap.php. Loading it from the shared
 * checkout — rather than copying it here — is what lets an application pick up
 * framework fixes with a single `git pull`.
 *
 * config.php is loaded first because it is what tells us where the framework
 * lives (CANYON_SHARED_PATH). Leave that unset to run against this checkout.
 */

// ---------------------------------------------------------------------------
// Configuration (defines CANYON_BASE_PATH and, optionally, CANYON_SHARED_PATH)
// ---------------------------------------------------------------------------

$configPath = dirname(__DIR__) . '/config.php';
if (file_exists($configPath)) {
 require_once $configPath;
} elseif (file_exists(dirname(__DIR__) . '/.env')) {
 // Fallback to .env if config.php doesn't exist
 $envLines = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
 foreach ($envLines as $line) {
  $line = trim($line);
  if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
  $separatorPos = strpos($line, '=');
  $key = trim(substr($line, 0, $separatorPos));
  $value = trim(substr($line, $separatorPos + 1));
  $commentPos = strpos($value, ' #');
  if ($commentPos !== false) $value = trim(substr($value, 0, $commentPos));
  if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
   (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
   $value = substr($value, 1, -1);
  }
  if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
   $_ENV[$key] = $value;
  }
 }
}

// ---------------------------------------------------------------------------
// Bootstrap (shared framework, or this checkout when CANYON_SHARED_PATH unset)
// ---------------------------------------------------------------------------

$canyonFrameworkPath = defined('CANYON_SHARED_PATH') ? CANYON_SHARED_PATH : dirname(__DIR__);

require $canyonFrameworkPath . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Load routes and dispatch (lazy load everything else)
// ---------------------------------------------------------------------------

if (file_exists(CANYON_ROUTES_PATH . '/web.php')) {
 require CANYON_ROUTES_PATH . '/web.php';
}

$response = \Canyon\Core\Router::dispatch();

if (is_string($response)) {
 echo $response;
}
