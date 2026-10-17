<?php

namespace Canyon\Controllers;

use Canyon\Core\View;
use Canyon\Core\Security;

/**
 * Canyon Framework Base Controller
 * Inspired by Laravel Controller and CodeIgniter Controller
 * 
 * Security features:
 * - XSS protection via escape()
 * - Open redirect prevention
 * - CSRF protection
 */
class Controller
{
    /**
     * Render a view with automatic XSS protection
     */
    protected function view($view, $data = [])
    {
        return View::make($view, $data);
    }
    
    /**
     * Send JSON response with security headers
     */
    protected function json($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        
        // Use JSON_HEX_TAG to prevent XSS in JSON responses
        echo json_encode(
            $data, 
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        exit;
    }
    
    /**
     * Redirect to URL with security validation
     * @param string $url The URL to redirect to (relative or absolute)
     * @param int $status HTTP status code (301, 302, 303, 307, 308)
     * @param bool $secure Whether to force secure connection
     */
    protected function redirect($url, $status = 302, $secure = null)
    {
        // Validate and sanitize redirect URL to prevent open redirect
        $sanitizedUrl = Security::sanitizeRedirectUrl($url);
        
        if ($sanitizedUrl === null && $url !== '/') {
            // Log potential security event
            Security::logEvent('invalid_redirect', ['url' => $url], 'warning');
            
            // Don't redirect to invalid URL - go home instead
            $sanitizedUrl = '/';
        }
        
        // Build full URL if needed
        if (!preg_match('/^https?:\/\//i', $sanitizedUrl) && !str_starts_with($sanitizedUrl, '/')) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $sanitizedUrl = "{$protocol}://{$host}{$sanitizedUrl}";
        } elseif (str_starts_with($sanitizedUrl, '//')) {
            // Fix protocol-relative URLs
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $sanitizedUrl = "{$protocol}:{$sanitizedUrl}";
        }
        
        http_response_code($status);
        header('Location: ' . $sanitizedUrl);
        exit;
    }
    
    /**
     * Redirect back to previous page with safety check
     */
    protected function redirectBack($fallback = '/', $status = 302)
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        
        if ($referer && self::isValidRedirectUrl($referer)) {
            return $this->redirect($referer, $status);
        }
        
        return $this->redirect($fallback, $status);
    }
    
    /**
     * Check if URL is valid for redirect
     */
    protected function isValidRedirectUrl(string $url): bool
    {
        // Get current host
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        
        // Parse the URL
        $parsed = parse_url($url);
        
        // Allow relative URLs
        if (!isset($parsed['host'])) {
            return true;
        }
        
        // Check if host matches current host
        return strtolower($parsed['host']) === strtolower($currentHost);
    }
    
    /**
     * Send response with status
     */
    protected function response($content, $status = 200, $contentType = 'text/html; charset=UTF-8')
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        echo $content;
        exit;
    }
    
    /**
     * Escape HTML for safe output
     */
    protected function escape($value)
    {
        return View::escape($value);
    }
    
    /**
     * Generate CSRF token for forms
     */
    protected function csrfField()
    {
        return Security::csrfField();
    }
    
    /**
     * Get CSRF token name for validation
     */
    protected function csrfTokenName()
    {
        return Security::getCsrfTokenName();
    }
    
    /**
     * Validate CSRF token
     */
    protected function verifyCsrf($token)
    {
        return Security::verifyCsrfToken($token);
    }
    
    /**
     * Send error JsonResponse
     */
    protected function errorJson($message, $status = 400)
    {
        return $this->json(['success' => false, 'message' => $message], $status);
    }
    
    /**
     * Send success JsonResponse
     */
    protected function successJson($data = null, $message = 'Success')
    {
        return $this->json([
            'success' => true, 
            'message' => $message,
            'data' => $data
        ], 200);
    }
    
    /**
     * Common error responses
     */
    protected function badRequest($message = 'Bad Request')
    {
        return $this->errorJson($message, 400);
    }
    
    protected function unauthorized($message = 'Unauthorized')
    {
        return $this->errorJson($message, 401);
    }
    
    protected function forbidden($message = 'Forbidden')
    {
        return $this->errorJson($message, 403);
    }
    
    protected function notFound($message = 'Not Found')
    {
        return $this->errorJson($message, 404);
    }
    
    protected function serverError($message = 'Internal Server Error')
    {
        return $this->errorJson($message, 500);
    }
}