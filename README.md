# 🌊 Canyon Framework

**A lightweight, secure PHP framework inspired by Laravel, CodeIgniter - works on any shared hosting.**

> Version: 0.5.0 | PHP: 8.1+ | License: MIT

---

## Core vs Plugin Architecture

The Canyon Framework follows a **modular and core-first principle**. The core framework provides minimal essential functionality, and additional features are delivered as **optional plugins**.

### Core Framework (Always Included)
- **HTTP Layer**: `Request`, `Response`, `Router`, `Middleware`, `Partial`
- **Dependency Injection**: `Container`, `Application`, `Config`
- **Data Layer**: `Database`, `Entity`, `Collection`, `Migration`
- **Security**: `Security`, `Rbac`, `Hmac`, `Permissions`, `AccessControl`
- **Validation**: `Validation`
- **Events**: `EventDispatcher`, `Queue`, `Cron`
- **Services**: `Cache`, `Email`, `Notification`, `FileStorage`, `I18n`, `RPC`, `Settings`
- **UI**: `View`, `Widget`, `Menu`, `Themes`
- **Performance**: `AssetOptimizer`, `ImageOptimizer`, `DatabaseOptimizer`, `Opcache`
- **Symfony Components**: `Finder`, `Process`, `Lock`
- **AI**: `Ai`, `AiResponse`, `AiHelper`, `AiChatbot`

### Plugins (Optional, Install as Needed)
| Plugin | Features | Location |
|--------|----------|----------|
| **Social System** | `UserProfile`, `MessageSystem`, `ActivityFeed`, `GroupSystem`, `Waf` | `plugins/social/` |
| **Custom Features** | Your own social/payment/etc. | `plugins/<name>/` |

### Building Without Modifying Core

```php
<?php
// 1. Create middleware in app/Middleware/
class AuthMiddleware extends Middleware {
    public function handle($request, $next) { /* ... */ }
}

// 2. Register routes in routes/web.php
Router::group([AuthMiddleware::class], function () {
    Router::get('/dashboard', [DashboardController::class, 'index']);
});

// 3. Create services in app/Services/
class PaymentService {
    public function process($amount, $user) { /* ... */ }
}

// 4. Create plugins in plugins/<name>/
// plugins/my-plugin/canyon-plugin.php
return [
    'plugin' => ['name' => 'My Plugin', 'active' => true],
    'routes' => ['my_route' => ['path' => '/custom', 'handler' => [MyController::class, 'index']]],
];
```

**Key Principle**: Never modify `app/Core/` for business logic. Extend via `app/Controllers/`, `app/Models/`, `app/Middleware/`, `app/Services/`, and `plugins/`.

### On Shared Hosting (cPanel, GoDaddy, HostGator, etc.)

1. **Download** from GitHub and **upload** to `public_html/`
2. **Create a MySQL database** in your hosting control panel
3. **Visit** `http://yourdomain.com/install.php` in your browser
4. **Fill in** your database details and site URL
5. **Delete** `install.php` - done!

**See [SHARED-HOSTING.md](SHARED-HOSTING.md) for detailed instructions.**

### On Local Server

```bash
git clone https://github.com/SunilSherkhane/Canyon-Framework.git
cd Canyon-Framework
# Edit config.php with your settings
php -S localhost:8000 -t public
```

---

## 📦 Feature Matrix (40+ Classes)

### Core Architecture
| Module | Class | Key Methods |
|---|---|---|
| **DI Container** | `Container` | `bind()`, `singleton()`, `resolve()`, `when()` contextual bindings |
| **Application** | `Application` | `set()`, `get()`, `isProduction()`, `isDebug()` |
| **Config** | `Config` | `get('app.debug')`, `set()`, `has()` - dot notation |
| **Request** | `Request` | `createFromGlobals()`, `input()`, `file()`, `validate()` - Symfony HTTP Foundation inspired |
| **Response** | `Response` | `json()`, `view()`, `redirect()`, `download()` - chainable |
| **Router** | `Router` | `get/post/put/delete/patch/any()`, `group()`, `{param}`, named routes |
| **Validation** | `Validation` | Rule strings, `passes()`, `fails()`, `errors()` |
| **Console** | `Console` | `run()`, `info()`, `error()`, `table()`, `ask()` - Symfony Console inspired |
| **ProgressBar** | `ProgressBar` | `start()`, `advance()`, `finish()` - CLI progress indicator |
| **Plugin System** | `Plugin` | `loadAll()`, `isActive()`, `getSetting()` |
| **Cron** | `Cron` | `register()`, `run()`, `runDue()`, `isDue()` |

