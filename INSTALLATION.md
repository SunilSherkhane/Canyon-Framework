# 🏗️ Canyon Framework — Installation & Configuration Guide

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installation Methods](#installation-methods)
3. [Web Server Configuration](#web-server-configuration)
4. [Environment Configuration](#environment-configuration)
5. [Database Setup](#database-setup)
6. [Development Server](#development-server)
7. [Verification](#verification)
8. [Basic Application Setup](#basic-application-setup)
9. [Production Deployment](#production-deployment)
10. [Configuration Reference](#configuration-reference)
11. [Troubleshooting](#troubleshooting)

---

## Prerequisites

Before installing Canyon Framework, ensure your system meets these requirements:

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| **PHP** | 8.1 | 8.2+ |
| **Composer** | 2.5+ | Latest |
| **Web Server** | Apache 2.4 / Nginx 1.18+ | Latest stable |
| **Database** | MySQL 5.7+, MariaDB 10.3+, SQLite 3 | MySQL 8.0+ |
| **PHP Extensions** | `pdo`, `mbstring`, `json`, `openssl`, `curl` | `gd`, `imagick`, `opcache` |

### Required PHP Extensions

```bash
# Ubuntu/Debian
sudo apt install php8.2 php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-opcache

# CentOS/RHEL
sudo dnf install php php-mysqlnd php-mbstring php-xml php-curl php-zip php-gd php-opcache

#macOS (Homebrew)
brew install php@8.2
```

Verify installation:

```bash
php -v
php -m | grep -E "(pdo|mbstring|json|openssl|curl)"
```

---

## Installation Methods

### Method 1: Clone from GitHub

```bash
# Clone the repository
git clone https://github.com/SunilSherkhane/Canyon-Framework.git my-project
cd my-project

# Install dependencies
composer install --no-dev --optimize-autoloader
```

### Method 2: Download ZIP

1. Visit https://github.com/SunilSherkhane/Canyon-Framework/releases
2. Download the latest source code ZIP
3. Extract to your project directory
4. Install dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

### Method 3: Composer Package (When available)

```bash
composer create-project canyon/framework my-project
cd my-project
```

---

## Web Server Configuration

### Apache Configuration

#### 1. Enable mod_rewrite

```bash
# Ubuntu/Debian
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod expires

# CentOS/RHEL/Fedora
sudo dnf install mod_rewrite
sudo systemctl restart httpd
```

#### 2. Create VirtualHost

Create `/etc/apache2/sites-available/canyon.conf` (Ubuntu/Debian) or `/etc/httpd/conf.d/canyon.conf` (CentOS):

```apache
<VirtualHost *:80>
    ServerName myproject.local
    ServerAlias www.myproject.local
    DocumentRoot /var/www/my-project/public

    <Directory /var/www/my-project/public>
        Options -Indexes +FollowSymLinks +MultiViews
        AllowOverride All
        Require all granted

        # Security: Block .env and sensitive files
        <Files ".env">
            Require all denied
        </Files>

        <Files ~ "^.*\.php$">
            Order allow,deny
            Deny from all
        </Files>
    </Directory>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/canyon-error.log
    CustomLog ${APACHE_LOG_DIR}/canyon-access.log combined

    # Optional: HTTPS redirect
    # RewriteEngine On
    # RewriteCond %{HTTPS} off
    # RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>
```

#### 3. Enable VirtualHost

```bash
# Ubuntu/Debian
sudo a2ensite canyon.conf
sudo a2enconf security headers expires
sudo systemctl reload apache2

# CentOS/RHEL
sudo systemctl restart httpd
```

### Nginx Configuration

Create `/etc/nginx/sites-available/canyon` (Ubuntu/Debian) or `/etc/nginx/conf.d/canyon.conf` (CentOS/RHEL):

```nginx
server {
    listen 80;
    server_name myproject.local www.myproject.local;
    root /var/www/my-project/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Block access to sensitive files
    location ~ /\.env {
        deny all;
        return 404;
    }

    location ~ ^/(app|config|storage|routes|resources|vendor|plugins)/ {
        deny all;
        return 404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Security: Don't allow .php files in uploads
        fastcgi_param PHP_VALUE "open_basedir=/var/www/my-project";
    }

    # Static assets with caching
    location ~* \.(css|js|png|jpg|jpeg|gif|svg|webp|avif|ico|woff2?|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Main entry point
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Health check endpoint
    location /health {
        return 200 "OK";
        add_header Content-Type text/plain;
    }
}
```

Enable and reload:

```bash
# Ubuntu/Debian
sudo ln -s /etc/nginx/sites-available/canyon /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# CentOS/RHEL
sudo systemctl reload nginx
```

---

## Environment Configuration

### Create .env File

```bash
cp .env.example .env
chmod 600 .env  # Restrict permissions
```

### Complete .env Configuration

```ini
# ─────────────────────────────────────────────────────────────────────────────
# APPLICATION SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

# Application name (used in emails, pages)
APP_NAME="Canyon App"

# Environment: development | production | staging
APP_ENV=development

# Debug mode: true | false (NEVER enable in production)
APP_DEBUG=true

# Base URL (use trailing slash for subdirectories)
APP_URL=http://localhost:8000

# ─────────────────────────────────────────────────────────────────────────────
# SECURITY SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

# Application key (MUST be 32 bytes, base64 encoded)
# Generate with: php -r "echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
APP_KEY=base64:xYz123abc456def789ghi012jkl345mno678pqr901stu234vwx567yz890

# HTTPS/TLS settings
HSTS_ENABLED=true
HSTS_MAX_AGE=31536000
HSTS_INCLUDE_SUBDOMAINS=true

# Allowed hosts (security measure against host header attacks)
ALLOWED_HOSTS=localhost,127.0.0.1,myproject.local,*.myproject.local

# CORS settings (comma-separated origins)
CORS_ALLOWED_ORIGINS=https://example.com,https://api.example.com

# RPC API keys (comma-separated, for JSON-RPC endpoints)
RPC_API_KEYS=internal-api-key-1,internal-api-key-2

# Rate limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_REQUESTS=60
RATE_LIMIT_WINDOW=60

# Session settings
SESSION_TIMEOUT=120
SESSION_COOKIE_SECURE=false  # Set true for HTTPS

# ─────────────────────────────────────────────────────────────────────────────
# DATABASE SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

# Database connection
DB_CONNECTION=mysql  # mysql | sqlite | pgsql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=canyon
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

# SQLite configuration (alternative)
# DB_CONNECTION=sqlite
# DB_DATABASE=/var/www/my-project/database.sqlite

# Connection pooling (MySQL only)
DB_PERSISTENT=false

# ─────────────────────────────────────────────────────────────────────────────
# CACHE SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

# Cache driver: file | redis | memcached
CACHE_DRIVER=file
CACHE_TTL=3600

# Redis configuration (if using redis cache)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0

# ─────────────────────────────────────────────────────────────────────────────
# QUEUE SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

# Queue driver: file | redis | database
QUEUE_DRIVER=file

# ─────────────────────────────────────────────────────────────────────────────
# MAIL SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your@email.com
MAIL_PASSWORD=your-mail-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"

# SMTP alternative (using PHP mail)
# MAIL_MAILER=sendmail
# MAIL_HOST=localhost

# ─────────────────────────────────────────────────────────────────────────────
# LOGGING SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

# Log level: debug | info | notice | warning | error | critical | alert | emergency
LOG_LEVEL=debug

# Log channel: single | daily | slack | stderr
LOG_CHANNEL=daily

# Log file path
LOG_PATH=/var/log/canyon

# ─────────────────────────────────────────────────────────────────────────────
# PERFORMANCE SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

# OPCache settings
OPCACHE_ENABLED=true
OPCACHE_MEMORY_CONSUMPTION=128
OPCACHE_MAX_ACCELERATED_FILES=4000
OPCACHE_REVALIDATE_FREQ=60
OPCACHE_FAST_SHUTDOWN=true

# Asset optimization
ASSET_DEBUG=false
ASSET_COMPRESS=true
ASSET_COMBINE=false

# Image optimization
IMAGE_OPTIMIZATION_ENABLED=true
IMAGE_OPTIMIZATION_QUALITY=85
IMAGE_GENERATE_WEBP=true
IMAGE_GENERATE AVIF=false
```

### Generate Application Key

The application key is required for encryption, session signing, and HMAC tokens:

```bash
# Generate a secure 32-byte base64 key
php -r "echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"

# Alternative method using Canyon CLI
./canyon key:generate
```

---

## Database Setup

### MySQL/MariaDB Setup

#### 1. Create Database User

```sql
-- Connect as root
CREATE DATABASE canyon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'canyon_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON canyon.* TO 'canyon_user'@'localhost';
FLUSH PRIVILEGES;
```

#### 2. Run Migrations

```bash
# Run all migrations
./canyon migrate

# Show migration status
./canyon migrate:status

# Rollback last migration
./canyon migrate:rollback

# Reset all migrations (drop tables)
./canyon migrate:reset
```

### SQLite Setup (Quick Start)

```bash
# Create database file
touch database.sqlite
chmod 644 database.sqlite

# Run migrations (auto-creates tables)
./canyon migrate
```

### Database Optimization

```bash
# Clean up database (sessions, cache, logs, etc.)
php command optimize

# Optimize all database tables
php command db:optimize

# Get database size report
php command db:size
```

---

## Development Server

### Using Canyon CLI

```bash
# Start development server (default port 8000)
./canyon serve

# Custom port and host
./canyon serve --port=3000 --host=192.168.1.100

# With HTTPS (development only)
./canyon serve --https
```

### Using PHP Built-in Server

```bash
# Start on port 8000
php -S 127.0.0.1:8000 -t public

# With router script (for path-based routing)
php -S 127.0.0.1:8000 public/index.php
```

---

## Verification

### Check Core Functionality

1. **Welcome Page** - Visit http://localhost:8000/
2. **Security Headers** - Check browser DevTools (Network tab)
3. **Session Cookie** - Verify `CANYONSESSID` is set with `HttpOnly` and `SameSite=Lax`
4. **CSRF Token** - Inspect forms for hidden `_token` field

### Run Security Test Page

**Only available in development mode:**

```
http://localhost:8000/test
```

This page verifies:
- CSRF protection
- Session security
- XSS prevention
- Input sanitization
- Header security
- Host header protection

### Run Tests

```bash
# Run all tests
./canyon test

# Run specific test suite
./vendor/bin/phpunit --filter Security

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/
```

---

## Basic Application Setup

### 1. Create Your First Controller

```php
<?php
// app/Controllers/HomeController.php
namespace Canyon\Controllers;

class HomeController extends Controller
{
    public function index(): string
    {
        return $this->view('home.index', [
            'title' => 'Welcome',
            'message' => 'Hello from Canyon Framework!'
        ]);
    }

    public function error(): string
    {
        return $this->view('errors.404', [], 404);
    }
}
```

### 2. Create Your First View

```php
<?php // resources/views/home/index.php ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - <?= env('APP_NAME') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header>
        <h1><?= htmlspecialchars($message) ?></h1>
    </header>

    <main>
        <p>Welcome to your Canyon Framework application!</p>
    </main>

    <?php echo csrfField(); ?>

    <script src="/assets/js/app.js"></script>
</body>
</html>
```

### 3. Define Routes

```php
<?php // routes/web.php
use Canyon\Core\Router;
use Canyon\Controllers\HomeController;

// Home route
Router::get('/', [HomeController::class, 'index'], 'home');

// 404 handler (must be last)
Router::any('{path}', [HomeController::class, 'error'], 'error', false);
```

### 4. Verify Routes

```bash
./canyon routes
```

Expected output:
```
Available Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS, ANY
┌────────────┬──────────────────┬────────────────────┬────────────┐
│ Method     │ Pattern          │ Handler            │ Name       │
├────────────┼──────────────────┼────────────────────┼────────────┤
│ GET        │ /                │ HomeController     │ home       │
│ ANY        │ {path}           │ HomeController     │ error      │
└────────────┴──────────────────┴────────────────────┴────────────┘
```

---

## Production Deployment

### Pre-deployment Checklist

- [ ] Set `APP_ENV=production` in `.env`
- [ ] Set `APP_DEBUG=false` in `.env`
- [ ] Rotate and secure `APP_KEY`
- [ ] Configure `ALLOWED_HOSTS` with your domain(s)
- [ ] Set up SSL/TLS certificate (Let's Encrypt recommended)
- [ ] Enable HSTS after SSL is verified
- [ ] Run `./canyon migrate`
- [ ] Set up cron jobs for scheduled tasks
- [ ] Configure log rotation
- [ ] Harden file permissions
- [ ] Remove `/test` route visibility

### Cron Setup for Production

Create a systemd timer or crontab entry:

```bash
# Edit crontab
crontab -e

# Add entry for Canyon cron (run every minute)
* * * * * cd /var/www/my-project && php canyon cron >> /dev/null 2>&1

# Optimized cron (hourly database cleanup)
0 * * * * cd /var/www/my-project && php command db:clean
```

### PHP-FPM Pool Configuration

Create `/etc/php/8.2/fpm/pool.d/canyon.conf`:

```ini
[canyon]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm-canyon.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500

# Security
php_admin_value[open_basedir] = /var/www/my-project
php_admin_value[upload_tmp_dir] = /var/www/my-project/storage/tmp
```

Reload PHP-FPM:

```bash
sudo systemctl reload php8.2-fpm
```

---

## Configuration Reference

### Core Modules

| Module | Class | Purpose |
|--------|-------|---------|
| Container | `Container` | Dependency injection with contextual bindings |
| Application | `Application` | Application state and configuration |
| Router | `Router` | HTTP request routing with middleware |
| Middleware | `Middleware` | Request/response middleware pipeline |
| Validation | `Validation` | Input validation with rules |
| Cache | `Cache` | Caching with multiple drivers |
| Queue | `Queue` | Background job processing |
| Database | `Database` | PDO wrapper with query builder |
| View | `View` | Template rendering with auto-escaping |
| EventDispatcher | `EventDispatcher` | Event system for plugins |
| Plugin | `Plugin` | Modular plugin architecture |
| Settings | `Settings` | Persistent application settings |
| Theme | `Themes` | Theme management system |
| I18n | `I18n` | Internationalization and localization |
| RPC | `RPC` | JSON-RPC 2.0 API server |

### Performance Modules

| Module | Class | Purpose |
|--------|-------|---------|
| AssetOptimizer | `AssetOptimizer` | CSS/JS minification, compression, CDN |
| ImageOptimizer | `ImageOptimizer` | Image optimization, WebP/AVIF, responsive |
| DatabaseOptimizer | `DatabaseOptimizer` | DB cleanup, optimization, health |
| Opcache | `Opcache` | OPcache monitoring and management |
| Heartbeat | `Heartbeat` | Periodic task scheduling |
| PreloadCrawler | `PreloadCrawler` | Cache warming and sitemap crawling |

### Security Modules

| Module | Class | Purpose |
|--------|-------|---------|
| Security | `Security` | CSRF, XSS, rate limiting, sanitization |
| HMAC | `Hmac` | Signed URLs, data integrity, action tokens |
| AccessControl | `AccessControl` | Role-based permissions |
| Permissions | `Permissions` | Entity-level access control |
| PageOwnership | `PageOwnership` | Content ownership hierarchy |
| Menu | `Menu` | Navigation with access control |

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| **500 Internal Server Error** | Check `storage/logs/php_errors.log`. Look for syntax errors or missing extensions. |
| **404 on routes** | Ensure `.htaccess` is in `public/`. Verify `AllowOverride All` is set. |
| **Session not working** | Ensure `storage/sessions/` is writable (chmod 750). Check session.save_path in php.ini. |
| **Database connection failed** | Verify DB credentials in `.env`. Check if MySQL is running (`systemctl status mysql`). |
| **CSRF token mismatch** | Check system time is correct. Ensure session cookie is not blocked. |
| **Images not optimizing** | Verify `gd` or `imagick` extension is installed. Run `php -m \| grep -E `(gd\|imagick)` |
| **Cache not working** | Ensure `storage/cache/` is writable. Check disk space. |
| **Queue not processing** | Run `./canyon queue work` in background. Check `storage/queue/` permissions. |
| **OPcache not enabled** | Verify extension is loaded: `php -m \| grep opcache` |
| **CORS errors** | Configure `CORS_ALLOWED_ORIGINS` in `.env` with your domain. |

### Debug Mode

Enable debug mode for detailed error pages:

```bash
# Enable
sed -i 's/APP_DEBUG=false/APP_DEBUG=true/' .env

# Disable (critical for production)
sed -i 's/APP_DEBUG=true/APP_DEBUG=false/' .env
```

### Log Commands

```bash
# View recent errors
tail -f storage/logs/php_errors.log

# View access logs
tail -f storage/logs/access.log

# Clear log file
> storage/logs/php_errors.log
```

---

## Support

- **Documentation**: See README.md for full API reference
- **GitHub Issues**: https://github.com/SunilSherkhane/Canyon-Framework/issues
- **License**: MIT — free for personal and commercial use

---

**Framework Version**: 0.5.0  
**PHP Compatibility**: 8.1, 8.2, 8.3  
**Last Updated**: October 2026