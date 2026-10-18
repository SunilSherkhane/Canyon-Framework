# 🌊 Canyon Framework

**A modular, secure PHP framework inspired by Laravel, CodeIgniter, Elgg, and QUIC.cloud — build anything from APIs to social networks to high-performance sites.**

> Version: 0.5.0 | PHP: 8.1+ | License: MIT

---

## ⚡ Quick Start

```bash
./canyon serve              # Start dev server (port 8000)
./canyon routes             # List registered routes
./canyon migrate            # Run database migrations
./canyon queue work         # Process queued jobs
./canyon info               # Framework info
```

**New to Canyon?** See **[INSTALLATION.md](INSTALLATION.md)** for step-by-step setup instructions.

Open `http://localhost:8000/` — security headers, sessions, and CSRF active by default.

---

## 📦 Feature Matrix (Laravel + CodeIgniter + Elgg + QUIC.cloud Inspired)

### Core Architecture
| Module | Class | Key Methods |
|---|---|---|
| **DI Container** | `Container` | `bind()`, `singleton()`, `resolve()`, `when()` with contextual bindings |
| **Application** | `Application` | `set()`, `get()`, `isProduction()`, `isDebug()`, shared view data |
| **Config** | `Config` | `get('app.debug')`, `set()`, `has()`, `all()` — dot notation |
| **Middleware** | `Middleware` | Abstract `handle($request, Closure $next)` — pipeline execution |
| **Router** | `Router` | `get/post/put/delete/patch/any()`, `group()`, `{param}`, named routes, catch-all, HTTP method spoofing |
| **Validation** | `Validation` | Rule strings (`'required\|email\|min:3'`), `passes()`, `fails()`, `errors()` |
| **Plugin System** | `Plugin` | `loadAll()`, `isActive()`, `getSetting()`, `getUserConfig()` |
| **HMAC** | `Hmac` | `generate()`, `validate()`, `signedUrl()`, `generateActionToken()` |
| **Cron** | `Cron` | `register()`, `run()`, `runDue()`, `isDue()`, `addInterval()` |

### Database & Data
| Module | Class | Key Methods |
|---|---|---|
| **Database** | `Database` | `select()`, `insert()`, `update()`, `delete()`, `statement()`, `beginTransaction()`, `transaction()` |
| **Migration** | `Migration` | `migrate()`, `rollback()`, `reset()`, `status()` — `up()`/`down()` classes |
| **Entity** | `Entity` | `find()`, `first()`, `save()`, `delete()`, `fillable`, `casts`, `getGuid()` |
| **Collection** | `Collection` | `filter()`, `map()`, `reduce()`, `where()`, `chunk()`, `skip()`, `take()`, `first()`, `last()` |
| **Search** | `Search` | `query()`, `byType()`, `byOwner()`, `byMetadata()`, `advanced()`, `suggestions()` |
| **River** | `River` | `add()`, `get()`, `forEntity()`, `forOwner()`, `deleteEntityItems()` |

### Views & UI
| Module | Class | Key Methods |
|---|---|---|
| **View** | `View` | `make()`, `render()`, `exists()`, `escape()`, `raw()` — auto-escaping + path-traversal protection |
| **Partial** | `Partial` | `section()`, `endSection()`, `yield()`, `include()`, `extend()`, `has()` |
| **Widget** | `Widget` | `register()`, `render()`, `byPosition()`, `setPosition()`, `setUserConfig()` |
| **Menu** | `Menu` | `add()`, `remove()`, `get()`, `render()`, `addChildren()`, `setPriority()` |
| **Themes** | `Themes` | `register()`, `activate()`, `setVariable()`, `addCss()`, `addJs()`, `render()` |

### Security
| Module | Class | Key Methods |
|---|---|---|
| **Security** | `Security` | `csrfField()`, `verifyCsrfToken()`, `throttle()`, `throttleIp()`, `sanitizeInput()`, `escape()`, `logEvent()` |
| **AccessControl** | `AccessControl` | `addRole()`, `addGroup()`, `inGroup()`, `setAccess()`, `canAccess()` |
| **Permissions** | `Permissions` | `can()`, `canEdit()`, `canDelete()`, `canView()`, `require()` |
| **PageOwnership** | `PageOwnership` | `setOwner()`, `getOwner()`, `setContext()`, `pushContext()`, `popContext()` |