### Database & Data
| Module | Class | Key Methods |
|---|---|---|
| **Database** | `Database` | `select()`, `insert()`, `update()`, `delete()`, `transaction()` |
| **Migration** | `Migration` | `migrate()`, `rollback()`, `reset()`, `status()` |
| **Entity** | `Entity` | `find()`, `first()`, `save()`, `delete()`, `fillable`, `casts` |
| **Collection** | `Collection` | `filter()`, `map()`, `reduce()`, `where()`, `chunk()` |
| **Search** | `Search` | `query()`, `byType()`, `byOwner()`, `advanced()` |
| **River** | `River` | `add()`, `get()`, `forEntity()`, `forOwner()` |

### Security & Access
| Module | Class | Key Methods |
|---|---|---|
| **Security** | `Security` | `csrfField()`, `verifyCsrfToken()`, `throttle()`, `sanitizeInput()` |
| **AccessControl** | `AccessControl` | `addRole()`, `addGroup()`, `canAccess()` |
| **RBAC** | `Rbac` | `assign()`, `can()`, `getPermissionsByRole()` - Yii2 inspired |
| **Permissions** | `Permissions` | `can()`, `canEdit()`, `canDelete()`, `require()` |
| **HMAC** | `Hmac` | `generate()`, `validate()`, `signedUrl()` |
| **PageOwnership** | `PageOwnership` | `setOwner()`, `getOwner()`, `pushContext()` |

### Symfony-Inspired Components
| Module | Class | Key Methods |
|---|---|---|
| **Finder** | `Finder` | `in()`, `name()`, `files()`, `size()`, `sortByName()` - file search |
| **Process** | `Process` | `run()`, `start()`, `stop()` - execute system commands |
| **Lock** | `Lock` | `acquire()`, `release()`, `synchronize()` - distributed locking |

### Services
| Module | Class | Key Methods |
|---|---|---|
| **Cache** | `Cache` | `put()`, `get()`, `remember()`, `flush()` |
| **Email** | `Email` | `send()`, `queue()`, `sendTemplate()` |
| **Notification** | `Notification` | `send()`, `markRead()`, `subscribe()` |
| **FileStorage** | `FileStorage` | `store()`, `get()`, `delete()`, `stream()` |
| **I18n** | `I18n` | `get()`, `trans()`, `setLocale()` |
| **RPC** | `RPC` | `register()`, `handle()` - JSON-RPC 2.0 |
| **Settings** | `Settings` | `get()`, `set()`, `getUser()` |
| **Queue** | `Queue` | `push()`, `pop()`, `work()` |

### UI & Presentation
| Module | Class | Key Methods |
|---|---|---|
| **View** | `View` | `make()`, `render()`, `escape()` - auto-escaping |
| **Partial** | `Partial` | `section()`, `yield()`, `include()`, `extend()` |
| **Widget** | `Widget` | `register()`, `render()`, `byPosition()` |
| **Menu** | `Menu` | `add()`, `remove()`, `render()` |
| **Themes** | `Themes` | `register()`, `activate()`, `addCss()`, `addJs()` |

### Performance (QUIC.cloud Inspired)
| Module | Class | Key Methods |
|---|---|---|
| **AssetOptimizer** | `AssetOptimizer` | `minifyCss()`, `minifyJs()`, `lazyImage()`, `generateCriticalCss()` |
| **ImageOptimizer** | `ImageOptimizer` | `optimizeUpload()`, `generateWebP()`, `generateAvif()`, `picture()` |
| **DatabaseOptimizer** | `DatabaseOptimizer` | `cleanSessions()`, `optimizeTables()`, `runAllCleanup()` |
| **Opcache** | `Opcache` | `isEnabled()`, `getStatus()`, `clear()`, `precompile()` |
| **Heartbeat** | `Heartbeat` | `shouldProcess()`, `process()` |
| **PreloadCrawler** | `PreloadCrawler` | `loadSitemap()`, `crawl()`, `preload()` |

