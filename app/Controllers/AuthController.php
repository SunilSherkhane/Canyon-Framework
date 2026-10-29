<?php

namespace Canyon\Controllers;

use Canyon\Core\Controller;
use Canyon\Core\Security;
use Canyon\Core\Session;
use Canyon\Models\User;

/**
 * Canyon Framework - Auth Controller
 *
 * Handles login, registration, and logout with password hashing,
 * session regeneration, rate limiting, and CSRF protection.
 */
class AuthController extends Controller
{
  // -----------------------------------------------------------------------
  // Show login form
  // -----------------------------------------------------------------------

  public function loginForm(): string
  {
    if (Security::getSession('canyon_user_id')) {
      return $this->redirect('/');
    }
    return $this->view('auth.login', [
      'title'           => 'Login',
      'csrf_token_name' => $this->csrfTokenName(),
      'csrf_token'      => $this->csrfField(),
    ]);
  }

  // -----------------------------------------------------------------------
  // Process login
  // -----------------------------------------------------------------------

  public function login(): never
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $this->redirect('/login');
    }

    // CSRF protection
    $token = $_POST[$this->csrfTokenName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$this->verifyCsrf($token)) {
      Security::logEvent('csrf_failure', ['route' => 'auth.login'], 'warning');
      $this->unauthorized('Invalid CSRF token');
    }

    // Rate limiting: 5 attempts per minute per IP
    if (!Security::throttleIp('login', 5, 60)) {
      Security::logEvent('rate_limit_exceeded', ['route' => 'auth.login'], 'warning');
      $this->json(['success' => false, 'message' => 'Too many login attempts. Try again later.'], 429);
    }

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
      $this->json(['success' => false, 'message' => 'Email and password are required.'], 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->json(['success' => false, 'message' => 'Invalid email format.'], 422);
    }

    $user = User::findByEmail($email);
    if (!$user || !User::verifyPassword($password, $user['password'])) {
      Security::logEvent('login_failed', ['email' => $email], 'warning');
      $this->json(['success' => false, 'message' => 'Invalid credentials.'], 401);
    }

    // Rehash if needed (algorithm upgrade)
    if (User::needsRehash($user['password'])) {
      User::updatePassword($user['id'], $password);
    }

    // Regenerate session ID to prevent fixation
    Security::regenerateSession(true);

    // Store user in session
    Security::setSession('canyon_user_id', (int) $user['id']);
    Security::setSession('canyon_user_name', $user['name']);
    Security::setSession('canyon_user_email', $user['email']);
    Security::setSession('canyon_user_roles', $user['roles'] ?? ['user']);

    Security::logEvent('login_success', ['user_id' => $user['id'], 'email' => $email], 'info');

    $this->json([
      'success' => true,
      'message' => 'Login successful.',
      'user'    => User::toPublic($user),
    ]);
  }

  // -----------------------------------------------------------------------
  // Show registration form
  // -----------------------------------------------------------------------

  public function registerForm(): string
  {
    if (Security::getSession('canyon_user_id')) {
      return $this->redirect('/');
    }
    return $this->view('auth.register', [
      'title'           => 'Register',
      'csrf_token_name' => $this->csrfTokenName(),
      'csrf_token'      => $this->csrfField(),
    ]);
  }

  // -----------------------------------------------------------------------
  // Process registration
  // -----------------------------------------------------------------------

  public function register(): never
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $this->redirect('/register');
    }

    // CSRF protection
    $token = $_POST[$this->csrfTokenName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$this->verifyCsrf($token)) {
      Security::logEvent('csrf_failure', ['route' => 'auth.register'], 'warning');
      $this->unauthorized('Invalid CSRF token');
    }

    // Rate limiting: 3 registrations per hour per IP
    if (!Security::throttleIp('register', 3, 3600)) {
      Security::logEvent('rate_limit_exceeded', ['route' => 'auth.register'], 'warning');
      $this->json(['success' => false, 'message' => 'Too many registrations. Try again later.'], 429);
    }

    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirmation'] ?? '');

    $errors = [];

    if ($name === '' || strlen($name) < 2 || strlen($name) > 100) {
      $errors['name'] = 'Name must be 2-100 characters.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
      $errors['email'] = 'Invalid email address.';
    }

    if (strlen($password) < 8) {
      $errors['password'] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirm) {
      $errors['password_confirmation'] = 'Passwords do not match.';
    }

    if (User::findByEmail($email)) {
      $errors['email'] = 'Email is already registered.';
    }

    if (!empty($errors)) {
      $this->json([
        'success' => false,
        'message' => 'Validation failed.',
        'errors'  => $errors,
      ], 422);
    }

    $userId = User::create($name, $email, $password, ['user']);

    Security::logEvent('user_registered', ['user_id' => $userId, 'email' => $email], 'info');

    // Auto-login after registration
    Security::regenerateSession(true);
    Security::setSession('canyon_user_id', $userId);
    Security::setSession('canyon_user_name', $name);
    Security::setSession('canyon_user_email', $email);
    Security::setSession('canyon_user_roles', ['user']);

    $this->json([
      'success' => true,
      'message' => 'Registration successful.',
      'user'    => User::toPublic([
        'id'    => $userId,
        'name'  => $name,
        'email' => $email,
        'roles' => ['user'],
      ]),
    ]);
  }

  // -----------------------------------------------------------------------
  // Logout
  // -----------------------------------------------------------------------

  public function logout(): never
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $this->redirect('/login');
    }

    // CSRF protection
    $token = $_POST[$this->csrfTokenName()] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$this->verifyCsrf($token)) {
      Security::logEvent('csrf_failure', ['route' => 'auth.logout'], 'warning');
      $this->unauthorized('Invalid CSRF token');
    }

    $userId = Security::getSession('canyon_user_id');

    Security::killSession();

    Security::logEvent('logout', ['user_id' => $userId], 'info');

    $this->json([
      'success' => true,
      'message' => 'Logged out successfully.',
    ]);
  }

  // -----------------------------------------------------------------------
  // Get current user
  // -----------------------------------------------------------------------

  public function me(): never
  {
    $userId = Security::getSession('canyon_user_id');
    if (!$userId) {
      $this->json(['success' => false, 'message' => 'Not authenticated.'], 401);
    }

    $user = User::findById((int) $userId);
    if (!$user) {
      Security::killSession();
      $this->json(['success' => false, 'message' => 'User not found.'], 401);
    }

    $this->json([
      'success' => true,
      'user'    => User::toPublic($user),
    ]);
  }
}
