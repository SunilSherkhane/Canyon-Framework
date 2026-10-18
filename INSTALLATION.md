# Canyon Framework — Installation Guide

## Step-by-Step Instructions

### Prerequisites

Before installing Canyon Framework, ensure your system meets the following requirements:

- **PHP 8.1 or higher** (8.2+ recommended)
- **Composer** (latest version)
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Database**: MySQL 5.7+ / MariaDB 10.3+ / SQLite 3
- **PHP Extensions**: `pdo`, `mbstring`, `json`, `openssl`, `curl`, `gd` (optional, for image processing), `imagick` (optional, for advanced image processing)
- **OPcache** (recommended for production)

---

### Step 1: Clone or Download the Framework

**Option A: Clone from GitHub**

```bash
git clone https://github.com/SunilSherkhane/Canyon-Framework.git my-project
cd my-project
```

**Option B: Download ZIP**

1. Visit https://github.com/SunilSherkhane/Canyon-Framework
2. Click **Code → Download ZIP**
3. Extract the archive to your web server directory
4. Rename the folder if desired

**Option C: Create a new project with Composer**

```bash
composer create-project canyon/framework my-project
cd my-project
```

---

### Step 2: Set Directory Permissions

The framework needs write access to the `storage/` directory for sessions, cache, and logs.

```bash
# Linux / macOS
chmod -R 755 storage/
chmod -R 755 public/uploads/ 2>/dev/null || mkdir -p public/uploads && chmod -R 755 public/uploads/

# If using Apache with mod_php (owns files as web user)
chown -R www-data:www-data storage/ public/uploads/

# If using PHP-FPM with a specific user
chown -R $USER:www-data storage/ public/uploads/
```

---

### Step 3: Configure Your Web Server

#### Apache Configuration

The framework ships with a pre-configured `.htaccess` file in `public/`. Ensure `mod_rewrite` is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**VirtualHost example:**

```apache
<VirtualHost *:80>
    ServerName myproject.local
    DocumentRoot /var/www/my-project/public

    <Directory /var/www/my-project/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/my-project-error.log
    CustomLog ${APACHE_LOG_DIR}/my-project-access.log combined
</VirtualHost>
```

**Important**: Point `DocumentRoot` to the `public/` directory only. Never point it to the project root — the `.htaccess` blocks access to sensitive files like `.env`, `config/`, `storage/`, and `app/`.

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name myproject.local;
    root /var/www/my-project/public;
    index index.php;

    # Security headers (also set by framework)
    add_header X-Frame-Options DENY always;
    add_header X-Content-Type-Options nosniff always;

    # Block access to sensitive files
    location ~ /\.env {
        deny all;
        return 404;
    }

    location ~ ^/(app|config|storage|routes|resources|vendor)/ {
        deny all;
        return 404;
    }

    # Static files
    location ~* \.(css|js|png|jpg|jpeg|gif|svg|webp|avif|ico|woff2?|ttf)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Front controller
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

---

### Step 4: Create the Environment File

Copy the example environment file and configure it:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```ini
# Application
APP_NAME="My Canyon App"
APP_ENV=development        # development | production
APP_DEBUG=true              # true | false
APP_URL=http://localhost:8000

# Security
APP_KEY=                    # Generate in Step 5
HSTS_ENABLED=false
HSTS_MAX_AGE=31536000

# Allowed Hosts
ALLOWED_HOSTS=localhost,127.0.0.1,myproject.local

# Database
DB_CONNECTION=mysql         # mysql | sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=canyon
DB_USERNAME=root
DB_PASSWORD=

# CORS
CORS_ALLOWED_ORIGINS=

# RPC (for API access)
RPC_API_KEYS=              # Comma-separated API keys
```

**For SQLite (quick start):**

```ini
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite
```

---

### Step 5: Generate Application Key

The application key is used for encryption, HMAC signatures, and signed URLs.

```bash
# Generate a random base64-encoded 32-byte key
php -r "echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

Add the output to your `.env` file:

```ini
APP_KEY=base64:your-generated-key-here
```

---

### Step 6: Install Dependencies

```bash
composer install
```

If you don't have Composer installed:

```bash
# Download Composer installer
curl -sS https://getcomposer.org/installer | php

# Install dependencies
php composer.phar install
```

---

### Step 7: Set Up the Database

**Option A: Run migrations**

```bash
./canyon migrate
```

This creates all required tables (sessions, notifications, settings, etc.).

**Option B: Manual SQL import**

If you prefer to create tables manually, the framework auto-creates most tables on first use. For full control, run:

```bash
# MySQL/MariaDB
mysql -u root -p canyon < database/schema.sql

