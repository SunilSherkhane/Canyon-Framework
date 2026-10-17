<?php
/**
 * Home Controller - Canyon Framework
 *
 * Demonstrating minimal MVC pattern with security features
 */

namespace Canyon\Controllers;

use Canyon\Controllers\Controller;
use Canyon\Core\Security;

class HomeController extends Controller
{
    public function index()
    {
        return $this->view('home.index', [
            'title' => 'Welcome to Canyon',
            'message' => 'This is a minimal PHP framework built with Laravel and CodeIgniter inspiration.',
            'csrf_field' => $this->csrfField(),
        ]);
    }

    public function about()
    {
        return $this->view('home.about', [
            'title' => 'About Canyon',
            'description' => 'Canyon is a lightweight PHP framework.',
        ]);
    }

    public function contact()
    {
        return $this->view('home.contact', [
            'title' => 'Contact Us',
            'csrf_token_name' => $this->csrfTokenName(),
        ]);
    }

    /**
     * API data endpoint with CSRF protection and input validation
     */
    public function apiData()
    {
        // Verify CSRF token for POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST[$this->csrfTokenName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

            if (!$token || !$this->verifyCsrf($token)) {
                return $this->unauthorized('Invalid CSRF token');
            }
        }

        // Validate input with strict bounds
        $page = (int) ($_GET['page'] ?? 1);
        $perPage = (int) ($_GET['per_page'] ?? 10);

        // Limit maximum values to prevent DoS
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        // Generate response data
        $data = [
            'page' => $page,
            'per_page' => $perPage,
            'items' => [
                ['id' => 1, 'name' => 'Item One', 'value' => 'First item'],
                ['id' => 2, 'name' => 'Item Two', 'value' => 'Second item'],
                ['id' => 3, 'name' => 'Item Three', 'value' => 'Third item'],
            ],
            'total' => 3,
            'has_more' => false,
        ];

        return $this->json($data);
    }

    /**
     * Contact form submission.
     *
     * Security controls applied here:
     * - CSRF token verification (timing-safe comparison)
     * - Per-session rate limiting
     * - Strict server-side input validation (never trust the HTML pattern attr)
     * - Output escaping is handled by the view layer
     */
    public function submitContact()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->errorJson('Method not allowed', 405);
        }

        // CSRF protection
        $token = $_POST[$this->csrfTokenName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!$token || !$this->verifyCsrf($token)) {
            Security::logEvent('csrf_failure', ['route' => 'api.contact'], 'warning');
            return $this->unauthorized('Invalid CSRF token');
        }

        // Rate limiting: 5 submissions per minute per session
        if (!Security::throttle('contact', 5, 60)) {
            Security::logEvent('rate_limit_exceeded', ['route' => 'api.contact'], 'warning');
            return Security::rateLimitResponse(60);
        }

        // Server-side validation
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        $errors = [];

        if ($name === '' || strlen($name) > 100 || !preg_match("/^[a-zA-Z\s'-]+$/u", $name)) {
            $errors['name'] = 'Please enter a valid name (letters, spaces, hyphens and apostrophes only, max 100 characters).';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if ($message === '' || strlen($message) > 2000) {
            $errors['message'] = 'Please enter a message between 1 and 2000 characters.';
        }

        if (!empty($errors)) {
            return $this->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        // Honeypot: silently accept but ignore if filled (basic bot defence)
        if (!empty($_POST['website'])) {
            return $this->successJson(null, 'Message sent.');
        }

        // Persist the submission safely (escaped on write, never executed)
        Security::logEvent('contact_submitted', [
            'name' => $name,
            'email' => $email,
            'message_length' => strlen($message),
        ], 'info');

        return $this->successJson([
            'name' => Security::escape($name),
            'received_at' => date('c'),
        ], 'Thank you for your message. We will be in touch shortly.');
    }

    public function notFound($message = 'The page you are looking for does not exist.')
    {
        http_response_code(404);
        return $this->view('errors.404', [
            'message' => $message,
        ]);
    }
}