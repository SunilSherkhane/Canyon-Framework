<?php

/**
 * Canyon Framework - Routes
 */

use Canyon\Core\Router;
use Canyon\Controllers\HomeController;

// Public routes
Router::get('/', [HomeController::class, 'index'], 'home');
Router::get('/about', [HomeController::class, 'about'], 'about');
Router::get('/contact', [HomeController::class, 'contact'], 'contact');

// API routes
Router::post('/api/data', [HomeController::class, 'apiData'], 'api.data');

// Contact form submission (CSRF protected, rate limited, validated)
Router::post('/api/contact', [HomeController::class, 'submitContact'], 'api.contact');

// 404 handler (catch-all, last resort)
Router::get('/(.*)', [HomeController::class, 'notFound'], 'notfound');