# 🚀 Canyon Framework — Shared Hosting Deployment Guide

## Deploy to Any Shared Host (Like WordPress)

### What You Need

- Any shared hosting account (cPanel, Plesk, GoDaddy, HostGator, Bluehost, SiteGround, etc.)
- FTP client (FileZilla, Cyberduck, or your host's file manager)
- Database credentials from your hosting control panel

### 5-Minute Setup

#### Step 1: Download and Upload

1. **Download** Canyon Framework from GitHub: https://github.com/SunilSherkhane/Canyon-Framework
2. **Extract** the ZIP file on your computer
3. **Upload** all files to your hosting account:
   - **cPanel**: Use File Manager → Upload to `public_html/`
   - **FTP**: Upload to `public_html/` or `www/`
   - **Plesk**: Use File Manager → Upload to `httpdocs/`

#### Step 2: Create Database

1. Log in to your hosting control panel
2. Find **MySQL Databases** or **Database Wizard**
3. Create a new database (e.g., `username_canyon`)
4. Create a database user with a strong password
5. Add the user to the database with **All Privileges**
6. Note down: **hostname**, **database name**, **username**, **password**

#### Step 3: Run Installer

1. Open your browser and go to: `http://yourdomain.com/install.php`
2. You'll see a welcome screen checking your server requirements
3. Click **Continue** and fill in:
   - **Database Host**: Usually `localhost` (check your hosting panel)
   - **Database Name**: The database you created
   - **Database User**: The user you created
   - **Database Password**: The password you set
   - **Site URL**: Your domain (e.g., `https://yourdomain.com`)
   - **Application Name**: Your site name
   - **Timezone**: Your timezone
4. Click **Install Canyon Framework**

#### Step 4: Clean Up

After successful installation:

1. **Delete** `install.php` from your server (critical for security)
2. **Keep** `config.php` — the installer generates it automatically with your settings
3. **Set permissions**:
   - `storage/` → 755
   - `config.php` → 644
   - `public/uploads/` → 755

#### Step 5: Start Building!

Your site is now live at `http://yourdomain.com/`

---

## Directory Structure (Shared Hosting)

```
public_html/                 # Your web root (or httpdocs/, www/)
├── public/                  # Upload CONTENTS of this folder
│   ├── index.php           # Main entry point
│   ├── .htaccess           # Apache rules
│   ├── install.php         # DELETE AFTER INSTALL
│   └── uploads/            # User uploads
├── app/                    # Framework core (outside web root ideally)
│   ├── Core/               # 30+ framework classes
│   ├── Controllers/        # Your controllers
│   └── Helpers/            # Helper functions
├── config.php              # ← MAIN CONFIG FILE (edit this)
├── routes/
│   └── web.php             # URL routes
├── resources/
│   ├── views/              # Page templates
│   └── lang/               # Translations
├── storage/                # Cache, logs, sessions
│   ├── cache/
│   ├── logs/
│   └── sessions/
└── plugins/                # Plugin directory
```

---

## WordPress-Style Configuration (config.php)

All settings are in ONE file: `config.php`. Edit it with any text editor:

```php
// Database
define('CANYON_DB_HOST', 'localhost');
define('CANYON_DB_NAME', 'username_canyon');
define('CANYON_DB_USER', 'username_canyon');
define('CANYON_DB_PASS', 'your_password');

// Site URL
define('CANYON_SITE_URL', 'https://yourdomain.com');

// App Settings
define('CANYON_APP_NAME', 'My Canyon Site');
define('CANYON_ENV', 'production');  // production | development
define('CANYON_DEBUG', false);

// Security
define('CANYON_APP_KEY', 'your-random-key-here');
define('CANYON_ALLOWED_HOSTS', 'yourdomain.com,yourdomain.com');

// Performance
define('CANYON_CACHE_ENABLED', true);
define('CANYON_ASSET_MINIFY', true);
```

---

## URL Routing (routes/web.php)

Define your pages in `routes/web.php`:

```php
<?php
use Canyon\Core\Router;

// Home page
Router::get('/', function() {
    return view('home', ['title' => 'Welcome']);
}, 'home');

// About page
Router::get('/about', function() {
    return view('about', ['title' => 'About Us']);
}, 'about');

// Dynamic route
Router::get('/posts/{id}', function($id) {
    return view('post', ['id' => $id]);
}, 'posts.show');

// Admin area (with protection)
Router::group('auth', function() {
    Router::get('/admin', function() {
        return view('admin.dashboard');
    });
    Router::post('/admin/save', function() {
        // Handle form
    });
});
```

---

## Database Tables (Auto-Created)

The installer creates these tables automatically:

| Table | Purpose |
|-------|---------|
| `sessions` | User sessions |
| `cache` | Application cache |
| `notifications` | User notifications |
| `settings` | App & user settings |
| `river` | Activity stream |
| `queue` | Background jobs |

You can create additional tables using migrations:

```bash
php canyon migrate
```

Or manually in phpMyAdmin.

---

## Adding Pages (Views)

Create PHP files in `resources/views/`:

```php
<?php // resources/views/home.php ?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $title ?? 'My Site' ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <h1>Welcome to <?= $title ?? 'My Site' ?></h1>
    <p>This is your first Canyon page!</p>
</body>
</html>
```

---

## Adding Functionality (Controllers)

Create classes in `app/Controllers/`:

```php
<?php
// app/Controllers/PostController.php
namespace Canyon\Controllers;

class PostController extends Controller
{
    public function index(): string
    {
        // Get posts from database
        $posts = $this->db()->select('posts', ['*'], [], 10);
        
        return $this->view('posts.index', [
            'posts' => $posts
        ]);
    }
    
    public function show(int $id): string
    {
        $post = $this->db()->select('posts', ['*'], ['id' => $id])[0] ?? null;
        
        if (!$post) {
            return $this->notFound();
        }
        
        return $this->view('posts.show', ['post' => $post]);
    }
}
```

---

## Shared Hosting Compatibility

### Tested Hosting Providers

- cPanel-based hosts (most common)
- Plesk-based hosts
- GoDaddy, HostGator, Bluehost, SiteGround
- A2 Hosting, InMotion, DreamHost
- Any host with PHP 8.1+ and MySQL

### Hosting Requirements

| Feature | Required | Notes |
|---------|----------|-------|
| PHP 8.1+ | ✅ Yes | Most hosts now offer this |
| MySQL 5.7+ | ✅ Yes | For database |
| mod_rewrite | ✅ Yes | For URL routing |
| .htaccess | ✅ Yes | For Apache |
| Cron jobs | ❌ No | Optional, for scheduled tasks |
| SSH access | ❌ No | Not required |

### Troubleshooting

| Problem | Solution |
|---------|----------|
| **500 Internal Server Error** | Check `.htaccess` is in `public_html/`. Rename `htaccess.txt` to `.htaccess` if needed. |
| **404 on all pages** | Ensure `mod_rewrite` is enabled. Contact your host if needed. |
| **Database connection failed** | Verify credentials in `config.php`. Check if database user has correct permissions. |
| **White screen** | Set `CANYON_DEBUG=true` in `config.php` to see errors. |
| **Session errors** | Ensure `storage/sessions/` is writable (chmod 755). |
| **Upload errors** | Check `CANYON_UPLOAD_MAX_SIZE` in `config.php`. Check PHP `upload_max_filesize`. |
| **Memory limit error** | Add to `config.php`: `ini_set('memory_limit', '128M');` |

---

## Updating Canyon Framework

1. **Backup** your `config.php`, `routes/`, `resources/`, and `app/Controllers/`
2. **Download** the latest version from GitHub
3. **Upload** all files EXCEPT:
   - `config.php` (keep yours)
   - `routes/` (keep yours)
   - `resources/` (keep yours)
   - `storage/` (keep yours)
   - `plugins/` (keep yours)
4. **Delete** `install.php` if it reappears
5. **Clear cache** by deleting `storage/cache/` contents

---

## Security Checklist

- [ ] Delete `install.php` after installation
- [ ] Set `CANYON_DEBUG=false` in production
- [ ] Set `CANYON_ENV='production'`
- [ ] Keep `CANYON_APP_KEY` secret (don't share config.php)
- [ ] Use strong database password
- [ ] Set `storage/` to 755 permissions
- [ ] Set `config.php` to 644 permissions
- [ ] Enable HTTPS and set `CANYON_SITE_URL` to `https://`
- [ ] Regularly update Canyon Framework

---

## Next Steps

- **Read** `README.md` for full API documentation
- **Explore** `app/Core/` to see all 30+ framework classes
- **Create** plugins in `plugins/` directory
- **Customize** themes in `resources/themes/`

---

**Framework Version**: 0.5.0  
**PHP Compatibility**: 8.1, 8.2, 8.3  
**License**: MIT