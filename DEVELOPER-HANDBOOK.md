# Canyon Framework - Developer Handbook

## Building Apps on Canyon Framework

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Core Concepts](#core-concepts)
3. [Routing & Controllers](#routing--controllers)
4. [Database & Models](#database--models)
5. [Views & Templates](#views--templates)
6. [Security](#security)
7. [Authentication & Authorization](#authentication--authorization)
8. [Plugin Development](#plugin-development)
9. [AI Integration](#ai-integration)
10. [Social Features](#social-features)
11. [Performance Optimization](#performance-optimization)
12. [Deployment](#deployment)
13. [API Reference](#api-reference)

---

## Getting Started

### Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+ / SQLite 3
- Apache (mod_rewrite) or Nginx
- Composer (recommended)

### Installation

```bash
# Clone the repository
git clone https://github.com/SunilSherkhane/Canyon-Framework.git my-app
cd my-app

# Configure
cp config.php config.php.local
# Edit config.php with your database credentials

# Run installer (shared hosting: upload and visit /install.php)
php -S localhost:8000 -t public
```

### Directory Structure

```
my-app/
├── public/ # Web root
│ ├── index.php # Entry point (don't modify)
│ ├── .htaccess # Apache rules
│ └── uploads/ # User uploads
├── app/
│ ├── Core/ # Framework core (45+ classes)
│ ├── Controllers/ # Your controllers
│ ├── Models/ # Your models (Entity classes)
│ ├── Middleware/ # Your middleware
│ └── Helpers/ # Global helpers
├── routes/
│ ├── web.php # Web routes
│ └── ai.php # AI routes (optional)
├── resources/
│ ├── views/ # PHP templates
│ ├── lang/ # Translations
│ ├── css/ # Stylesheets
│ └── js/ # JavaScript
├── config/
│ └── ai.php # AI provider config
├── plugins/ # Plugins directory
├── storage/ # Cache, logs, sessions
│ ├── cache/
│ ├── logs/
│ └── sessions/
├── config.php # Main configuration (WordPress-style)
├── canyon # CLI tool
└── DEVELOPER-HANDBOOK.md # This file
```

---

## Core Concepts

### Request Lifecycle

```
HTTP Request → index.php → Router → Middleware → Controller → View → Response
```

1. **index.php** boots the framework (session, security headers, autoloader)
2. **Router** matches the URL to a route handler
3. **Middleware** processes the request (auth, CSRF, rate limiting)
4. **Controller** handles the logic
5. **View** renders the template
6. **Response** is sent to the client

### Dependency Injection

```php
// Bind a service in a service provider
\Container::singleton('db', function () {
 return new Database();
});

// Resolve anywhere
$db = \Container::make('db');
```

### Events

```php
// Listen for events
\EventDispatcher::listen('user.registered', function ($user) {
 \Notification::send($user->id, 'Welcome!', 'Thanks for joining!');
});

// Fire events
\EventDispatcher::dispatch('user.registered', ['user' => $user]);
```

---

## Routing & Controllers

### Defining Routes

```php
<?php
// routes/web.php
use Canyon\Core\Router;

// Basic routes
Router::get('/', [HomeController::class, 'index'], 'home');
Router::get('/about', fn() => view('about'), 'about');

// Route parameters
Router::get('/posts/{id}', [PostController::class, 'show'], 'posts.show');
Router::get('/users/{user}/posts/{post}', [PostController::class, 'showByUser'], 'users.posts.show');

// Route groups with middleware
Router::group('auth', function () {
 Router::get('/dashboard', [DashboardController::class, 'index']);
 Router::post('/profile/update', [ProfileController::class, 'update']);
});

// Route groups with prefix
Router::group('api', 'prefix:api/v1', function () {
 Router::get('/users', [Api\UserController::class, 'index']);
 Router::post('/users', [Api\UserController::class, 'store']);
});

// Any HTTP method
Router::any('/webhook', [WebhookController::class, 'handle']);

// Catch-all 404 (must be last)
Router::any('{path}', fn() => abort(404), 'fallback', false);
```

### Controllers

```php
<?php
// app/Controllers/PostController.php
namespace Canyon\Controllers;

use Canyon\Core\Request;
use Canyon\Core\Response;
use Canyon\Core\Validation;
use Canyon\Models\Post;

class PostController extends Controller
{
 public function index(): string
 {
 $posts = Post::paginate(10);
 return $this->view('posts.index', compact('posts'));
 }

 public function show(int $id): string
 {
 $post = Post::find($id);
 if (!$post) {
 return $this->notFound('Post not found');
 }
 return $this->view('posts.show', compact('post'));
 }

 public function store(): never
 {
 // Validate input
 $data = $this->validate([
 'title' => 'required|string|max:255',
 'body' => 'required|string',
 ]);

 // Create post
 $post = Post::create($data);
 $post->author_id = auth()->id;
 $post->save();

 return $this->redirect()
 ->route('posts.show', ['id' => $post->id])
 ->with('success', 'Post created!');
 }

 public function apiIndex(): never
 {
 $posts = Post::paginate(10);
 $this->json(['data' => $posts], 200);
 }
}
```

### Middleware

```php
<?php
// app/Middleware/AuthMiddleware.php
namespace Canyon\Middleware;

use Canyon\Core\Middleware;
use Canyon\Core\Request;
use Canyon\Core\Response;

class AuthMiddleware extends Middleware
{
 public function handle(Request $request, \Closure $next): Response
 {
 if (!auth()->check()) {
 return Response::redirect('/login');
 }
 return $next($request);
 }
}

// app/Middleware/AdminMiddleware.php
class AdminMiddleware extends Middleware
{
 public function handle(Request $request, \Closure $next): Response
 {
 if (!Rbac::can(auth()->id, 'admin.access')) {
 abort(403, 'Access denied');
 }
 return $next($request);
 }
}
```

---

## Database & Models

### Entity (Active Record)

```php
<?php
// app/Models/Post.php
namespace Canyon\Models;

use Canyon\Core\Entity;

class Post extends Entity
{
 protected string $table = 'posts';
 protected array $fillable = ['title', 'body', 'author_id', 'status', 'excerpt'];
 protected array $hidden = ['deleted_at'];
 protected array $casts = [
 'id' => 'int',
 'author_id' => 'int',
 'is_published' => 'boolean',
 'views_count' => 'int',
 'meta' => 'json',
 'published_at' => 'datetime',
 ];

 // Relationships
 public function author(): ?Entity
 {
 return User::find($this->author_id);
 }

 // Scopes
 public static function published(): Collection
 {
 return self::where('status', 'published');
 }

 // Accessors
 public function getExcerptAttribute(): string
 {
 return str_limit($this->body, 150);
 }
}
```

### Database Queries

```php
<?php
use Canyon\Core\Database;

// Simple select
$users = Database::select('users', ['*'], ['active' => 1], 20);

// Insert
$id = Database::insert('posts', [
 'title' => 'Hello World',
 'body' => 'My first post',
 'author_id' => 1,
]);

// Update
Database::update('users', ['last_login' => time()], ['id' => 123]);

// Delete
Database::delete('sessions', ['last_activity' < time() - 3600]);

// Transactions
Database::transaction(function () {
 $orderId = Database::insert('orders', $orderData);
 Database::insert('order_items', $items);
 Database::update('products', ['stock' => 'stock - 1'], ['id' => $productId]);
});

// Raw queries
$results = Database::statement('SELECT * FROM users WHERE created_at > ?', [strtotime('-30 days')]);
```

### Migrations

```php
<?php
// database/migrations/2024_01_01_create_posts_table.php
use Canyon\Core\Migration;

class CreatePostsTable extends Migration
{
 public function up(): void
 {
 $this->createTable('posts', [
 'id' => 'int|unsigned|auto_increment|primary',
 'user_id' => 'int|unsigned|index',
 'title' => 'varchar:255',
 'slug' => 'varchar:255|unique',
 'body' => 'text',
 'status' => "enum('draft','published','archived')|default:draft",
 'meta' => 'json|nullable',
 'created_at' => 'timestamp|default:current_timestamp',
 'updated_at' => 'timestamp|default:current_timestamp|on_update:current_timestamp',
 ]);
 }

 public function down(): void
 {
 $this->dropTable('posts');
 }
}
```

---

## Views & Templates

### Creating Views

```php
<?php // resources/views/posts/index.php ?>
<?php $this->layout('layouts.app') ?>

<?php $this->section('title') ?>Posts<?php $this->endSection() ?>

<?php $this->section('content') ?>
<div class="posts">
 <?php foreach ($posts as $post): ?>
 <article class="post-card">
 <h2><a href="<?= route_path('posts.show', ['id' => $post->id]) ?>">
 <?= e($post->title) ?>
 </a></h2>
 <p class="excerpt"><?= e($post->excerpt) ?></p>
 <div class="meta">
 <span>By <?= e($post->author->name) ?></span>
 <span><?= human_time($post->created_at) ?></span>
 </div>
 </article>
 <?php endforeach; ?>
</div>

<?= $pagination->links() ?>
<?php $this->endSection() ?>
```

### Layouts

```php
<?php // resources/views/layouts/app.php ?>
<!DOCTYPE html>
<html lang="<?= app()->getLocale() ?>">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title><?= $this->yield('title') ?> - <?= e(config('app.name')) ?></title>
 <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
 <?= $this->yield('head') ?>
</head>
<body>
 <?= $this->include('partials.nav') ?>

 <main>
 <?php if ($message = flash('success')): ?>
 <div class="alert alert-success"><?= e($message) ?></div>
 <?php endif; ?>

 <?= $this->yield('content') ?>
 </main>

 <?= $this->include('partials.footer') ?>
 <script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
```

---

## Security

### Web Application Firewall (WAF)

Canyon includes a built-in WAF. Enable it in `config.php`:

```php
define('CANYON_WAF_ENABLED', true);
```

```php
<?php
// app/Middleware/WafMiddleware.php
class WafMiddleware extends Middleware
{
 public function handle(Request $request, \Closure $next): Response
 {
 if (!CANYON_WAF_ENABLED) {
 return $next($request);
 }

 // Check all WAF rules
 \Waf::inspect($request);

 // Inspect returned 403 or passes through
 return $next($request);
 }
}
```

WAF protects against:
- SQL injection
- Cross-site scripting (XSS)
- Path traversal
- Command injection
- Rate-based attacks

### CSRF Protection

```php
<!-- In forms: -->
<form method="POST" action="/posts">
 <?= Security::csrfField() ?>
 <!-- or -->
 <input type="hidden" name="_token" value="<?= Security::csrfToken() ?>">
 ...
</form>

// In API routes:
Router::post('/api/posts', [PostController::class, 'store'])
 ->middleware('csrf');
```

### Input Validation

```php
<?php
// In controllers
$data = $this->validate([
 'email' => 'required|email|unique:users',
 'password' => 'required|string|min:8|confirmed',
 'age' => 'required|integer|min:18|max:120',
 'website' => 'nullable|url',
 'role' => 'required|in:admin,editor,user',
 'tags' => 'array',
 'tags.*' => 'string|max:50',
]);

// Manual validation
$validator = new Validation($data, [
 'email' => 'required|email',
 'password' => 'required|min:8',
]);

if ($validator->fails()) {
 return $this->json(['errors' => $validator->errors()], 422);
}
```

### Output Escaping

```php
<?php // Views auto-escape by default ?>
<?= $user->name ?> <!-- Safe: auto-escaped -->
<?= $user->bio ?> <!-- Safe: auto-escaped -->

// For trusted HTML (admin content):
<?= View::raw($trustedHtml) ?> <!-- Raw output - use carefully! -->
```

---

## Authentication & Authorization

### User Authentication

```php
<?php
// Login
$user = User::find($_POST['email']);
if ($user && password_verify($_POST['password'], $user->password)) {
 auth()->login($user);
 return redirect('/dashboard');
}

// Register
$user = new User();
$user->email = $_POST['email'];
$user->name = $_POST['name'];
$user->password = password_hash($_POST['password'], PASSWORD_ARGON2ID);
$user->save();

// Logout
auth()->logout();
```

### RBAC (Role-Based Access Control)

```php
<?php
// Assign roles
Rbac::assign('admin', $user->id);
Rbac::assign('editor', $user->id);

// Check permissions
if (Rbac::can($user->id, 'posts.publish')) {
 // Allow publishing
}

// Check in middleware
class AdminMiddleware extends Middleware
{
 public function handle(Request $request, \Closure $next): Response
 {
 if (!Rbac::can(auth()->id, 'admin.access')) {
 abort(403);
 }
 return $next($request);
 }
}
```

---

## Plugin Development

### Plugin Structure

```
plugins/
└── my-plugin/
 ├── canyon-plugin.php # Plugin config (required)
 ├── src/
 │ ├── MyPlugin.php # Main plugin class
 │ └── Controllers/
 │ └── PluginController.php
 ├── views/
 │ └── settings.php
 └── composer.json
```

### Plugin Configuration

```php
<?php
// plugins/my-plugin/canyon-plugin.php
return [
 'plugin' => [
 'name' => 'My Plugin',
 'description' => 'Does something useful',
 'version' => '1.0.0',
 'author' => 'Your Name',
 'active' => true,
 ],

 // Plugin routes
 'routes' => [
 'my_plugin_index' => [
 'path' => '/my-plugin',
 'method' => 'GET',
 'controller' => [PluginController::class, 'index'],
 ],
 ],

 // Plugin widgets
 'widgets' => [
 'my_widget' => [
 'name' => 'My Widget',
 'callback' => fn($data) => '<div>Widget!</div>',
 'options' => ['access' => 'logged_in'],
 ],
 ],

 // Plugin settings
 'settings' => [
 'api_key' => '',
 'enabled' => true,
 ],

 // Plugin events
 'events' => [
 'user.registered' => [MyPlugin::class, 'onUserRegistered'],
 ],
];
```

### Plugin Class

```php
<?php
// plugins/my-plugin/src/MyPlugin.php
namespace MyPlugin;

class MyPlugin
{
 public static function boot(): void
 {
 // Plugin initialization
 }

 public static function onUserRegistered(array $data): void
 {
 // Handle event
 }
}
```

---

## AI Integration

### Configuration

Edit `config/ai.php`:

```php
'providers' => [
 'openai' => [
 'api_key' => 'sk-your-real-key',
 'model' => 'gpt-4o',
 ],
 'openrouter' => [
 'api_key' => 'sk-or-your-real-key',
 'model' => 'openai/gpt-4o',
 ],
],
```

### Using AI in Controllers

```php
<?php
use Canyon\Core\AiHelper;

class AiController extends Controller
{
 use AiHelper;

 public function chat(): never
 {
 $message = $this->input('message');
 $response = $this->aiChat($message, [
 'system' => 'You are a helpful assistant.',
 ]);

 $this->json([
 'reply' => $response->content,
 'tokens' => $response->tokensUsed,
 ]);
 }

 public function summarize(): never
 {
 $text = $this->input('text');
 $summary = $this->aiSummarize($text);
 $this->json(['summary' => $summary]);
 }

 public function code(): never
 {
 $code = $this->aiGenerateCode(
 'Create a REST API endpoint for user registration',
 'php'
 );
 $this->json(['code' => $code]);
 }
}
```

### AI Chatbot Widget

```php
<?php
// In a service provider or routes file
AiChatbot::register('bottom-right', [
 'title' => 'AI Assistant',
 'welcome_message' => 'How can I help you?',
]);
```

---

## Social Features

### User Profiles

```php
<?php
// Create profile
\UserProfile::create($user->id, [
 'bio' => 'Software developer',
 'location' => 'New York',
 'website' => 'https://example.com',
]);

// Get profile
$profile = \UserProfile::get($user->id);

// Update profile
\UserProfile::update($user->id, ['bio' => 'New bio']);

// Upload avatar
\UserProfile::uploadAvatar($user->id, $_FILES['avatar']);

// Upload cover photo
\UserProfile::uploadCover($user->id, $_FILES['cover']);
```

### Friend System

```php
<?php
// Send friend request
\MessageSystem::sendFriendRequest($fromUserId, $toUserId);

// Accept request
\MessageSystem::acceptFriendRequest($requestId);

// Get friends
$friends = \MessageSystem::getFriends($userId);

// Check if friends
$isFriends = \MessageSystem::isFriend($userId1, $userId2);

// Block user
\MessageSystem::blockUser($userId, $blockedUserId);

// Get blocked users
$blocked = \MessageSystem::getBlockedUsers($userId);
```

### Private Messages

```php
<?php
// Send message
\MessageSystem::sendMessage($fromUserId, $toUserId, 'Hello!');

// Get conversations
$conversations = \MessageSystem::getConversations($userId);

// Get messages in conversation
$messages = \MessageSystem::getMessages($conversationId, [
 'limit' => 20,
 'before' => $lastMessageId,
]);

// Mark as read
\MessageSystem::markAsRead($conversationId, $userId);

// Get unread count
$unread = \MessageSystem::getUnreadCount($userId);
```

### Activity Feed (Wall Posts)

```php
<?php
// Create post
$post = \ActivityFeed::post($userId, [
 'content' => 'Hello world!',
 'type' => 'text', // text, image, video, link
]);

// Get feed
$feed = \ActivityFeed::getFeed([
 'user_id' => $userId,
 'limit' => 20,
 'offset' => 0,
]);

// Like/unlike
\ActivityFeed::like($postId, $userId);
\ActivityFeed::unlike($postId, $userId);

// Comment
\ActivityFeed::comment($postId, $userId, 'Great post!');

// Share
\ActivityFeed::share($postId, $userId, 'Check this out!');

// Delete post
\ActivityFeed::delete($postId, $userId);
```

### Groups

```php
<?php
// Create group
$group = \GroupSystem::create([
 'name' => 'PHP Developers',
 'description' => 'A group for PHP enthusiasts',
 'privacy' => 'public', // public, private, secret
 'owner_id' => $userId,
]);

// Join group
\GroupSystem::join($groupId, $userId);

// Leave group
\GroupSystem::leave($groupId, $userId);

// Post in group
\GroupSystem::post($groupId, $userId, 'Welcome everyone!');

// Get group members
$members = \GroupSystem::getMembers($groupId);

// Get groups for user
$groups = \GroupSystem::getUserGroups($userId);
```

---

## Plugin Architecture (Modular Design)

The Canyon Framework follows a **modular and core-first principle**. The core framework provides minimal essential functionality, and features like social networking are delivered as **optional plugins**.

### Using the Social Plugin

```bash
# The social features are bundled as a plugin in /plugins/social/
# Enable it by copying to your app:
cp -r plugins/social/ your-app/plugins/

# Or use Composer:
composer require canyon/social-plugin
```

### Building Your Own Social Feature

Don't want the built-in social plugin? Build your own:

```php
<?php
// In your plugin's canyon-plugin.php
return [
 'plugin' => [
 'name' => 'Custom Social',
 'version' => '1.0.0',
 'active' => true,
 ],
 'services' => [
 'social' => CustomSocialService::class,
 ],
 'routes' => [
 'custom_profile' => [
 'path' => '/profile/{id}',
 'handler' => [ProfileController::class, 'show'],
 ],
 ],
];
```

### Core vs Plugin Classes

| Class | Location | Replaceable |
|-------|----------|-------------|
| `Router`, `Controller`, `Database`, `Security` | `app/Core/` | ❌ Core framework |
| `Waf` | `app/Core/` | ✅ Extend via middleware |
| `UserProfile`, `MessageSystem`, `ActivityFeed`, `GroupSystem` | `plugins/social/src/` | ✅ Full plugin |
| Your models/controllers | `app/Models/`, `app/Controllers/` | ✅ Yes |

### Extending Without Modifying Core

```php
<?php
// Create middleware in app/Middleware/
class AuthMiddleware extends Middleware
{
 public function handle($request, $next) {
 // Your auth logic
 return $next($request);
 }
}

// Register in routes/web.php
Router::group([AuthMiddleware::class], function () {
 // Protected routes
});

// Create services in app/Services/
class PaymentService {
 public function process($amount, $user) {
 // Your payment logic
 }
}
```

**Key Principle**: Never modify `app/Core/` for business logic. Use `app/Models/`, `app/Controllers/`, `app/Middleware/`, `app/Services/` for application code.

### Contracts (Interfaces)

The framework provides interfaces for all major features, allowing you to swap implementations without changing your application code:

| Interface | Location | Default Implementation |
|-----------|----------|----------------------|
| `ProfileInterface` | `app/Contracts/ProfileInterface.php` | `UserProfile` |
| `MessagingInterface` | `app/Contracts/MessagingInterface.php` | `MessageSystem` |
| `ActivityInterface` | `app/Contracts/ActivityInterface.php` | `ActivityFeed` |
| `GroupInterface` | `app/Contracts/GroupInterface.php` | `GroupSystem` |

**Custom Implementation Example:**

```php
<?php
// app/Services/CustomProfileService.php
namespace App\Services;

use Canyon\Contracts\ProfileInterface;

class CustomProfileService implements ProfileInterface
{
 public function create(int $userId, array $data): bool { /* ... */ }
 public function get(int $userId): ?array { /* ... */ }
 public function update(int $userId, array $data): bool { /* ... */ }
 // ... implement all interface methods
}

// Register in a service provider or public/index.php
\Container::singleton(ProfileInterface::class, CustomProfileService::class);
```

---

## Performance Optimization

### Caching

```php
<?php
use Canyon\Core\Cache;

// Basic cache
Cache::put('key', $value, 3600);
$value = Cache::get('key', 'default');

// Remember (get or compute)
$users = Cache::remember('users.all', fn() => Database::select('users'), 3600);

// Cache tags
Cache::tags(['users', 'admin'])->put('stats', $stats, 3600);

// Flush cache
Cache::flush();
```

### Asset Optimization

```php
<?php
use Canyon\Core\AssetOptimizer;

// Minify CSS
$minifiedCss = AssetOptimizer::minifyCss($css);

// Minify JS
$minifiedJs = AssetOptimizer::minifyJs($js);

// Lazy-load image
$lazyImage = AssetOptimizer::lazyImage('/uploads/photo.jpg', 'Description');

// Critical CSS
$criticalCss = AssetOptimizer::generateCriticalCss($html, 'app.css');

// Versioned assets (for cache busting)
$versionedUrl = AssetOptimizer::versioned('/css/app.css');
```

### Image Optimization

```php
<?php
use Canyon\Core\ImageOptimizer;

// Auto-optimize on upload
$metadata = ImageOptimizer::optimizeUpload($_FILES['photo'], 'images');

// Generate WebP/AVIF versions
$webp = ImageOptimizer::generateWebP($sourcePath, 'images', $filename);
$avif = ImageOptimizer::generateAvif($sourcePath, 'images', $filename);

// Responsive images
$variants = ImageOptimizer::generateResponsive($sourcePath, 'images', $filename);

// Picture element with fallbacks
echo ImageOptimizer::picture($metadata['url'], $metadata, 'Alt text');
```

### Database Optimization

```php
<?php
// Clean up old data
$optimizer = new DatabaseOptimizer();
$optimizer->cleanSessions();
$optimizer->cleanCache();
$optimizer->cleanQueue();
$optimizer->cleanNotifications();
$optimizer->runAllCleanup(); // Run all

// Optimize tables
$optimizer->optimizeTables();
```

---

## Deployment

### Production Checklist

```php
// config.php
define('CANYON_ENV', 'production');
define('CANYON_DEBUG', false);
define('CANYON_LOG_ERRORS', true);
define('CANYON_CACHE_ENABLED', true);
define('CANYON_ASSET_MINIFY', true);
define('CANYON_WAF_ENABLED', true);
```

### Nginx Configuration

```nginx
server {
 listen 80;
 server_name yourdomain.com;
 root /var/www/my-app/public;
 index index.php;

 # Security headers
 add_header X-Frame-Options DENY always;
 add_header X-Content-Type-Options nosniff always;

 # Block sensitive files
 location ~ /\.env { deny all; return 404; }
 location ~ ^/(app|config|storage|routes|resources|vendor)/ { deny all; return 404; }

 # Static files
 location ~* \.(css|js|png|jpg|jpeg|gif|svg|webp|avif|ico|woff2?|ttf)$ {
 expires 1y;
 add_header Cache-Control "public, immutable";
 }

 # PHP
 location ~ \.php$ {
 fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
 fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
 include fastcgi_params;
 }

 # Front controller
 location / {
 try_files $uri $uri/ /index.php?$query_string;
 }
}
```

### Cron Jobs

```bash
# /etc/cron.d/canyon
* * * * * www-data cd /var/www/my-app && php canyon cron >> /dev/null 2>&1
0 * * * * www-data cd /var/www/my-app && php canyon queue work >> /dev/null 2>&1
0 0 * * * www-data cd /var/www/my-app && php canyon clear-cache >> /dev/null 2>&1
```

---

## API Reference

### Core Classes

| Class | Namespace | Purpose |
|-------|-----------|---------|
| `Container` | `Canyon\Core` | Dependency injection |
| `Application` | `Canyon\Core` | App state |
| `Config` | `Canyon\Core` | Configuration |
| `Request` | `Canyon\Core` | HTTP request |
| `Response` | `Canyon\Core` | HTTP response |
| `Router` | `Canyon\Core` | Routing |
| `Middleware` | `Canyon\Core` | Middleware base |
| `Validation` | `Canyon\Core` | Input validation |
| `Database` | `Canyon\Core` | PDO wrapper |
| `Entity` | `Canyon\Core` | Active Record |
| `Collection` | `Canyon\Core` | Collection helper |
| `Migration` | `Canyon\Core` | DB migrations |
| `View` | `Canyon\Core` | Template rendering |
| `Partial` | `Canyon\Core` | View sections |
| `Security` | `Canyon\Core` | CSRF, XSS, rate limiting |
| `AccessControl` | `Canyon\Core` | Role-based access |
| `Rbac` | `Canyon\Core` | RBAC with hierarchy |
| `Permissions` | `Canyon\Core` | Entity permissions |
| `Hmac` | `Canyon\Core` | HMAC signatures |
| `EventDispatcher` | `Canyon\Core` | Event system |
| `Queue` | `Canyon\Core` | Job queue |
| `Cache` | `Canyon\Core` | Caching |
| `Email` | `Canyon\Core` | Email sending |
| `Notification` | `Canyon\Core` | Notifications |
| `FileStorage` | `Canyon\Core` | File storage |
| `I18n` | `Canyon\Core` | Internationalization |
| `RPC` | `Canyon\Core` | JSON-RPC 2.0 |
| `Settings` | `Canyon\Core` | Settings management |
| `Widget` | `Canyon\Core` | Dashboard widgets |
| `Menu` | `Canyon\Core` | Menu system |
| `Themes` | `Canyon\Core` | Theme management |
| `Plugin` | `Canyon\Core` | Plugin system |
| `Search` | `Canyon\Core` | Search |
| `River` | `Canyon\Core` | Activity stream |
| `Cron` | `Canyon\Core` | Scheduled tasks |
| `PageOwnership` | `Canyon\Core` | Page ownership |
| `Console` | `Canyon\Core` | CLI commands |
| `ProgressBar` | `Canyon\Core` | CLI progress |
| `Finder` | `Canyon\Core` | File finder |
| `Process` | `Canyon\Core` | System commands |
| `Lock` | `Canyon\Core` | Distributed locking |
| `Waf` | `Canyon\Core` | Web Application Firewall |
| `UserProfile` | `Canyon\Core` | User profiles |
| `MessageSystem` | `Canyon\Core` | Private messaging |
| `ActivityFeed` | `Canyon\Core` | Wall posts/feed |
| `GroupSystem` | `Canyon\Core` | User groups |
| `Ai` | `Canyon\Core` | AI client |
| `AiResponse` | `Canyon\Core` | AI response |
| `AiHelper` | `Canyon\Core` | AI trait |
| `AiChatbot` | `Canyon\Core` | AI chatbot widget |
| `AssetOptimizer` | `Canyon\Core` | Asset optimization |
| `ImageOptimizer` | `Canyon\Core` | Image optimization |
| `DatabaseOptimizer` | `Canyon\Core` | DB optimization |
| `Opcache` | `Canyon\Core` | OPcache management |
| `Heartbeat` | `Canyon\Core` | Heartbeat control |
| `PreloadCrawler` | `Canyon\Core` | Cache preloading |

### Helper Functions

| Function | Purpose |
|----------|---------|
| `env($key, $default)` | Get environment variable |
| `config($key, $default)` | Get configuration value |
| `asset($path)` | Generate asset URL |
| `route_path($name, $params)` | Generate route URL |
| `view($name, $data)` | Render a view |
| `abort($code, $message)` | Abort with error |
| `dd($var)` | Dump and die |
| `e($string)` | Escape HTML entities |
| `csrf_token()` | Get CSRF token |
| `csrf_field()` | Get CSRF hidden input |
| `old($key, $default)` | Get old input value |
| `flash($key, $value)` | Set flash message |
| `back()` | Redirect back |
| `now()` | Current timestamp |
| `human_time($timestamp)` | Human-readable time |

---

**Framework Version**: 0.5.0
**PHP Compatibility**: 8.1, 8.2, 8.3
**GitHub**: https://github.com/SunilSherkhane/Canyon-Framework
**License**: MIT