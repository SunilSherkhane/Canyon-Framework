# 🌊 Canyon Framework

**A minimal, secure PHP framework inspired by Laravel and CodeIgniter — designed for modular web applications.**

> Version: 0.2.0 | PHP: 8.1+ | License: MIT

---

## Quick Start

```bash
# Clone / pull
clones from https://github.com/SunilSherkhane/Canyon-Framework.git

# Start server immediately
./canyon serve     # default port 8000
# or manually
php -S 127.0.0.1:8000 -t public
```

Open `http://localhost:8000/` — the framework boots with security headers, session hardening, and CSRF protection already active.

---

## What's Inside (Modular — Build Anything)

| Module | Purpose | Use When... |
|---|---|---|
| **Router** (`app/Core/Router.php`) | HTTP method allow-list, URI sanitization, parameterized routes (`{id}`), named routes, catch-all, middleware groups | Defining URLs |
| **Controllers** (`app/Controllers/`) | Base `Controller` with `view()`, `json()`, `redirect()`, CSRF helpers, DB access | Handling requests |
| **Views** (`app/Core/View.php`) | Auto-escaping (`htmlspecialchars()`), path-traversal protection, `View::raw()` escape hatch | Rendering HTML |
| **Security** (`app/Core/Security.php`) | CSRF, rate limiting, host header validation, output escaping, input sanitization, CSP, logging | Any security need |
| **Middleware** (`app/Core/Middleware.php`) | Pipeline pattern — abstract `handle($request, Closure $next)` | Cross-cutting concerns |
| **Database** (`app/Core/Database.php`) | PDO wrapper: `select()`, `insert()`, `update()`, `delete()`, `statement()`, transactions | Data persistence |
| **Container** (`app/Core/Container.php`) | Laravel-style DI: `bind()`, `singleton()`, `resolve()`, `when()` | Dependency injection |
| **Application** (`app/Core/Application.php`) | Singleton for shared view data (`csrf_token`, `app_name`, etc.) | Global state |
| **CLI** (`canyon`) | `serve`, `routes`, `test`, `clear-cache`, `info` | Development / ops |
| **Tests** (`tests/` + `phpunit.xml`) | PHPUnit setup + bootstrap | Quality assurance |

---

## Project Layout

```
canyon/
├── public/              # Web entry: .htaccess + index.php
│   ├── index.php        # Bootstraps env, session, host check, router
│   └── .htaccess        # Rewrite rules + security headers
├── app/
│   ├── Core/            # Router, View, Security, Database, Middleware, Container, Application
│   ├── Controllers/     # HomeController, TestController, your controllers
│   └── Helpers/         # env(), base_url(), asset(), storage_path(), route_path()
├── routes/
│   └── web.php          # Route definitions: Router::get/post/put/delete/patch/group
├── resources/views/     # PHP templates (auto-escaping, path-traversal protected)
│   ├── home/            # index.php, about.php, contact.php, features.php
│   ├── errors/          # 404.php
│   └── test/            # index.php (debug only when APP_DEBUG=true)
├── config/
│   └── app.php          # App config (name, debug, URL, timezone, DB)
├── storage/             # Sessions, logs, cache (outside web root)
├── .env                 # Environment (NOT committed — see .env.example)
├── .env.example         # Template for .env
├── composer.json        # PSR-4 autoload + bin: canyon
├── canyon               # Executable CLI
└── tests/               # PHPUnit bootstrap + Security/Router test dirs
```

---

## How to Use — Build on Top of It

### 1. Add a Route

Edit `routes/web.php`:

```php
use Canyon\Controllers\HomeController;
use Canyon\Core\Router;

Router::get('/', [HomeController::class, 'index'], 'home');
Router::get('/posts/{id}', [PostController::class, 'show'], 'post.show');

// Group with middleware
Router::group('App\Core\Middleware\AuthMiddleware', function () {
    Router::get('/dashboard', [DashboardController::class, 'index']);
});
```