### Events & Queue
| Module | Class | Key Methods |
|---|---|---|
| **EventDispatcher** | `EventDispatcher` | `listen()`, `dispatch()`, `subscribe()`, `hasListeners()` |
| **Queue** | `Queue` | `push()`, `pop()`, `process()`, `work()`, `fail()`, `clear()` |
| **Notification** | `Notification` | `send()`, `markRead()`, `subscribe()`, `unreadCount()`, `get()`, `stats()` |

### Services
| Module | Class | Key Methods |
|---|---|---|
| **Cache** | `Cache` | `put()`, `get()`, `has()`, `remember()`, `flush()`, `forget()` |
| **Email** | `Email` | `send()`, `queue()`, `sendTemplate()`, `sendNotification()`, `processQueue()` |
| **FileStorage** | `FileStorage` | `store()`, `get()`, `delete()`, `getUrl()`, `stream()`, `getByOwner()` |
| **I18n** | `I18n` | `get()`, `trans()`, `choice()`, `setLocale()`, `detectLocale()`, `has()` |
| **RPC** | `RPC` | `register()`, `handle()`, `getMethods()` — JSON-RPC 2.0 with API key auth |
| **Settings** | `Settings` | `get()`, `set()`, `getUser()`, `setUser()`, `all()`, `allUser()` |

### Performance (QUIC.cloud Inspired)
| Module | Class | Key Methods |
|---|---|---|
| **AssetOptimizer** | `AssetOptimizer` | `minifyCss()`, `minifyJs()`, `minifyHtml()`, `combineCss()`, `lazyImage()`, `asyncCss()`, `deferJs()`, `generateCriticalCss()`, `cacheHeaders()`, `dnsPrefetch()`, `http2Push()`, `versioned()` |
| **ImageOptimizer** | `ImageOptimizer` | `optimizeUpload()`, `generateWebP()`, `generateAvif()`, `generateResponsive()`, `srcset()`, `picture()`, `isImagickAvailable()` |
| **DatabaseOptimizer** | `DatabaseOptimizer` | `cleanSessions()`, `cleanCache()`, `cleanQueue()`, `optimizeTables()`, `analyzeTables()`, `getDatabaseSize()`, `runAllCleanup()` |
| **Opcache** | `Opcache` | `isEnabled()`, `getStatus()`, `clear()`, `invalidate()`, `precompile()`, `healthReport()` |
| **Heartbeat** | `Heartbeat` | `shouldProcess()`, `process()`, `setEnabled()`, `setInterval()`, `status()` |
| **PreloadCrawler** | `PreloadCrawler` | `loadSitemap()`, `crawl()`, `preload()`, `preloadUrls()`, `checkRobots()`, `getReport()` |

### CLI
```bash
./canyon serve [port] [host]   # Dev server
./canyon routes                # List routes
./canyon migrate [action]      # migrate|rollback|reset|status
./canyon queue [action]        # work|status|clear|clear-failed
./canyon config                # Show configuration
./canyon test                  # Run tests
./canyon clear-cache           # Clear cache
./canyon info                  # Framework info
```

---

## 🏗️ Project Layout

```
canyon/
├── public/              # Web entry point
│   ├── index.php        # Bootstrap: env, session, host check, dispatch
│   └── .htaccess        # Rewrite rules + security headers
├── app/
│   ├── Core/            # 30 framework classes (see above)
│   ├── Controllers/     # Base Controller + your controllers
│   └── Helpers/         # env(), base_url(), asset(), route_path(), response_time()
├── routes/
│   └── web.php          # Route definitions
├── resources/
│   ├── views/           # PHP templates (auto-escaping)
│   ├── lang/            # i18n translation files
│   └── emails/          # Email templates
├── config/
│   └── app.php          # App configuration
├── storage/             # Sessions, cache, logs, queue, files
├── plugins/             # Plugin directory
│   └── my-plugin/
│       ├── canyon-plugin.php
│       └── composer.json
├── .env                 # Environment (NOT committed)
├── .env.example         # Template for .env
├── canyon               # CLI binary
├── composer.json        # PSR-4 autoload + bin: canyon
├── phpunit.xml          # PHPUnit config
├── INSTALLATION.md      # Step-by-step installation guide
├── tests/               # PHPUnit (Security/, Router/ suites)
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
Router::get('/posts/{id}', [PostController::class, 'show'], 'posts.show');

Router::group(AuthMiddleware::class, function () {
    Router::post('/posts', [PostController::class, 'store'], 'posts.store');
});
```

