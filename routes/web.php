<?php

/**
 * Canyon Framework - Web Routes
 */

use Canyon\Core\Router;
use Canyon\Controllers\HomeController;
use Canyon\Controllers\TestController;
use Canyon\Controllers\AuthController;

// Public pages
Router::get('/',  [HomeController::class, 'index'], 'home');
Router::get('/about', [HomeController::class, 'about'], 'about');
Router::get('/contact', [HomeController::class, 'contact'], 'contact');
Router::get('/features', [HomeController::class, 'features'], 'features');

// Authentication routes
Router::get('/login',  [AuthController::class, 'loginForm'], 'login');
Router::post('/login',  [AuthController::class, 'login'], 'login.post');
Router::get('/register',  [AuthController::class, 'registerForm'], 'register');
Router::post('/register',  [AuthController::class, 'register'], 'register.post');
Router::post('/logout',  [AuthController::class, 'logout'], 'logout');

// Get current user (API)
Router::get('/api/me',  [AuthController::class, 'me'], 'api.me');

// Developer diagnostics page (dev-gated inside the controller)
Router::get('/test', [TestController::class, 'index'], 'test');

// API endpoints
Router::post('/api/data', [HomeController::class, 'apiData'],  'api.data');
Router::post('/api/contact', [HomeController::class, 'submitContact'], 'api.contact');

// 404 catch-all (must be last)
Router::get('/(.*)', [HomeController::class, 'notFound'], 'notfound');