# SQLite
sqlite3 database.sqlite < database/schema.sql
```

---

### Step 8: Verify the Installation

Start the development server:

```bash
./canyon serve
```

Or use PHP's built-in server:

```bash
php -S 127.0.0.1:8000 -t public
```

Open your browser and visit:

```
http://localhost:8000/
```

You should see the Canyon Framework welcome page with:
- Security headers active (check with browser DevTools → Network → Headers)
- Session cookie set as `CANYONSESSID` with `HttpOnly` and `SameSite=Lax`
- CSRF token available in forms

**Run the security test page:**

```
http://localhost:8000/test
```

This page shows all security checks passing (only visible when `APP_DEBUG=true`).

---

### Step 9: Build Your First Page

**1. Create a controller** — `app/Controllers/PageController.php`:

```php
<?php
namespace Canyon\Controllers;

class PageController extends Controller
{
    public function about(): string
    {
        return $this->view('pages.about', [
            'title' => 'About Us',
            'content' => 'Welcome to my Canyon application!',
        ]);
    }
}
```

**2. Create a view** — `resources/views/pages/about.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?> - <?= env('APP_NAME') ?></title>
</head>
<body>
    <h1><?= $title ?></h1>
    <p><?= $content ?></p>
</body>
</html>
```

**3. Add a route** — edit `routes/web.php`:

```php
use Canyon\Controllers\PageController;

Router::get('/about', [PageController::class, 'about'], 'about');
```

**4. Verify routes are registered:**

```bash
./canyon routes
```

**5. Visit the page:**

```
http://localhost:8000/about
```

---

### Step 10: Production Deployment Checklist

Before deploying to production:

- [ ] Set `APP_ENV=production` in `.env`
- [ ] Set `APP_DEBUG=false` in `.env`
- [ ] Generate a strong `APP_KEY`
- [ ] Configure `ALLOWED_HOSTS` with your domain(s)
- [ ] Set up SSL/TLS certificate
- [ ] Set `HSTS_ENABLED=true` (after SSL is working)
- [ ] Configure your web server (see Step 3)
- [ ] Run `./canyon migrate` to create database tables
- [ ] Set up cron for scheduled tasks:

```bash
# Crontab example
* * * * * cd /var/www/my-project && php canyon cron >> /dev/null 2>&1
```

- [ ] Set up log rotation for `storage/logs/`
- [ ] Configure `RPC_API_KEYS` if using the API
- [ ] Review `storage/` directory permissions
- [ ] Remove the `/test` route or keep `APP_DEBUG=false`

---

### Directory Structure Reference

```
my-project/
├── app/
│   ├── Controllers/       # Your controllers
│   ├── Core/              # Framework core (don't modify)
│   └── Helpers/           # Global helper functions
├── config/
│   └── app.php            # App configuration
├── database/
│   └── migrations/        # Your migration files
├── plugins/               # Plugin directory
│   └── my-plugin/
│       ├── canyon-plugin.php
│       └── composer.json
├── public/                # Web root
│   ├── index.php          # Entry point (don't modify)
│   ├── .htaccess          # Apache config (don't modify)
│   └── uploads/           # User uploads
├── resources/
│   ├── lang/              # Translations
│   ├── views/             # Your templates
│   └── themes/            # Theme files
├── routes/
│   └── web.php            # Your routes
├── storage/               # Writable directory
│   ├── cache/
│   ├── logs/
│   └── sessions/
├── tests/                 # PHPUnit tests
├── .env                   # Environment (never commit)
├── .env.example           # Template
├── canyon                 # CLI tool
├── composer.json
└── README.md
```

---

### Next Steps

- **Read the README.md** for full API documentation
- **Explore the test page** at `/test` (dev mode) to verify security
- **Build plugins** by creating directories in `plugins/` with a `canyon-plugin.php` config
- **Set up the CLI** — run `./canyon` to see all available commands
- **Configure caching** — use `Cache::remember()` for expensive queries
- **Set up the queue** — run `./canyon queue work` to process background jobs

---

### Troubleshooting

| Problem | Solution |
|---|---|
| 500 Internal Server Error | Check `storage/logs/php_errors.log` |
| 404 on all routes | Ensure `mod_rewrite` is enabled and `.htaccess` is in `public/` |
| Session not working | Check `storage/sessions/` is writable |
| Database connection failed | Verify `.env` DB credentials |
| CSS/JS not loading | Check `APP_URL` matches your domain |
| `/test` page shows 404 | Set `APP_DEBUG=true` in `.env` |

---

**Framework Version**: 0.4.0
**GitHub**: https://github.com/SunilSherkhane/Canyon-Framework
**License**: MIT