### Plugin System
```php
// plugins/my-plugin/canyon-plugin.php
return [
    'plugin' => [
        'name' => 'My Plugin',
        'version' => '1.0.0',
        'active' => true,
    ],
    'routes' => [
        'my_plugin_index' => [
            'path' => '/my-plugin',
            'method' => 'GET',
            'controller' => [MyPluginController::class, 'index'],
        ],
    ],
    'widgets' => [
        'my_widget' => [
            'name' => 'My Widget',
            'callback' => fn($data) => '<div>Widget!</div>',
            'options' => ['access' => 'logged_in'],
        ],
    ],
    'settings' => [
        'default_value' => 'hello',
    ],
];
```

### Performance Optimization
```php
// Minify CSS/JS
$css = AssetOptimizer::minifyCss($css);
$js = AssetOptimizer::minifyJs($js);

// Lazy-load images
$img = AssetOptimizer::lazyImage('/uploads/photo.jpg', 'Photo');

// Generate critical CSS
$critical = AssetOptimizer::generateCriticalCss($html, 'styles.css');

// CDN + versioned assets
$url = AssetOptimizer::versioned('/css/main.css');

// Image optimization (WebP/AVIF)
$metadata = ImageOptimizer::optimizeUpload($_FILES['photo'], 'images');
echo ImageOptimizer::picture($metadata['url'], $metadata, 'Photo');

// Cache pages
$html = Cache::remember('page.home', fn() => $this->renderHome(), 3600);
```

### Entity + Search + River
```php
// Entity
$post = new Post(['title' => 'Hello', 'body' => 'World']);
$post->save();

// Search
$search = new Search();
$results = $search->query('hello', ['type' => 'object', 'subtype' => 'post'], ['limit' => 10]);

// River
$river = new River();
$river->add([
    'type' => 'object',
    'subtype' => 'post',
    'action_type' => 'create',
    'entity_id' => $post->getAttribute('id'),
    'entity_type' => 'object',
    'owner_id' => '123',
]);
```

### Events + Cron + Queue
```php
// Events
EventDispatcher::listen('user.created', function ($payload) {
    Notification::send($payload['user_id'], 'Welcome!', 'Thanks!');
});
EventDispatcher::dispatch('user.created', ['user_id' => 123]);

// Cron
Cron::register('daily', function () {
    $optimizer = new DatabaseOptimizer();
    $optimizer->runAllCleanup();
});

// Queue
Queue::push(SendEmail::class, ['to' => 'user@test.com']);
Queue::work(10);
```

### Themes + Widgets + Menus
```php
// Theme
Themes::register('dark', 'Dark Theme');
Themes::activate('dark');
Themes::setVariable('bg_color', '#1a1a1a');
Themes::addCss('/themes/dark/style.css');

// Widget
Widget::register('recent_posts', 'Recent Posts', function ($data) {
    return '<div>Latest posts...</div>';
}, ['access' => 'logged_in', 'cache' => 300, 'position' => 'sidebar']);

// Menu
Menu::add('main', 'home', ['text' => 'Home', 'href' => '/', 'priority' => 100]);
Menu::add('main', 'about', ['text' => 'About', 'href' => '/about', 'priority' => 200]);
echo Menu::render('main');
```

---

## 🔒 Security Defaults (Already Active)

- CSRF tokens on all POST forms (`hash_equals()` verification + 1-hour expiry)
- Host header injection protection (configurable allow-list, wildcard subdomain support)
- Session hardening (`HttpOnly`, `SameSite=Lax`, `use_strict_mode`, `use_only_cookies`, `CANYONSESSID`, storage outside web root)
- Security headers: CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy, Permissions-Policy, HSTS
- Rate limiting: session-based (`throttle()`) + IP-based (`throttleIp()`)
- Output auto-escaping in views (`htmlspecialchars()`)
- `JSON_HEX_*` flags on all JSON responses
- Input sanitization (modern PHP 8.1+ `FILTER_VALIDATE_*` only)
- Open redirect prevention (rejects `//evil.com`, absolute URLs, protocol-relative)
- Error sanitization (no stack traces in production)
- HMAC signatures for signed URLs and data integrity
- .htaccess: blocks `.env`, `storage/`, `logs/`, directory listing, PHP execution in uploads

---

## 🧪 Testing

```bash
./canyon test
# or
./vendor/bin/phpunit
```

---

## 📄 License

MIT — free for personal and commercial use.
