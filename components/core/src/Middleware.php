<?php

namespace Canyon\Core;

use Closure;

/**
 * Canyon Framework Middleware System
 * Inspired by Laravel Middleware
 */
abstract class Middleware
{
    /**
     * Handle incoming request
     */
    abstract public function handle($request, Closure $next);
}