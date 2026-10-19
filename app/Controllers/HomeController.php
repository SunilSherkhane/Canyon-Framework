<?php

namespace Canyon\Controllers;

use Canyon\Core\Security;

/**
 * Home Controller - Canyon Framework
 *
 * Handles the public pages and the contact form.
 */
class HomeController extends Controller
{
 // -----------------------------------------------------------------------
 // Public pages
 // -----------------------------------------------------------------------

 public function index(): string
 {
  return $this->view('home.index', [
   'title'  => 'Welcome to Canyon',
   'message'  => 'This is a minimal PHP framework built with Laravel and CodeIgniter inspiration.',
   'csrf_field' => $this->csrfField(),
  ]);
 }

 public function about(): string
 {
  return $this->view('home.about', [
   'title'  => 'About Canyon',
   'description' => 'Canyon is a lightweight PHP framework.',
  ]);
 }

 public function contact(): string
 {
  return $this->view('home.contact', [
   'title'   => 'Contact Us',
   'csrf_token_name' => $this->csrfTokenName(),
  ]);
 }

 public function features(): string
 {
  return $this->view('home.features', [
   'title' => 'Features',
  ]);
 }

 // -----------------------------------------------------------------------
 // API endpoints
 // -----------------------------------------------------------------------

 /**
  * API data endpoint with CSRF protection and input validation.
  */
 public function apiData(): never
 {
  // Verify CSRF token for POST requests
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $token = $_POST[$this->csrfTokenName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

   if (!$this->verifyCsrf($token)) {
    $this->unauthorized('Invalid CSRF token');
   }
  }

  // Validate and clamp pagination parameters
  $page = max(1, (int) ($_GET['page'] ?? 1));
  $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 10)));

  $data = [
   'page'  => $page,
   'per_page' => $perPage,
   'items' => [
    ['id' => 1, 'name' => 'Item One', 'value' => 'First item'],
    ['id' => 2, 'name' => 'Item Two', 'value' => 'Second item'],
    ['id' => 3, 'name' => 'Item Three', 'value' => 'Third item'],
   ],
   'total' => 3,
   'has_more' => false,
  ];

  $this->json($data);
 }

 /**
  * Contact form submission.
  *
  * Security controls:
  * - CSRF token verification (timing-safe comparison)
  * - Per-session rate limiting
  * - Strict server-side input validation
  * - Honeypot field for basic bot defence
  * - Output escaping handled by the view layer
  */
 public function submitContact(): never
 {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   $this->errorJson('Method not allowed', 405);
  }

  // CSRF protection
  $token = $_POST[$this->csrfTokenName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

  if (!$this->verifyCsrf($token)) {
   Security::logEvent('csrf_failure', ['route' => 'api.contact'], 'warning');
   $this->unauthorized('Invalid CSRF token');
  }

  // Rate limiting: 5 submissions per minute per session
  if (!Security::throttle('contact', 5, 60)) {
   Security::logEvent('rate_limit_exceeded', ['route' => 'api.contact'], 'warning');
   Security::rateLimitResponse(60);
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
   $this->json([
    'success' => false,
    'message' => 'Validation failed.',
    'errors' => $errors,
   ], 422);
  }

  // Honeypot: silently accept but ignore if filled (basic bot defence)
  if (!empty($_POST['website'])) {
   $this->successJson(null, 'Message sent.');
  }

  // Log the submission safely (data is escaped on write, never executed)
  Security::logEvent('contact_submitted', [
   'name'   => $name,
   'email'   => $email,
   'message_length' => strlen($message),
  ], 'info');

  $this->successJson([
   'name'  => Security::escape($name),
   'received_at' => date('c'),
  ], 'Thank you for your message. We will be in touch shortly.');
 }

 // -----------------------------------------------------------------------
 // Error pages
 // -----------------------------------------------------------------------

 public function notFound(string $message = 'The page you are looking for does not exist.'): string
 {
  http_response_code(404);
  return $this->view('errors.404', [
   'message' => $message,
  ]);
 }
}