---

## 🏗️ Project Layout

```
my-project/
├── public/              # Web root (public_html/)
│   ├── index.php        # Entry point (bootstrap)
│   ├── install.php      # Web installer (delete after use)
│   ├── .htaccess        # Apache rules (shared hosting safe)
│   └── uploads/         # User uploads
├── app/
│   ├── Core/            # 40+ framework classes
│   ├── Controllers/     # Your controllers
│   └── Helpers/         # Global helper functions
├── routes/
│   └── web.php          # URL routes
├── resources/
│   ├── views/           # PHP templates
│   ├── lang/            # Translations
│   └── themes/          # Theme files
├── config.php           # ← MAIN CONFIG (WordPress-style)
├── storage/             # Sessions, cache, logs
├── plugins/             # Plugin directory
├── canyon               # CLI tool
├── SHARED-HOSTING.md    # Shared hosting guide
├── INSTALLATION.md      # Full installation guide
└── README.md            # This file
```

---

## 📝 Usage Examples

### Routes + Middleware
```php
use Canyon\Core\Router;
use Canyon\Controllers\PostController;
use Canyon\Middleware\AuthMiddleware;

Router::get('/posts', [PostController::class, 'index'], 'posts.index');

Router::group(AuthMiddleware::class, function () {
    Router::post('/posts', [PostController::class, 'store']);
});
```

### Request & Response (Symfony-inspired)
```php
// In a controller
$request = \Canyon\Core\Request::createFromGlobals();

// Get input
$name = $request->input('name', 'default');
$file = $request->file('avatar');

// JSON response
return \Canyon\Core\Response::json([
    'success' => true,
    'data' => $user
]);

// View response
return \Canyon\Core\Response::view('posts.index', ['posts' => $posts]);
```

### Console Commands (Symfony-inspired)
```php
\Canyon\Core\Console::register('g:model', function($console, $argv) {
    $console->info('Generating model...');
    $bar = $console->progressBar(100);
    $bar->start();
    // ... do work ...
    $bar->finish();
    $console->success('Model created!');
}, 'Generate a new model');
```

### RBAC (Yii2-inspired)
```php
\Canyon\Core\Rbac::assign('admin', $userId);
\Canyon\Core\Rbac::assign('editor', $userId);

if (\Canyon\Core\Rbac::can($userId, 'posts.publish')) {
    // Allow publishing
}
```

### Finder (Symfony-inspired)
```php
$files = \Canyon\Core\Finder::create()
    ->in('/path/to/uploads')
    ->name('*.jpg')
    ->size('> 100K')
    ->sortByModifiedTime()
    ->getResults();
```

### Entity + Search + River
```php
// Entity
$post = new Post(['title' => 'Hello', 'body' => 'World']);
$post->save();

// Search
$search = new Search();
$results = $search->query('hello', ['type' => 'post']);

// River
$river = new River();
$river->add([
    'type' => 'object',
    'subtype' => 'post',
    'action_type' => 'create',
    'entity_id' => $post->getAttribute('id'),
]);
```

### Events + Queue + Cron
```php
EventDispatcher::listen('user.created', fn($p) => Notification::send($p['user_id'], 'Welcome!'));
EventDispatcher::dispatch('user.created', ['user_id' => 123]);

Queue::push(SendEmail::class, ['to' => 'user@test.com']);

Cron::register('daily', function () {
    (new DatabaseOptimizer())->runAllCleanup();
});
```

---

## 🔒 Security Defaults

- CSRF tokens on all POST forms
- Host header injection protection
- Session hardening (HttpOnly, SameSite=Lax, strict mode)
- Security headers: CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff
- Rate limiting (session + IP based)
- Output auto-escaping in views
- HMAC signatures for signed URLs
- RBAC with role hierarchy

---

## 🧪 Testing

```bash
./canyon test
# or
./vendor/bin/phpunit
```

---

## 📄 License

MIT - free for personal and commercial use.