### 2. Create a Controller

```php
<?php
namespace Canyon\Controllers;

class PostController extends Controller
{
    public function show(string $id): string
    {
        $post = $this->db()->select('posts', ['*'], ['id' => $id]);
        return $this->view('posts.show', ['post' => $post[0] ?? null]);
    }

    public function list(): string
    {
        $posts = $this->db()->select('posts', ['*'], [], 20);
        return $this->view('posts.list', ['posts' => $posts]);
    }
}
```

### 3. Create a View

`resources/views/posts/show.php`:

```php
<!DOCTYPE html>
<html>
<head><title><?= $title ?? 'Post' ?></title></head>
<body>
  <h1><?= $post['title'] ?? 'Untitled' ?></h1>
  <p><?= $post['body'] ?? '' ?></p>
  <!-- Data is auto-escaped; use $raw for raw HTML only when you fully trust it -->
</body>
</html>
```

### 4. Use the Database

```php
// Direct PDO
$db = new \Canyon\Core\Database();
$row = $db->select('users', ['*'], ['email' => 'user@test.com']);

// In a controller (convenient)
$this->db()->insert('users', ['name' => 'Alice', 'email' => 'alice@test.com']);
$this->db()->update('users', ['name' => 'Alice Updated'], ['id' => 1]);
$this->db()->delete('users', ['id' => 5]);

// Transactions
$this->db()->transaction(function ($db) {
    $db->insert('orders', [...]);
    $db->insert('order_items', [...]);
    return true;
});
```

### 5. Use Middleware (Pipeline)

Create `app/Core/Middleware/AuthMiddleware.php`:

```php
<?php
namespace Canyon\Core;

abstract class Middleware
{
    abstract public function handle($request, \Closure $next);
}
```

Then create `app/Middleware/AuthMiddleware.php` (or wherever you prefer):

```php
<?php
namespace App\Middleware;

use Canyon\Core\Middleware;

class AuthMiddleware extends Middleware
{
    public function handle($request, \Closure $next)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        return $next(); // Continue to controller
    }
}
```

### 6. Use the Container (Dependency Injection)

```php
use Canyon\Core\Container;

// Bind interface to concrete
Container::bind('App\Contracts\Mailer', 'App\Services\SmtpMailer');

// Singleton
Container::singleton('App\Services\Logger', 'App\Services\FileLogger');

// Resolve
$logger = Container::resolve('App\Services\Logger');

// Contextual (only for specific controllers)
Container::when('App\Controllers\OrderController', 'App\Contracts\PaymentGateway', 'App\Services\StripeGateway');
```

### 7. Use Security Features

```php
// CSRF field in form
<?= \Canyon\Core\Security::csrfField() ?>

// Verify in controller
if (!$this->verifyCsrf($_POST['_canyon_csrf_token'] ?? null)) {
    $this->unauthorized('Invalid CSRF token');
}

// Rate limit
if (!\Canyon\Core\Security::throttle('contact', 5, 60)) {
    \Canyon\Core\Security::rateLimitResponse(60);
}

// Sanitize input
$clean = \Canyon\Core\Security::sanitizeInput($_POST, ['email' => 'email', 'age' => 'int']);

// Escape for HTML
$safe = \Canyon\Core\Security::escape('<script>');
```

---

## Security Defaults (Already Active)

Every request gets:

