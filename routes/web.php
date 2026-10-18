<?php

/**
 * Canyon Framework — Web Routes
 */

use Canyon\Core\Router;
use Canyon\Controllers\HomeController;
use Canyon\Controllers\TestController;

// Public pages
Router::get('/',      [HomeController::class, 'index'],   'home');
Router::get('/about', [HomeController::class, 'about'],   'about');
Router::get('/contact', [HomeController::class, 'contact'], 'contact');
Router::get('/features', [HomeController::class, 'features'], 'features');

// Developer diagnostics page (dev-gated inside the controller)
Router::get('/test', [TestController::class, 'index'], 'test');

// API endpoints
Router::post('/api/data',    [HomeController::class, 'apiData'],       'api.data');
Router::post('/api/contact', [HomeController::class, 'submitContact'], 'api.contact');

// 404 catch-all (must be last)
Router::get('/(.*)', [HomeController::class, 'notFound'], 'notfound');
