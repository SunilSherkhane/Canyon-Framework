<?php
/**
 * PHPUnit bootstrap for Canyon Framework tests
 */

require __DIR__ . '/../vendor/autoload.php';

// Define base paths
if (!defined('CANYON_BASE_PATH')) {
 define('CANYON_BASE_PATH', dirname(__DIR__));
}
if (!defined('CANYON_APP_PATH')) {
 define('CANYON_APP_PATH', CANYON_BASE_PATH . '/app');
}
if (!defined('CANYON_CONFIG_PATH')) {
 define('CANYON_CONFIG_PATH', CANYON_BASE_PATH . '/config');
}
if (!defined('CANYON_RESOURCES_PATH')) {
 define('CANYON_RESOURCES_PATH', CANYON_BASE_PATH . '/resources');
}
if (!defined('CANYON_STORAGE_PATH')) {
 define('CANYON_STORAGE_PATH', CANYON_BASE_PATH . '/storage');
}

require CANYON_APP_PATH . '/Helpers/env.php';
require CANYON_APP_PATH . '/Core/Application.php';

// Initialize environment
$_ENV['APP_DEBUG'] = 'true';
$_ENV['APP_NAME'] = 'Canyon Test';
$_ENV['APP_ENV'] = 'testing';