- **CSRF protection** (session tokens + `hash_equals()` verification + 1-hour expiry)
- **Host header validation** (configurable allow-list with wildcard subdomains; port-stripped; bad hosts logged at `storage/logs/security.log`)
- **URI sanitization** (null bytes/reject control chars; length capped at 2048; `%00`-decoded for visibility)
- **Session hardening** (`HttpOnly`, `SameSite=Lax`, `use_strict_mode`, `use_only_cookies`, `CANYONSESSID`, storage outside web root)
- **Security headers** (CSP `default-src 'self'`, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy, Permissions-Policy, HSTS when HTTPS)
- **CSP in meta tags** aligned with HTTP headers (no `'unsafe-inline'` on `script-src`)
- **Rate limiting** (session-based; configurable attempts/decay; JSON 429 response)
- **Open redirect prevention** (`sanitizeRedirectUrl()` rejects `//evil.com`, absolute URLs, protocol-relative URLs)
- **Output escaping** (view layer auto-escapes all string data via `htmlspecialchars()`)
- **JSON protection** (`JSON_HEX_*` flags on all JSON responses)
- **Error sanitization** (no stack traces in production; sanitized HTML response)
- **.htaccess** (blocks `.env`, `storage/`, `logs/`, directory listing, PHP execution in uploads, MIME sniffing disabled, ETag disabled, compression enabled)

---

## CLI Commands

```bash
./canyon              # Shows help
./canyon info         # Framework version, PHP version, env
./canyon routes       # List all registered routes
./canyon serve        # Start dev server (port 8000 by default)
./canyon serve 8080 0.0.0.0
./canyon test         # Run framework tests
./canyon clear-cache  # Clear storage/cache
```

---

## Testing

```bash
# Install PHPUnit if needed (composer require --dev phpunit/phpunit)
phpunit                # Runs phpunit.xml suite
phpunit --testsuite="Canyon Security Suite"
```

Bootstrap (`tests/bootstrap.php`) sets up the framework environment, defines paths, and initializes `Application::init()`.

---

## Configuration (`.env` / `config/app.php`)

Copy `.env.example` → `.env` and edit:

```bash
cp .env.example .env
```

Key variables:

| Variable | Default | Purpose |
|---|---|---|
| `APP_NAME` | Canyon Framework | App name |
| `APP_ENV` | production | production / development / testing |
| `APP_DEBUG` | false | Show errors / display_errors |
| `APP_URL` | http://localhost | Base URL for links |
| `TIMEZONE` | UTC | PHP timezone |
| `ALLOWED_HOSTS` | localhost,127.0.0.1 | Host header validation (comma-separated; wildcard `*.example.com` allowed) |
| `DB_CONNECTION` | mysql | Database driver |
| `DB_HOST` `/`DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | 127.0.0.1 / 3306 / canyon / root / (empty) | Database |
| `CORS_ALLOWED_ORIGINS` | (empty) | Allowed CORS origins |
| `HSTS_ENABLED` / `HSTS_MAX_AGE` | false / 31536000 | HSTS header |

---

## Modular Build Pattern

To create anything from this framework:

1. **Define data** (`resources/views/` template or `Database::select()` query)
2. **Create controller** (`app/Controllers/YourController.php` extends `Controller`)
3. **Register route** (`routes/web.php` with `Router::get/post` or group)
4. **Add middleware if needed** (`Router::group()` or per-route array)
5. **Optionally use Container** (`Container::bind()` / `Container::singleton()`) for dependency injection
6. **Use Security helpers** (`csrfField()`, `sanitizeInput()`, `throttle()`, `escape()`) on every form/endpoint
7. **Optional: create Model** — extend `Canyon\Core\Model` (if you build one) or use `Database::select()` directly

The framework is intentionally minimal — you bring the models, services, and business logic; it provides security, routing, rendering, database access, DI, and CLI.

---

## Backup

A complete backup archive is saved at:

```
/home/sunilsherkhane/canyon-framework-backup.tar.gz
```

Also pushed to:

```
https://github.com/SunilSherkhane/Canyon-Framework
```

---

## Credits & Notes

Built for modular, secure PHP applications. Inspired by Laravel (router, middleware pipeline, container, security patterns) and CodeIgniter (controller/view separation, .htaccess security, session management, CLI). All security patterns verified against real framework bugs — see `app/Core/Security.php`, `public/index.php`, `public/.htaccess`, `app/Controllers/Controller.php`.
