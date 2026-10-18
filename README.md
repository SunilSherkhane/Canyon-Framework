# 🌊 Canyon Framework

**A modular, secure PHP framework inspired by Laravel, CodeIgniter, and Elgg — designed for building anything from APIs to social networks.**

> Version: 0.4.0 | PHP: 8.1+ | License: MIT

---

## ⚡ Quick Start

```bash
./canyon serve          # Start dev server (port 8000)
./canyon routes          # List registered routes
./canyon migrate         # Run database migrations
./canyon queue work      # Process queued jobs
```

Open `http://localhost:8000/` — security headers, sessions, and CSRF active by default.

---

## 📦 Feature Matrix (Laravel + CodeIgniter + Elgg Inspired)

### Core Architecture
| Module | Class | Key Methods |
|---|---|---|
| **DI Container** | `Container` | `bind()`, `singleton()`, `resolve()`, `when()` with contextual bindings |
| **Application** | `Application` | `set()`, `get()`, `isProduction()`, `isDebug()`, shared view data |
| **Config** | `Config` | `get('app.debug')`, `set()`, `has()`, `all()` — dot notation |
| **Middleware** | `Middleware` | Abstract `handle($request, Closure $next)` — pipeline execution |
| **Router** | `Router` | `get/post/put/delete/patch/any()`, `group()`, `{param}`, named routes, catch-all, HTTP method spoofing |
| **Validation** | `Validation` | Rule strings (`'required\|email\|min:3'`), `passes()`, `fails()`, `errors()` |

### Database & Data
| Module | Class | Key Methods |
|---|---|---|
| **Database** | `Database` | `select()`, `insert()`, `update()`, `delete()`, `statement()`, `beginTransaction()`, `transaction()` |
| **Migration** | `Migration` | `migrate()`, `rollback()`, `reset()`, `status()` — `up()`/`down()` classes |
| **Entity** | `Entity` | `find()`, `first()`, `save()`, `delete()`, `fillable`, `casts`, `getGuid()`, `getEntityType()` |
| **Collection** | `Collection` | `filter()`, `map()`, `reduce()`, `where()`, `chunk()`, `skip()`, `take()`, `first()`, `last()`, `random()` |

### Views & UI
| Module | Class | Key Methods |
|---|---|---|
| **View** | `View` | `make()`, `render()`, `exists()`, `escape()`, `raw()` — auto-escaping + path-traversal protection |
| **Partial** | `Partial` | `section()`, `endSection()`, `yield()`, `include()`, `extend()`, `has()` |
| **Widget** | `Widget` | `register()`, `render()`, `byPosition()`, `setPosition()`, `setOrder()`, `setUserConfig()` |

### Security
| Module | Class | Key Methods |
|---|---|---|
| **Security** | `Security` | `csrfField()`, `verifyCsrfToken()`, `throttle()`, `throttleIp()`, `sanitizeInput()`, `escape()`, `logEvent()` |
| **AccessControl** | `AccessControl` | `addRole()`, `addGroup()`, `inGroup()`, `setAccess()`, `canAccess()`, `getAccessible()` |

### Events & Queue
| Module | Class | Key Methods |
|---|---|---|
| **EventDispatcher** | `EventDispatcher` | `listen()`, `dispatch()`, `subscribe()`, `hasListeners()` |
| **Queue** | `Queue` | `push()`, `pop()`, `process()`, `work()`, `fail()`, `clear()` |

### Services
| Module | Class | Key Methods |
|---|---|---|
| **Cache** | `Cache` | `put()`, `get()`, `has()`, `remember()`, `flush()`, `forget()` |
| **Email** | `Email` | `send()`, `queue()`, `sendTemplate()`, `sendNotification()`, `processQueue()` |
| **Notification** | `Notification` | `send()`, `markRead()`, `subscribe()`, `unreadCount()`, `get()`, `stats()` |
| **FileStorage** | `FileStorage` | `store()`, `get()`, `delete()`, `getUrl()`, `stream()`, `getByOwner()` |
| **I18n** | `I18n` | `get()`, `trans()`, `choice()`, `setLocale()`, `detectLocale()`, `has()` |
| **RPC** | `RPC` | `register()`, `handle()`, `getMethods()` — JSON-RPC 2.0 with API key auth |

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
│   ├── Core/            # 16 framework classes (see above)
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
├── .env                 # Environment (NOT committed)
├── .env.example         # Template for .env
├── canyon               # CLI binary
├── composer.json        # PSR-4 autoload + bin: canyon
└── tests/               # PHPUnit (Security/, Router/ suites)
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

### Controller + Validation + Database
```php
<?php
namespace Canyon\Controllers;

class PostController extends Controller
{
    public function index(): string
    {
        $posts = $this->db()->select('posts', ['*'], [], 20);
        return $this->view('posts.index', ['posts' => $posts]);
    }

    public function show(string $id): string
    {
        $post = $this->db()->select('posts', ['*'], ['id' => $id])[0] ?? null;
        if (!$post) return $this->notFound();
        return $this->view('posts.show', ['post' => $post]);
    }

    public function store(): never
    {
        $data = $this->post([
            'title' => 'string',
            'body' => 'string',
            'category_id' => 'int',
        ]);

        $validator = new \Canyon\Core\Validation($data, [
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            $this->json(['errors' => $validator->errors()], 422);
        }

        $id = $this->db()->insert('posts', $data);
        $this->success(['id' => $id], 'Post created', 201);
    }
}
```

### Entity System (Active Record)
```php
class Post extends \Canyon\Core\Entity
{
    protected string $table = 'posts';
    protected array $fillable = ['title', 'body', 'author_id', 'status'];
    protected array $casts = [
        'id' => 'int',
        'is_published' => 'boolean',
        'settings' => 'json',
        'password_hash' => 'encrypted',
    ];
}

$post = Post::find(1);
$post->title = 'Updated';
$post->save();
```

### Events + Queue + Notifications
```php
// Event
\EventDispatcher::listen('user.created', function ($payload) {
    \Notification::send($payload['user_id'], 'Welcome!', 'Thanks for joining!');
});
\EventDispatcher::dispatch('user.created', ['user_id' => 123]);

// Queue
\Queue::push(SendWelcomeEmail::class, ['user_id' => 123]);
$processed = \Queue::work(10);
```

### Caching + i18n + Widgets
```php
// Cache
$users = \Cache::remember('users.all', fn() => $this->db()->select('users'), 3600);

// i18n
\I18n::setLocale('en');
$text = \I18n::get('posts.welcome', ['name' => 'Alice']);

// Widget
\Widget::register('recent_posts', 'Recent Posts', function ($data) {
    return $this->view('widgets.recent_posts', $data);
}, ['access' => 'logged_in', 'cache' => 300]);

echo \Widget::renderPosition('sidebar', ['user_id' => 123]);
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
- .htaccess: blocks `.env`, `storage/`, `logs/`, directory listing, PHP execution in uploads, ETag disabled

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
