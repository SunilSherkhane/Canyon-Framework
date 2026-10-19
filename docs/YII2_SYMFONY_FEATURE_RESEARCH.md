# Yii2 + Symfony → Canyon Framework: Feature Research & Implementation Guide

**Purpose:** map every Yii2 and Symfony feature worth adopting into Canyon (`/home/sunilsherkhane/htdocs/canyon`), with Canyon-specific class/method signatures, gaps in the current codebase, and a build order.

**Canyon conventions assumed throughout:** `namespace Canyon\Core;`, one class per file in `app/Core/`, static façade API for cross-cutting services, PSR-4 `Canyon\ → app/`, PHP 8.1+, constants `CANYON_BASE_PATH`, `CANYON_STORAGE_PATH`, `CANYON_RESOURCES_PATH`.

---

## 0. Current Canyon baseline (verified by reading the source)

| Area | Existing class | Shape | Notes |
|---|---|---|---|
| DI | `Container` (140 L) | static `bind/singleton/singletonInstance/contextual/make` | no autowiring, no interface→impl scanning, no tags |
| App | `Application` | static init + `getSharedData()` | no environment/kernel lifecycle, no container wiring |
| Config | `Config` | dot-notation, `env()` helper | no nested arrays from files, no caching |
| HTTP | `Router` (463 L), `Middleware` (17 L) | static route table, abstract `Middleware::handle($request, Closure $next)` | no `Request`/`Response` objects, no content negotiation, no `OPTIONS`/`HEAD` handling, no rate limiting on routes |
| Data | `Database` (294 L) raw PDO, `Entity` (357 L), `Migration` (251 L) | hand-written SQL; `Entity::find($className, $id)` | **broken API**: `find()`/`first()`/`all()` require a `$className` arg but are called as `Post::find(1)` in docs/README; no relations, no query builder, no events, no soft delete |
| Validation | `Validation` (146 L) | pipe-rule strings, `match` on rule names | `min`/`max` compare numerically even for strings; `array`/`object` rules absent; no nested arrays (`posts.*.title`); no conditional (`required_if`); no `Sometimes`; no per-field multiple messages |
| Security | `Security` (487 L) | CSRF, host allow-list, redirect sanitization, throttle (session only) | no password hashing abstraction, no firewall, no voter, no login throttling, no remember-me, no 2FA |
| AuthZ | `AccessControl` (217 L), `Permissions` (110 L) | static role lists, env-based admin allow-list | roles hardcoded in `getUserRoles()`; groups in-memory only (not persisted); no hierarchy resolution; no permissions table; no voter strategy |
| Events | `EventDispatcher` (95 L) | `listen(event, cb, priority)`, `dispatch(event, payload)` | no stop-propagation, no wildcard events, no listener/provider registration from plugins, no async |
| Queue | `Queue` (212 L) | file-based `.job` serialize, `push/pop/process/work/fail` | no transports, no retry/backoff, no `tries`/`timeout`, no failed-job table, no delayed-by-queue, no batch |
| Cache | `Cache` (139 L) | file cache, TTL, serialize | no PSR-6/16, no tags, no stampede protection (mutex/early expiry), no atomic increment, no multi-get |
| UI | `View` (212 L), `Partial`, `Widget` (205 L), `Themes`, `Menu` | static registry widgets | widgets are closures, not classes; no `begin()/end()` buffering widget pattern; no fragment/ESI caching; no component abstraction |
| CLI | `canyon` binary, ad-hoc `switch` | hardcoded commands | no `Command` base class, no signature/option parsing, no `Input/Output`, no `--env`, no interactive input, no progress bars |
| I18n | `I18n` (233 L) | per-locale PHP files, dot lookup | no pluralization rules, no `MessageSelector`, no fallback chain, no gettext, no validation error translation integration |
| Plugins | `Plugin` (269 L) | `canyon-plugin.php` returning array | no lifecycle hooks (`activate/deactivate/uninstall`), no dependency resolution, no asset publishing, no plugin-provided routes caching |
| Testing | `tests/bootstrap.php` (empty suites) | no framework-level helpers | no `TestCase`, no browser kit, no fixture support, no assertions library |

### Highest-value gaps to close first
1. `Entity` static API inconsistency (docs vs. code) - fix signature or fix docs.
2. No `Request`/`Response` value objects → every other feature (negotiation, caching, security, console) is harder.
3. No console `Command` abstraction → CLI can't scale past ~10 commands.
4. Validation rule semantics + nested-field support → required by Forms and REST.
5. Roles/permissions are in-memory → not usable in production.

---

## 1. Yii2 Feature Set → Canyon

### 1.1 Gii - Code Generator

**What it is.** A web module (`yii\gii\Module`) exposing generators behind `?r=gii`, IP-locked with `allowedIPs`, that introspect DB schema and writes AR classes, CRUD controllers, and search models from templates.

**Canyon implementation: `Canyon\Core\Generator` + `Canyon\Generators\*`**

```
app/Core/Generator.php          # registry + runner (static facade, dev-gated)
app/Generators/ModelGenerator.php
app/Generators/CrudGenerator.php
app/Generators/ControllerGenerator.php
app/Generators/MigrationGenerator.php
app/Generators/FormGenerator.php
```

- **Template engine**: PHP-based stub templates in `resources/stubs/{model,crud,controller}.stub` with `{{placeholders}}` replaced by `str_replace`. Do not invent a template DSL - reuse the `View` renderer with a `data` array.
- **Schema introspection**: add `Database::getSchema()` returning `['table' => ['columns' => [name => ['type','length','nullable','default','pk','autoIncrement']], 'indexes' => []]]`. Read from `information_schema` for MySQL, `PRAGMA table_info` for SQLite.
- **Dev gating**: `Generator::guard()` throws `RuntimeException` unless `env('APP_DEBUG')` is true **and** `Generator::isAllowedIp()` matches `allowedIPs` (default `127.0.0.1, ::1`). Mirror Yii's `allowedIPs` config exactly.
- **Generation flow**: `Generator::model($table, $class)` → `ModelGenerator::generate()` → write file to `app/Models/`. Track generated files in `storage/generated.log` so regeneration is idempotent and can be diffed.
- **CRUD generator** emits controller with `index/create/view/update/delete` actions, a search model, and views; wires `Router::resource()` (see §1.7) so the routes are registered automatically.
- **Validation of output**: never `eval()`. Write to a temp file inside the target dir, `require` it once in an isolated process if a syntax check is needed (`php -l`), then rename atomically.

**Code sketch:**
```php
namespace Canyon\Core;

class Generator
{
    public const STUB_DIR = CANYON_RESOURCES_PATH . '/stubs';

    public static function guard(): void
    {
        if (!env('APP_DEBUG', false)) {
            throw new \RuntimeException('Code generation is disabled outside debug mode.');
        }
        if (!self::isAllowedIp()) {
            throw new \RuntimeException('Your IP is not allowed to use the generator.');
        }
    }

    public static function model(string $table, string $class, string $namespace = 'App\\Models'): string
    {
        self::guard();
        $schema = (new Database())->getSchema();
        if (!isset($schema[$table])) {
            throw new \InvalidArgumentException("Unknown table: {$table}");
        }
        return ModelGenerator::generate($table, $class, $namespace, $schema[$table]);
    }

    public static function renderStub(string $stub, array $vars): string
    {
        $template = file_get_contents(self::STUB_DIR . '/' . $stub . '.stub');
        return str_replace(
            array_map(fn($k) => "{{{$k}}}", array_keys($vars)),
            array_values($vars),
            $template
        );
    }
}
```

**Why it matters for Canyon:** it converts the hand-written `Entity` subclasses (currently ~40 lines each) into generated code, and it is the natural companion to the schema-aware Query Builder (§1.3). Without it, the AR layer is a manual chore.

---

### 1.2 ActiveRecord Implementation

**Yii2 AR model.** `yii\db\ActiveRecord` with `find()`, `ActiveQuery`, `hasOne/hasMany`, `scopes`, `inverseOf`, `with()` eager loading, `indexBy`, `asArray`, `link()`, `via()`, transactional `save()` with validation, `beforeSave`/`afterSave`/`afterFind`, dirty attributes, optimistic locking via `optimisticLock()`, `isNewRecord`, `primaryKey()`, `tableName()`.

**Canyon implementation: rework `Canyon\Core\Entity` into a real AR**

```
app/Core/Entity.php                 # ActiveRecord base (instance API)
app/Core/Query.php                  # Query builder (fluent, immutable-ish)
app/Core/Relations/
    HasOne.php, HasMany.php, BelongsTo.php, BelongsToMany.php
app/Core/Relations/Relation.php     # base: one/many, eager/lazy, constraints
```

**Class structure:**
```php
abstract class Entity
{
    protected string $table = '';
    protected array $primaryKey = ['id'];
    protected array $fillable = [];
    protected array $guarded = ['id'];
    protected array $casts = [];
    protected array $hidden = [];        // serializer exclusion
    protected array $appends = [];       // computed accessors
    protected array $relations = [];     // loaded relation cache
    protected array $original = [];      // dirty-tracking snapshot

    // --- finder API (instance-style, static call) ---
    public static function query(): Query;
    public static function find(int|string $id): ?static;      // FIX: single arg
    public static function findOrFail(int|string $id): static; // throws ModelNotFound
    public static function all(array $columns = ['*']): Collection;
    public static function where(string $column, mixed $operator = null, mixed $value = null): Query;
    public static function firstOrCreate(array $attrs, array $values = []): static;
    public static function updateOrCreate(array $attrs, array $values = []): static;

    // --- persistence ---
    public function save(?bool $runValidation = true): bool;
    public function insert(array $attrs = []): bool;
    public function update(array $attrs = []): bool;
    public function delete(): bool;
    public function refresh(): static;
    public static function destroy(int|string ...$ids): int;

    // --- state ---
    public function isNewRecord(): bool;
    public function getDirty(): array;
    public function getOriginal(?string $key = null): mixed;
    public function wasChanged(string $key): bool;

    // --- relations ---
    public function hasOne(string $class, string $foreignKey, string $localKey = 'id'): Relation;
    public function hasMany(string $class, string $foreignKey, string $localKey = 'id'): Relation;
    public function belongsTo(string $class, string $foreignKey, string $ownerKey = 'id'): Relation;
    public function belongsToMany(string $class, string $pivotTable, string $fk, string $relatedFk): Relation;
    public function __get(string $name): mixed;   // lazy relation resolution
    public function getRelation(string $name): mixed;

    // --- serialization ---
    public function toArray(): array;
    public function toJson(int $flags = 0): string;
    public function fill(array $attrs): static;
    public function validate(): bool;             // runs Validation from $rules

    // --- events ---
    public function onBeforeSave(): void {}
    public function onAfterSave(bool $isNew): void {}
    public function onAfterFind(): void {}
    public function onBeforeDelete(): void {}
}
```

**Key implementation points:**
- **`save()` must wrap in a transaction** and call `validate()` first; on failure return `false` and populate `$errors` (from `Validation`).
- **Dirty tracking**: snapshot `$attributes` on `fill()`/`find()`; `getDirty()` diffs against `$original`. This is what makes `update()` write only changed columns - currently `update()` writes every attribute including unchanged ones.
- **`__get()` relation proxy**: if `$name` matches a declared relation method (`public function comments(): Relation`), instantiate and cache it in `$relations[$name]`. Otherwise return the attribute.
- **Eager loading**: `Post::query()->with('comments', 'author')->get()` → collect foreign keys, run one query per relation, then `link()` results back. This is the single biggest performance win over the current N+1-prone lazy loading.
- **Query builder `Query`**: `select/from/where/whereIn/whereBetween/orderBy/limit/offset/with/join/leftJoin/groupBy/having/count/sum/avg/min/max/exists/get/all/first/paginate` - all returning `Entity` instances by default, arrays with `asArray()`.
- **Fix the existing broken signature**: change `find(string $className, int $id)` → `find(int|string $id)`. Every caller (`Entity::find(1)`) and the skill/README already assume the one-arg form.

**Code sketch - Query + relations:**
```php
class Query
{
    protected string $model;
    protected array $wheres = [];
    protected array $with = [];
    protected array $orders = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected bool $asArray = false;

    public function __construct(string $model) { $this->model = $model; }

    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) { $value = $operator; $operator = '='; }
        $this->wheres[] = compact('column', 'operator', 'value');
        return $this;
    }

    public function with(string ...$relations): static
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    public function get(): Collection
    {
        $rows = $this->run();
        $models = array_map(fn($row) => $this->model::hydrate($row), $rows);
        if ($this->with) { $this->eagerLoad($models); }
        return new Collection($models);
    }

    protected function eagerLoad(array $models): void
    {
        foreach ($this->with as $name) {
            $relation = $models[0]->{$name}();
            $relation->eagerLoad($models);
        }
    }
}
```

---

### 1.3 Query Builder (Yii2 `yii\db\Query`)

Currently `Database` exposes only raw PDO + `select/insert/update/delete` helpers. Add a fluent builder on top so generators, search, and pagination share one SQL path.

```php
class QueryBuilder
{
    public function select(string|array $columns = '*'): static;
    public function from(string $table): static;
    public function where(string|array $column, mixed $op = null, mixed $val = null): static;
    public function andWhere(...): static;  // orWhere, whereIn, whereNotIn, whereBetween, whereNull, whereLike
    public function join(string $table, string $first, string $op, string $second, string $type = 'INNER'): static;
    public function orderBy(string|array $column, string $direction = 'ASC'): static;
    public function groupBy(string|array $columns): static;
    public function having(...): static;
    public function limit(int $n): static;
    public function offset(int $n): static;
    public function count(string $column = '*'): int;
    public function sum(string $column): float;
    public function avg(string $column): float;
    public function exists(): bool;
    public function get(): array;            // fetch all assoc
    public function first(): ?array;
    public function column(string $column = null): array;
    public function paginate(int $perPage, int $page = 1): array; // ['items'=>, 'total'=>, 'last_page'=>]
    public function toSql(): array;          // [sql, bindings] - critical for debugging
}
```

**Rules:** always parameterize (`?` placeholders + bindings array); quote identifiers with backticks (MySQL) or double quotes (SQLite) via a `Grammar` class; `toSql()` is required for the debug toolbar.

---

### 1.4 RBAC System

**Yii2 model.** `yii\rbac\ManagerInterface` (PhpManager / DbManager), items (`Role`, `Permission`, `Rule`), `auth_item` / `auth_item_child` / `auth_assignment` / `auth_rule` tables, `checkAccess($userId, $permission, $params)`, rules as classes with `execute()`, `can()`, `getRoles()`, `getPermissions()`, `addChild()`, `revoke()`, hierarchy, and `Yii::$app->user->can()`.

**Canyon implementation: replace the in-memory `AccessControl` with a persistent RBAC layer**

```
app/Core/Auth/RbacManager.php      # assign/revoke/checkAccess/addRole/addPermission/addRule
app/Core/Auth/Role.php
app/Core/Auth/Permission.php
app/Core/Auth/Rule.php             # interface: execute($user, $item, $params): bool
app/Core/Auth/Rules/OwnerRule.php  # example rule
```

```php
class RbacManager
{
    public static function addRole(string $name, string $description = ''): Role;
    public static function addPermission(string $name, string $description = ''): Permission;
    public static function addChild(string $parent, string $child): void;      // hierarchy
    public static function assign(string $role, int|string $userId): void;     // auth_assignment
    public static function revoke(string $role, int|string $userId): void;
    public static function revokeAll(int|string $userId): void;
    public static function getRolesByUser(int|string $userId): array;
    public static function checkAccess(int|string $userId, string $permission, array $params = []): bool;
    public static function getPermissionsByUser(int|string $userId): array;
    public static function getChildren(string $item): array;
}
```

**Schema (mirror Yii's, minus the `type` redundancy):**
```sql
CREATE TABLE auth_item (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  type TINYINT NOT NULL,            -- 1=role, 2=permission
  description TEXT,
  rule_name VARCHAR(64) NULL,
  created_at INT UNSIGNED, updated_at INT UNSIGNED
);
CREATE TABLE auth_item_child (
  parent VARCHAR(64) NOT NULL, child VARCHAR(64) NOT NULL,
  PRIMARY KEY (parent, child), FOREIGN KEY (parent) REFERENCES auth_item(name) ON DELETE CASCADE
);
CREATE TABLE auth_assignment (
  item_name VARCHAR(64) NOT NULL, user_id VARCHAR(64) NOT NULL,
  created_at INT UNSIGNED, PRIMARY KEY (item_name, user_id)
);
CREATE TABLE auth_rule ( name VARCHAR(64) PRIMARY KEY, data TEXT, created_at INT UNSIGNED );
```

**Implementation rules:**
- `checkAccess()` walks the permission's ancestors; for each, if a `rule_name` is set, instantiate `auth_rule.data` (serialized class) and call `execute()`. Rules must implement `Rule::execute($user, $item, $params): bool`.
- Cache the resolved role graph per request (`Cache::remember('rbac.'.$userId, ...)`) - RBAC lookups are hot-path.
- **Migrate `AccessControl`'s existing API** (`addRole`, `hasRole`, `inGroup`, `canAccess`) to delegate to `RbacManager` so plugins keep working, then deprecate.
- Persist groups as roles prefixed `group:` so `inGroup()` and RBAC share one store.

---

### 1.5 Behaviors

**Yii2 model.** `yii\base\Behavior` + `yii\base\Component::behaviors()`; attaching injects properties/methods via `__get`/`__call` and binds events. Built-ins: `TimestampBehavior`, `BlameableBehavior`, `SluggableBehavior`, `AttributeTypecastBehavior`, `OptimisticLockBehavior`. Filters (`AccessControl`, `VerbFilter`, `HttpCache`, `PageCache`, `RateLimiter`) are behaviors with `only`/`except`.

**Canyon implementation:**
```
app/Core/Behavior.php              # base: $owner, attach(), detach(), events()
app/Core/Behaviors/TimestampBehavior.php
app/Core/Behaviors/BlameableBehavior.php
app/Core/Behaviors/SluggableBehavior.php
app/Core/Behaviors/SoftDeleteBehavior.php
app/Core/Behaviors/OptimisticLockBehavior.php
app/Core/Behaviors/CacheableBehavior.php
```

```php
abstract class Behavior
{
    public object $owner;
    abstract public function events(): array;   // ['beforeSave' => 'touchTimestamps', ...]

    public function attach(object $owner): void { $this->owner = $owner; }
    public function detach(): void { unset($this->owner); }

    public function __get(string $name) { return $this->owner->{$name} ?? null; }
    public function __call(string $m, array $a) { return $this->owner->{$m}(...$a); }
}
```

**Attach points in `Entity`:** `Entity::behaviors(): array` returns `[]`; `save()`/`delete()`/`find()` iterate `behaviors()` and fire `events()`. `TimestampBehavior` sets `created_at`/`updated_at`; `BlameableBehavior` sets `created_by`/`updated_by` from `Session::user()`; `SoftDeleteBehavior` turns `delete()` into `UPDATE deleted_at = now()` and injects a `WHERE deleted_at IS NULL` default scope into `Query`.

**Filters** = behaviors on controllers. Canyon's `Middleware` is already the equivalent of `AccessControl`/`VerbFilter`; extend `Middleware` with an `only`/`except` property and a `Controller::middleware(): array` hook so filters can be declared per-controller.

---

### 1.6 Widgets

**Yii2 model.** `yii\base\Widget::widget($config)` returns HTML; `begin()`/`end()` capture output between them; `Widget::end()` renders. Bundle widgets: `ActiveForm`, `Menu`, `ListView`, `GridView`, `DetailView`, `Breadcrumbs`, `LinkPager`, `Tabs`, `Alert`.

**Canyon implementation: add a class-based widget layer on top of the existing `Widget` registry**

```
app/Core/Widget.php                # keep registry; add Widget::widget() for class widgets
app/Core/Widgets/ActiveForm.php
app/Core/Widgets/Menu.php
app/Core/Widgets/GridView.php
app/Core/Widgets/Alert.php
app/Core/Widgets/Pagination.php
app/Core/Widgets/DetailView.php
```

```php
abstract class BaseWidget
{
    protected array $config = [];
    protected string $content = '';

    public static function widget(array $config = []): string
    {
        $w = new static();
        $w->config = array_merge($w->defaults(), $config);
        return $w->run();
    }

    public static function begin(array $config = []): static
    {
        $w = new static();
        $w->config = array_merge($w->defaults(), $config);
        ob_start();
        return $w;
    }

    public function end(): string
    {
        $this->content = ob_get_clean();
        return $this->run();
    }

    abstract protected function run(): string;
    protected function defaults(): array { return []; }
}
```

**Key wins for Canyon:** `ActiveForm::begin()`/`end()` gives CSRF-injected forms with model binding and validation error rendering (`$form->field($model, 'title')`), which the current `Security::csrfField()` + manual `<form>` does not provide. `GridView` + `Pagination` give sortable, paginated tables - currently missing entirely.

---

### 1.7 REST API Support

**Yii2 model.** `yii\rest\ActiveController` with `$modelClass`, `UrlRule` auto-routing to REST verbs, `Serializer` (selectable fields, `expand` for relations, HATEOAS `_links`), content negotiation (JSON/XML), `OPTIONS`/`HEAD`, pagination headers (`X-Pagination-*`), rate limiting via `RateLimitInterface`, `QueryParamAuth`/`HttpBearerAuth`/`CompositeAuth`.

**Canyon implementation:**
```
app/Core/Rest/RestController.php     # base controller with CRUD actions
app/Core/Rest/Serializer.php         # fields(), expand(), links()
app/Core/Rest/ContentNegotiator.php  # picks format from Accept header
app/Core/Rest/RateLimiter.php        # per-user/IP sliding window
app/Core/Rest/Auth/QueryParamAuth.php, BearerAuth.php, BasicAuth.php
```

```php
class RestController extends Controller
{
    public string $modelClass = '';
    protected array $actions = ['index','view','create','update','delete','options'];

    public function index(): never    // GET    /users      -> paginated collection
    public function view(int $id): never   // GET    /users/1
    public function create(): never   // POST   /users
    public function update(int $id): never // PUT    /users/1
    public function delete(int $id): never // DELETE /users/1
    public function options(): never  // OPTIONS /users
}
```

**Routing:** add `Router::resource(string $name, string $controller)` and `Router::apiResource()` that expand to the six REST routes with the correct verbs, plus automatic `OPTIONS` responses carrying `Allow`. Content negotiation: `ContentNegotiator::negotiate(Request)` returns `json|xml|html` from `Accept`; `Serializer` respects `fields` and `expand` query params and emits `_links`/`_meta` for HATEOAS. Rate limiting must live **before** the controller (middleware) and return `429` with `Retry-After`.

---

### 1.8 i18n

**Yii2 model.** `Yii::t('app', 'Hello, {name}', ['name' => $n])`, categories (`app`, `yii`), `MessageSource` with `messages/<lang>/<category>.php`, `MessageFormatter`, `i18n` component config, `sourceLanguage`, fallback, `yii\i18n\Formatter` for dates/numbers/currency.

**Canyon implementation: extend `I18n`**
- **Pluralization:** implement `I18n::choice($key, int $number, array $replace = [])` with ICU-style categories (`one`, `few`, `many`, `other`) and per-locale rule functions (`resources/lang/<locale>/plurals.php` returning a callable). Required for real apps; the current `I18n` has no plural support at all.
- **Fallback chain:** `I18n::setFallbacks(['en'])` - on miss, walk the chain before returning the key.
- **Category support:** `I18n::get('validation.required', [...], 'validation')` → `resources/lang/en/validation.php`.
- **Formatter:** `I18n::format()->date($ts, 'long')`, `->number($n)`, `->currency($n, 'USD')`, `->percent($n)` - locale-aware, no `IntlDateFormatter` dependency if `ext-intl` is absent (fall back to `strftime`-style tables).
- **Message extraction:** `Generator::messages()` scans views for `I18n::get(` / `{{t}}` and emits a POT-ish PHP file so translators have a source of truth.
- **Vue/JS parity:** expose `I18n::js($locale)` returning a JSON object for client-side strings.

---

### 1.9 Caching

**Yii2 model.** `yii\caching\Cache` base with `get/set/delete/has/mget/mset/add/flush`, dependency injection (`DbDependency`, `FileDependency`, `ExpressionDependency`, `ChainedDependency`), `Yii::$app->cache` component, plus `yii\caching\PageCache` (whole-response) and `yii\filters\HttpCache` (ETag/Last-Modified).

**Canyon implementation: keep `Cache` façade, add contract + tags + stampede protection**
```
app/Core/Cache.php               # existing façade -> PSR-16-ish + tags
app/Core/Cache/Store/FileStore.php, RedisStore.php, ArrayStore.php, DatabaseStore.php
app/Core/Cache/CacheItem.php     # PSR-6 item
app/Core/Cache/TagSet.php        # tag -> key index for invalidation
```

- **Tag invalidation:** `Cache::tags(['posts', 'user:1'])->put(...)`; `Cache::tags('posts')->flush()` deletes every key recorded under that tag (store the mapping in a separate index key).
- **Stampede prevention (mutex):** `Cache::remember()` must, on miss, attempt to acquire a lock key `lock:<key>` (atomic `setnx`); if acquired, compute and store with an extended TTL; if not, sleep 50–200 ms and re-read. Without this, a hot key expiry triggers a thundering herd - the classic cache stampede.
- **PSR-16:** `get/set/has/delete/clear/getMultiple/setMultiple/deleteMultiple`.
- **Page cache:** `Cache::rememberPage($key, $ttl, fn() => ...)` storing rendered HTML in `storage/cache/pages/` with `Cache-Control` headers; invalidate via tags.

---

### 1.10 Security Features

**Yii2 model.** `yii\base\Security`: `generateRandomString`, `generatePasswordHash` (bcrypt/argon2), `validatePassword`, `encryptByPassword`/`decryptByPassword`, `hkdf`, `pbkdf2`, `compareString` (timing-safe), `maskToken`, `CSRF` (`yii\web\Request::$enableCsrfValidation` + `CsrfToken`), `yii\filters\RateLimiter`, `yii\web\Cookie`, `yii\web\Session`, `yii\web\User` with `login()`, `logout()`, `isGuest`, `getIdentity()`, `authTimeout`, `absoluteAuthTimeout`, `renewAuth`.

**Canyon implementation: extend `Security` (487 L) with a dedicated `Auth` namespace**
```
app/Core/Security.php                 # existing: CSRF, host, redirect, throttle
app/Core/Security/Hash.php            # bcrypt/argon2id, needsRehash, verify
app/Core/Security/Encrypter.php       # AES-256-CBC/GCM with MAC, key rotation
app/Core/Security/Csrf.php            # token, field, meta, per-session rotation
app/Core/Security/Password.php        # reset tokens, expiry
app/Core/Auth/Authenticator.php       # authenticate(), supports providers
app/Core/Auth/ProviderInterface.php   # retrieveByIdentifier(), validateCredentials()
app/Core/Auth/SessionAuthenticator.php
app/Core/Auth/TokenAuthenticator.php  # API tokens / bearer
app/Core/Auth/RememberMe.php          # long-lived cookie, hashed token in DB
app/Core/Auth/LoginThrottle.php       # per-user+IP, sliding window, lockout
```

**Concrete additions:**
- `Hash::make($pw)` → `password_hash($pw, PASSWORD_ARGON2ID)` when available, else bcrypt cost 12; `Hash::check()`; `Hash::needsRehash()`. The current `Entity::encrypt()` uses a raw `AES-256-CBC` with a derived key and **no MAC** - replace with `Encrypter` using `hash_hmac` Encrypt-then-MAC.
- `Csrf::token()`, `Csrf::field()` (hidden input), `Csrf::meta()` (for SPA fetch), `Csrf::verify()` (already exists - keep `hash_equals`), plus **per-request rotation** option and SameSite cookie attributes.
- `LoginThrottle::tooManyAttempts($key, $max = 5, $decay = 900)` + `hit()` + `availableIn()` - sliding window, keyed by `user|ip`, persisted in `Cache` (not session, which is cookie-rotatable - noted in the skill as a known weakness).
- `Authenticator::login($provider, $credentials)` returns an identity or throws `AuthenticationException`; on failure, `LoginThrottle::hit()`.
- **Session hardening:** `session.cookie_httponly`, `secure`, `samesite=Lax`, `session.use_strict_mode=1`, `session_regenerate_id(true)` on login, `session.gc_probability/divisor` (already flagged in the skill as missing).

---

### 1.11 Testing Tools

**Yii2 model.** Codeception-based: `yii\codeception\TestCase`, `yii\test\Fixture`, `yii\test\ActiveFixture` (loads AR fixtures), `yii\test\FileFixture`, `yii\test\InitDbFixture`, `yii\web\TestRequest`/`TestResponse` for simulating requests, `yii\debug\Module` for the debug toolbar.

**Canyon implementation:**
```
tests/TestCase.php          # base: bootstraps Application, creates temp DB, wraps transactions
tests/Fixtures/Fixture.php  # abstract: table, rows, load(), unload()
tests/Fixtures/ActiveFixture.php
tests/BrowserKit.php        # Request/Response simulation without a web server
tests/Assertions/ResponseAssertions.php, DatabaseAssertions.php
```

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        Application::init();
        Config::init();
        $this->beginTransaction();          // each test in a rolled-back txn
    }
    protected function tearDown(): void
    {
        $this->rollBack();
    }

    protected function get(string $uri): TestResponse
    protected function post(string $uri, array $data = []): TestResponse
    protected function json(string $method, string $uri, array $data = []): TestResponse
    protected function assertDatabaseHas(string $table, array $attrs): void
    protected function assertStatus(int $code): void
}
```

- **Fixtures:** `Fixture` loads rows from `tests/Fixtures/data/<name>.php`, truncates first. `ActiveFixture` hydrates via the AR `Entity::fill()`.
- **Test DB:** `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` in `phpunit.xml`; `Migration::migrate()` in `setUpBeforeClass()`.
- **BrowserKit:** build a `Request` from `$_SERVER` overrides, run `Router::dispatch()`, capture output + status. This is what makes controller tests possible without `php -S`.

---

### 1.12 Debug Toolbar

**Yii2 model.** `yii\debug\Module` + `yii\debug\panels\*` (log, db, timeline, mail, request, config, routing, user, profiling), rendered as a fixed bottom bar, storing requests in `runtime/debug`, IP-gated via `allowedIPs`.

**Canyon implementation:**
```
app/Core/Debug/DebugBar.php       # collect() on kernel.terminate, render() on response
app/Core/Debug/Panel.php          # abstract: getName(), getData(), getSummary()
app/Core/Debug/Panels/LogPanel.php, DbPanel.php, TimelinePanel.php, RoutePanel.php,
                   ConfigPanel.php, RequestPanel.php, ProfilePanel.php
```

- **Collect points:** `Database` records every query (sql, bindings, duration, trace) into `DebugBar`; `Router` records matched route + middleware; `EventDispatcher` records dispatched events; `Cache` records hits/misses.
- **Render:** inject a `<div id="canyon-debug">` before `</body>` when `APP_DEBUG=true` and the request accepts HTML; store the full payload to `storage/debug/<id>.json` and load it from the toolbar's own route.
- **Dev gating:** reuse `Generator::guard()`'s IP allow-list pattern. Never render in production - check `env('APP_DEBUG')` first.
- **Timeline panel:** merge events from a shared `Timeline` collector (`start($label)`, `end($label)`) so `DebugBar` can draw a waterfall.

---

## 2. Symfony Feature Set → Canyon

### 2.1 HTTP Foundation (Request / Response)

**Symfony model.** `Request` with `ParameterBag` bags: `query` ($_GET), `request` ($_POST), `cookies`, `attributes` (app data), `files`, `server`, `headers`; helpers `getPathInfo`, `getMethod`, `getRealMethod`, `getContentType`, `getLanguages`, `getPreferredLanguage`, `isXmlHttpRequest`, `isSecure`, `getUri`, `getSchemeAndHttpHost`, `duplicate`, `createFromGlobals`, `overrideGlobals`. `Response` with `Content-Type`, `setStatusCode`, `setPublic/setPrivate/setMaxAge/setSharedMaxAge`, `setEtag`, `setLastModified`, `isNotModified(Request)`, `prepare(Request)`, `send()`, plus `JsonResponse`, `RedirectResponse`, `BinaryFileResponse`, `StreamedResponse`, `ResponseHeaderBag`.

**Canyon implementation - the single highest-leverage addition**
```
app/Core/Http/Request.php
app/Core/Http/Response.php
app/Core/Http/JsonResponse.php
app/Core/Http/RedirectResponse.php
app/Core/Http/ParameterBag.php
app/Core/Http/HeaderBag.php
app/Core/Http/FileBag.php
```

```php
class Request
{
    public ParameterBag $query;     // $_GET
    public InputBag $request;       // $_POST (InputBag: all strings/arrays, no object casting)
    public ParameterBag $attributes;// routing params + app data
    public ParameterBag $cookies;
    public FileBag $files;
    public ParameterBag $server;
    public HeaderBag $headers;

    public static function createFromGlobals(): static;
    public function create(string $uri, string $method = 'GET', array $parameters = [],
                           array $cookies = [], array $files = [], array $server = []): static;

    public function getPathInfo(): string;
    public function getBaseUrl(): string;
    public function getMethod(): string;          // resolved, honors _method spoof
    public function getRealMethod(): string;
    public function getContentType(): ?string;
    public function getAcceptableContentTypes(): array;
    public function getPreferredLanguage(array $locales = []): ?string;
    public function isXmlHttpRequest(): bool;
    public function isSecure(): bool;
    public function getUri(): string;
    public function getSchemeAndHttpHost(): string;
    public function getClientIp(): string;
    public function duplicate(?array $query = null, ?array $request = null): static;
    public function get(string $key, mixed $default = null): mixed;      // merged query+request
    public function post(string $key, mixed $default = null): mixed;
    public function input(string $key, mixed $default = null): mixed;
}

class Response
{
    public function __construct(string $content = '', int $status = 200, array $headers = []);
    public function setStatusCode(int $code): static;
    public function setContent(?string $content): static;
    public function setPublic(): static;
    public function setPrivate(): static;
    public function setMaxAge(int $value): static;
    public function setSharedMaxAge(int $value): static;
    public function setEtag(?string $etag, bool $weak = false): static;
    public function setLastModified(?\DateTimeInterface $date): static;
    public function isNotModified(Request $request): bool;
    public function prepare(Request $request): static;   // sets Content-Type, Vary, etc.
    public function send(): static;                       // headers + echo
}
```

**Why it matters:** `Router::dispatch()` currently reads `$_GET`/`$_SERVER` directly and `Controller::json()` calls `header()` + `exit`. Introducing `Request`/`Response` lets `Middleware::handle(Request $request, Closure $next)` actually receive state, enables content negotiation, makes `Response::prepare()` handle `Vary`/`Content-Type` correctly, and makes the whole app testable via `Request::create()`.

---

### 2.2 Dependency Injection

**Symfony model.** `ContainerBuilder` compiled to PHP, autowiring by type-hint, autoconfiguration via interfaces, service tags, service locators, `bind()` for default arguments, `Definition`/`ChildDefinition`, `CompilerPassInterface`, environment-specific config, `ParameterBag`, `ContainerInterface` (`get`, `has`, `initialized`).

**Canyon implementation: upgrade `Container` with autowiring + reflection**
```
app/Core/Container.php          # existing façade + add autowiring, tags, aliases, factories
app/Core/Container/Definition.php
app/Core/Container/CompilerPassInterface.php
app/Core/Container/ContainerBuilder.php   # build + cache to storage/cache/container.php
```

```php
class Container
{
    public static function bind(string $id, mixed $concrete = null, bool $shared = false): void;
    public static function singleton(string $id, mixed $concrete = null): void;
    public static function instance(string $id, object $instance): void;
    public static function alias(string $alias, string $id): void;
    public static function tag(string $id, string $tag): void;
    public static function tagged(string $tag): array;
    public static function make(string $id, array $params = []): mixed;   // autowire
    public static function call(callable $fn, array $params = []): mixed; // resolve by reflection
    public static function has(string $id): bool;
}
```

**Implementation:** `make()` uses `ReflectionClass` on the target constructor; for each parameter, if it has a class type-hint, recursively `make()` it; if scalar, look in `$params` then `$this->parameters` (from `Config`). Cache the resolved singleton. `call()` does the same for arbitrary callables - this is what makes controller actions with typed dependencies work. **Compile & cache**: `ContainerBuilder::compile()` writes a PHP file returning a `Container` with closures; `Application::init()` loads it if `storage/cache/container.php` is newer than `config/`. Add `CompilerPassInterface` for plugin-provided service registration.

---

### 2.3 Console Component

**Symfony model.** `Application` + `Command` with `setName/setDescription/setHelp/addArgument/addOption/setCode`, `InputInterface` (`Argument`, `Option`, `ArgvInput`, `ArrayInput`), `OutputInterface` (`Output`, `ConsoleOutput`, `BufferedOutput`, `Table`, `ProgressBar`, `QuestionHelper`, `FormatterHelper`), `#[AsCommand]`, `ContainerCommandLoader`, `Tester` (`CommandTester`, `ApplicationTester`), events (`ConsoleEvents::COMMAND/TERMINATE/ERROR`), `--env`, `--no-interaction`, `--verbose`, `--quiet`.

**Canyon implementation - required, the current `canyon` binary is a `switch`**
```
app/Core/Console/Application.php
app/Core/Console/Command.php
app/Core/Console/Input/InputInterface.php, ArgvInput.php, ArrayInput.php, InputArgument.php, InputOption.php
app/Core/Console/Output/OutputInterface.php, ConsoleOutput.php, BufferedOutput.php
app/Core/Console/Helper/Table.php, ProgressBar.php, QuestionHelper.php, DescriptorHelper.php
app/Core/Console/Tester/CommandTester.php, ApplicationTester.php
app/Commands/ServeCommand.php, RoutesCommand.php, MakeCommand.php, MigrateCommand.php, ...
```

```php
abstract class Command
{
    protected string $name = '';
    protected string $description = '';
    protected string $help = '';
    protected array $arguments = [];
    protected array $options = [];

    protected function configure(): void;
    abstract protected function execute(InputInterface $input, OutputInterface $output): int;

    public function setName(string $name): static;
    public function addArgument(string $name, int $mode = self::ARGUMENT_OPTIONAL,
                               string $description = '', mixed $default = null): static;
    public function addOption(string $name, ?string $shortcut = null, int $mode = self::OPTION_NONE,
                              string $description = '', mixed $default = null): static;
    protected function argument(string $name): mixed;
    protected function option(string $name): mixed;
    protected function info(string $msg): void;
    protected function error(string $msg): void;
    protected function table(array $headers, array $rows): void;
    protected function ask(string $question, mixed $default = null): mixed;
    protected function confirm(string $question, bool $default = false): bool;
    protected function progressStart(int $max): void;
    protected function progressAdvance(int $step = 1): void;
    protected function progressFinish(): void;
}
```

**Migration path:** keep the existing `canyon <verb>` invocations as thin aliases (`canyon routes` → `RoutesCommand`) so nothing breaks. Add `--env=`, `--no-interaction`, `-v/-vv/-vvv`, `--quiet` globally. `CommandTester` makes every CLI command unit-testable.

---

### 2.4 Security Component (Firewalls, Voters)

**Symfony model.** `SecurityBundle` config: `providers` (user source), `firewalls` (pattern, `form_login`, `json_login`, `logout`, `remember_me`, `access_token`, `custom_authenticators`, `entry_point`), `access_control` rules, `encoders`/`password_hashers`, `UserInterface` / `UserPasswordHasherInterface`, `AuthenticationTrustResolver`, `AuthorizationChecker::isGranted`, `VoterInterface` + `Voter` with `supports()`/`voteOnAttribute()`, strategies `affirmative|consensus|unanimous|priority`, `AccessDeniedException` → `ExceptionListener` → `access_denied_handler`, `#[IsGranted]`, `Security::getUser()`, `TokenInterface`, `AuthenticationManager`, `AuthenticationProviderManager`, `SwitchUserListener`, `LogoutListener`, `SessionLogoutListener`.

**Canyon implementation - merge Symfony voters with Yii RBAC**
```
app/Core/Security/Firewall.php          # pattern -> authenticators -> access rules
app/Core/Security/AuthenticatorInterface.php
app/Core/Security/FormAuthenticator.php, JsonAuthenticator.php, TokenAuthenticator.php
app/Core/Security/UserProviderInterface.php
app/Core/Security/UserInterface.php      # getRoles(), getPassword(), getSalt(), eraseCredentials()
app/Core/Security/PasswordHasher.php     # hash(), verify(), needsRehash()
app/Core/Security/Voter/VoterInterface.php  # supports(), voteOnAttribute()
app/Core/Security/Voter/Voter.php
app/Core/Security/Voter/PostVoter.php    # example
app/Core/Security/AuthorizationChecker.php  # isGranted(), denyAccessUnlessGranted()
app/Core/Security/Security.php           # getUser(), isGranted(), getToken()
```

```php
interface VoterInterface
{
    public function supports(string $attribute, mixed $subject): bool;
    public function voteOnAttribute(string $attribute, mixed $subject, UserInterface $user): bool;
}

abstract class Voter implements VoterInterface
{
    public function voteOnAttribute(string $attribute, mixed $subject, UserInterface $user): bool
    {
        return match ($attribute) {
            'view'   => $this->canView($subject, $user),
            'edit'   => $this->canEdit($subject, $user),
            'delete' => $this->canDelete($subject, $user),
            default  => false,
        };
    }
    abstract protected function canView(mixed $subject, UserInterface $user): bool;
    abstract protected function canEdit(mixed $subject, UserInterface $user): bool;
    abstract protected function canDelete(mixed $subject, UserInterface $user): bool;
}
```

**Firewall model:**
```php
Firewall::register('main', [
    'pattern'   => '^/',
    'lazy'      => true,                  // authenticate only when needed
    'provider'  => 'users',
    'authenticators' => ['form', 'token'],
    'entry_point' => FormAuthenticator::class,
    'access'    => [
        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
        ['path' => '^/login', 'roles' => 'PUBLIC'],
    ],
]);
```

**Authorization strategies:** `affirmative` (default, one yes wins), `consensus` (majority yes, abstain ignored), `unanimous` (all must allow, abstain ignored), `priority` (first non-abstain wins). Canyon should default to `affirmative` for BC with `Permissions::can()` (which is owner-or-admin-or-callback).

**Integration:** `Permissions::can()` becomes a thin wrapper over `AuthorizationChecker::isGranted($permission, $entity)`, so plugins calling the existing API still work. `denyAccessUnlessGranted('edit', $post)` in controllers replaces manual `Permissions::require()` calls.

---

### 2.5 Form Component

**Symfony model.** `FormFactoryInterface` + `FormBuilderInterface`, `Form` with `createForm()`, `handleRequest()`, `isSubmitted()`, `isValid()`, `getData()`, `getErrors(true)`, `FormView` + Twig templates, types (`TextType`, `ChoiceType`, `EntityType`, `CollectionType`, `FileType`, `RepeatedType`, `SubmitType`), data mappers (`DataMapperInterface`), transformers (`TransformerInterface`), validators as event subscribers, `FormEvents::PRE_SET_DATA/POST_SET_DATA/PRE_SUBMIT/SUBMIT/POST_SUBMIT`, `#[Assert]` constraints on the model, CSRF via `CsrfExtension`, themes.

**Canyon implementation - the missing layer between `Validation` and HTML**
```
app/Core/Form/FormFactory.php
app/Core/Form/FormBuilder.php
app/Core/Form/Form.php
app/Core/Form/FormView.php
app/Core/Form/Type/TypeInterface.php
app/Core/Form/Type/TextType.php, TextareaType.php, ChoiceType.php, CheckboxType.php,
        EmailType.php, PasswordType.php, HiddenType.php, FileType.php,
        CollectionType.php, RepeatedType.php, SubmitType.php, EntityType.php
app/Core/Form/TransformerInterface.php
app/Core/Form/EventListenerInterface.php
app/Core/Form/Themes/default.php
```

```php
class Form
{
    public static function create(string $type, mixed $data = null, array $options = []): self;
    public function add(string $name, string $type = 'text', array $options = []): static;
    public function handleRequest(Request $request): static;
    public function isSubmitted(): bool;
    public function isValid(): bool;
    public function getData(): mixed;                 // mapped back to the Entity
    public function getErrors(bool $deep = false): array;
    public function createView(): FormView;
    public function render(array $vars = []): string;
}
```

**Data flow (Symfony's three layers, simplified):**
1. **Model data** - the `Entity` (or array).
2. **View data** - strings from `Request`.
3. `Form::handleRequest()` maps view → model using transformers, then runs `Validation` (or constraint validators) on the model; `isValid()` is false if violations exist.

**Value for Canyon:** `Validation` alone cannot render a form, show field-level errors, or handle file uploads. `Form` + `FormView` + `Themes/default.php` gives `ActiveForm`-style rendering with per-field error display and CSRF injection, closing the gap between `Security::csrfField()` and a real form.

---

### 2.6 Serializer

**Symfony model.** `Serializer` = Normalizers (`ObjectNormalizer`, `GetSetMethodNormalizer`, `PropertyNormalizer`, `DateTimeNormalizer`, `ArrayDenormalizer`, `BackedEnumNormalizer`) + Encoders (`JsonEncoder`, `XmlEncoder`, `CsvEncoder`, `YamlEncoder`); `serialize($data, $format, $context)`, `deserialize($data, $type, $format, $context)`; `SerializerInterface`; groups (`Default`, `post:read`, `post:write`), `#[Groups]`, `#[SerializedName]`, `#[MaxDepth]`, `#[Ignore]`, circular-reference handler, `AbstractObjectNormalizer`, `ObjectToPopulate`, `.COLLECT_DENORMALIZATION_ERRORS`.

**Canyon implementation:**
```
app/Core/Serializer/Serializer.php
app/Core/Serializer/Normalizer/NormalizerInterface.php, DenormalizerInterface.php
app/Core/Serializer/Normalizer/ObjectNormalizer.php, ArrayNormalizer.php,
                    DateTimeNormalizer.php, EntityNormalizer.php
app/Core/Serializer/Encoder/JsonEncoder.php, XmlEncoder.php
app/Core/Serializer/SerializerInterface.php
```

```php
class Serializer implements SerializerInterface
{
    public function serialize(mixed $data, string $format, array $context = []): string;
    public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed;
    public function normalize(mixed $data, ?string $format = null, array $context = []): mixed;
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed;
}
```

**Groups:** `Entity` declares `public array $groups = ['read' => [...], 'write' => [...]]`; `Serializer::serialize($post, 'json', ['groups' => ['post:read']])` emits only those fields. This is what makes the REST layer safe - without groups, `toArray()` leaks `password`/`deleted_at`. Add `EntityNormalizer` that respects `$hidden`/`$appends` (already present) plus groups.

---

### 2.7 Validator

**Symfony model.** `ValidatorInterface::validate($value, $constraints, $groups)`, constraints (`NotBlank`, `Length`, `Email`, `Url`, `Regex`, `Range`, `Choice`, `Date`, `Callback`, `EqualTo`, `IdenticalTo`, `Type`, `UniqueEntity`, `Expression`, `Count`, `File`, `Image`, `Ip`, `Uuid`, `Currency`, `Language`, `Locale`, `Timezone`, `Traverse`, `All`, `Collection`, `Count`, `DivisibleBy`, `Positive`, `Negative`, `LessThan`, `GreaterThan`, `LessThanOrEqual`, `GreaterThanOrEqual`, `IsNull`, `IsTrue`, `IsFalse`, `IsInstanceOf`, `Json`), `ConstraintViolationList` with `getMessage()`, `getPropertyPath()`, `getInvalidValue()`, `getConstraint()`, `getPropertyPath()`, `GroupSequence`, `Validation::createValidatorBuilder()`, metadata from attributes/YAML/XML, `RecursiveContextualValidator`.

**Canyon implementation - replace the `match`-based `Validation` with a constraint-object model**
```
app/Core/Validation/Validator.php          # validate($value, $constraints, $groups)
app/Core/Validation/Constraint.php          # abstract: message, groups, payload, validatedBy()
app/Core/Validation/Constraints/NotBlank.php, NotNull.php, Length.php, Email.php,
        Url.php, Regex.php, Range.php, Choice.php, Type.php, Date.php,
        Callback.php, Count.php, File.php, Image.php, Ip.php, Uuid.php,
        Json.php, Unique.php, RequiredIf.php, SameAs.php, In.php, Max.php, Min.php
app/Core/Validation/ConstraintViolation.php
app/Core/Validation/ConstraintViolationList.php
app/Core/Validation/GroupSequence.php
app/Core/Validation/Metadata/ClassMetadata.php   # reads attributes + rules array
```

```php
abstract class Constraint
{
    public string $message = 'This value is invalid.';
    public array $groups = ['Default'];
    public array $payload = [];

    public function __construct(array|string|null $options = null, ?array $groups = null);
    abstract public function validatedBy(): string;   // -> ConstraintValidator class
}

class Validator
{
    public function validate(mixed $value, Constraint|array|null $constraints = null,
                            array|string|null $groups = null): ConstraintViolationList;
    public function validateProperty(object $object, string $property, array|string|null $groups = null): ConstraintViolationList;
    public function validateClass(object $object, array|string|null $groups = null): ConstraintViolationList;
    public function startContext(): ContextualValidatorInterface;
}
```

**Why replace rather than extend:** the current `Validation` uses `match ($rule)` with `default => true`, which silently passes unknown rules (a security hazard - `'title' => 'required|bogus'` validates as true). Constraint objects make unknown rules impossible, support nested arrays (`new Collection(['title' => new NotBlank()])` → `posts.*.title`), and support groups/sequences. Keep `Validation` as a façade: `Validation::make($data, $rules)` builds constraints from the existing pipe syntax for BC.

---

### 2.8 Messenger (Queues)

**Symfony model.** `MessageBusInterface::dispatch($message, array $stamps)` → `Envelope` + `StampInterface`; handlers via `#[AsMessageHandler]` + `__invoke`; middleware stack (`DispatchMiddleware` → `HandleMessageMiddleware` → handlers); transports (`DoctrineTransport`, `RedisTransport`, `AmqpTransport`, `InMemoryTransport`, `FailoverTransport`, `RoundRobinTransport`); `TransportInterface` (`send(Envelope)`, `get(): iterable`, `ack(Envelope)`, `reject(Envelope)`); retry strategy (`max_retries`, `delay`, `multiplier`, `max_delay`); `Worker` + `messenger:consume`; `MessageBusInterface` decorators; `StampInterface` implementations (`BusNameStamp`, `RedeliveryStamp`, `SentStamp`, `HandledStamp`, `TransportMessageIdStamp`, `SerializerStamp`, `DelayStamp`, `ReceivedStamp`); `MessageHandlerInterface`; `Envelope::last()`.

**Canyon implementation: replace the file-`.job` `Queue` with a transport-based Messenger**
```
app/Core/Messenger/MessageBus.php
app/Core/Messenger/Envelope.php
app/Core/Messenger/Stamp/StampInterface.php, DelayStamp.php, RedeliveryStamp.php, HandledStamp.php
app/Core/Messenger/Middleware/MiddlewareInterface.php, DispatchMiddleware.php, HandleMessageMiddleware.php
app/Core/Messenger/Handler/HandlerInterface.php, HandlerDescriptor.php
app/Core/Messenger/Transport/TransportInterface.php
app/Core/Messenger/Transport/FileTransport.php, DatabaseTransport.php, RedisTransport.php, SyncTransport.php
app/Core/Messenger/Retry/RetryStrategy.php
app/Core/Messenger/Worker.php
app/Core/Messenger/Failed/FailedTransport.php
```

```php
interface TransportInterface
{
    public function send(Envelope $envelope): Envelope;
    public function get(): iterable;                 // yields Envelopes
    public function ack(Envelope $envelope): void;
    public function reject(Envelope $envelope): void;
}

class MessageBus
{
    public function dispatch(object $message, array $stamps = []): Envelope;
    public function addMiddleware(MiddlewareInterface $middleware): static;
}
```

**Improvements over the current `Queue`:**
- **Envelopes + stamps** replace the flat `$job` array - `DelayStamp` gives per-message delay, `RedeliveryStamp` gives retry count, `HandledStamp` records the handler result.
- **Multiple transports**: `SyncTransport` (immediate, for tests), `FileTransport` (current behaviour, renamed), `DatabaseTransport` (the `queue_jobs` table - atomic `SELECT ... FOR UPDATE` claim), `RedisTransport` (BRPOPLPUSH).
- **Retry strategy**: `max_retries=3`, `delay=1000ms`, `multiplier=2`, `max_delay=10000ms` → exponential backoff. Current `Queue` increments `attempts` but never retries.
- **Worker**: `messenger:consume` with `--limit`, `--time-limit`, `--memory-limit`, `--sleep`, graceful `SIGTERM` handling. Current `Queue::work()` loops until empty with no limit or signal handling.
- **Handler resolution**: scan `#[AsMessageHandler]` attributes (or a `handler` config array) to map message class → handler; multiple handlers per message supported.
- **BC shim:** keep `Queue::push($class, $payload)` as `MessageBus::dispatch(new CallQueuedHandler($class, $payload))` so existing plugins work.

---

### 2.9 Lock Component

**Symfony model.** `LockFactory::createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): LockInterface`; `LockInterface` (`acquire(bool $blocking = false): bool`, `release(): void`, `isAcquired(): bool`, `refresh(?float $ttl = null): void`, `isExpired(): bool`); stores `FlockStore`, `SemaphoreStore`, `RedisStore`, `DoctrineDbalStore`, `MemcachedStore`, `PdoStore`, `ZookeeperStore`, `CompositeStore`, `InMemoryStore`, `NullStore`; `ExpiringStoreInterface`; `FlockStore` scoped by `kernel.project_id`; `Lock` auto-release on destruct; `RetryTillSaveStore`.

**Canyon implementation - currently Canyon has no locking at all (a real hole for cron + queue workers)**
```
app/Core/Lock/LockFactory.php
app/Core/Lock/LockInterface.php, Lock.php
app/Core/Lock/Store/StoreInterface.php, FlockStore.php, SemaphoreStore.php,
        RedisStore.php, DatabaseStore.php, InMemoryStore.php
```

```php
class LockFactory
{
    public function __construct(private StoreInterface $store) {}
    public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): LockInterface;
}

interface LockInterface
{
    public function acquire(bool $blocking = false): bool;
    public function release(): void;
    public function isAcquired(): bool;
    public function refresh(?float $ttl = null): void;
    public function isExpired(): bool;
}
```

**Usage in Canyon:** `Cron::register()` should wrap every callback in `LockFactory::createLock('cron.'.$name)` so overlapping cron runs can't double-execute; `Queue`/`Messenger` workers should lock per-worker; `DatabaseStore` should use `GET_LOCK()` on MySQL / `INSERT ... ON CONFLICT` on SQLite for cross-server safety (Flock only works on one host).

---

### 2.10 Finder

**Symfony model.** `Finder` with `in()`, `files()`/`directories()`, `name()`, `notName()`, `contains()`, `notContains()`, `path()`, `notPath()`, `size()`, `date()`, `exclude()`, `ignoreDotFiles()`, `ignoreVCS()`, `ignoreUnreadableDirs()`, `depth()`, `sortByName()`, `sortByType()`, `sortByAccessedTime()`, `filter(Closure)`, `append()`, `getIterator()`, `count()`; `SplFileInfo` with `getRelativePathname()`.

**Canyon implementation: new `Canyon\Core\Finder`**
```php
class Finder implements \IteratorAggregate, \Countable
{
    public static function create(): static;
    public function in(string ...$dirs): static;
    public function files(): static;
    public function directories(): static;
    public function name(string|array $patterns): static;      // glob, or exact
    public function notName(string|array $patterns): static;
    public function contains(string|array $patterns): static;  // file contents
    public function path(string|array $patterns): static;      // relative path
    public function size(string|int $size): static;            // ">", "<", "==", "10K"
    public function date(string $expr): static;                // "since yesterday"
    public function depth(int|string $level): static;
    public function filter(callable $callback): static;
    public function sortByName(bool $reverse = false): static;
    public function ignoreDotFiles(bool $ignore = true): static;
    public function count(): int;
    public function getIterator(): \Iterator;
}
```

**Where Canyon needs it:** plugin discovery (currently a bare `glob($pluginDir.'/*', GLOB_ONLYDIR)`), theme asset discovery, migration file discovery (currently `glob`), log rotation, and the `Generator`'s template discovery.

---

### 2.11 Process

**Symfony model.** `Process` with `setCommandLine`, `setInput`, `setTimeout`, `setIdleTimeout`, `setWorkingDirectory`, `setEnv`, `setOptions`, `run()`, `mustRun()`, `start()`, `wait()`, `isRunning()`, `stop()`, `getOutput()`, `getErrorOutput()`, `getExitCode()`, `getExitCodeText()`, `isSuccessful()`, `setTty()`, `setPty()`, `inheritEnvironmentVariables()`, `ProcessTimedOutException`, `ProcessFailedException`, `PhpProcess`, `PhpExecutableFinder`.

**Canyon implementation: new `Canyon\Core\Process`**
```php
class Process
{
    public function __construct(array $command, ?string $cwd = null, ?array $env = null,
                                mixed $input = null, ?float $timeout = 60.0, ?float $idleTimeout = null);
    public static function fromShellCommandline(string $command, ...): static;
    public function run(?callable $callback = null): int;
    public function mustRun(?callable $callback = null): static;
    public function start(?callable $callback = null): void;
    public function wait(?callable $callback = null): int;
    public function getOutput(): string;
    public function getErrorOutput(): string;
    public function getCommandLine(): string;
    public function isRunning(): bool;
    public function stop(float $timeout = 10.0, ?int $signal = null): ?int;
}
```

**Safety:** never pass user input to `fromShellCommandline()` - always use the array form with `proc_open` (no shell). Canyon should add `Process::shell()` that throws if the command contains shell metacharacters, to force the safe path.

---

### 2.12 Expression Language

**Symfony model.** `ExpressionLanguage` with `evaluate($expression, $values = [])`, `compile($expression, $names = [])`, `parse($expression, $names)`, `lint($expression, $names)`, `ExpressionFunction`, `ExpressionFunctionProviderInterface`; syntax `1 + 2`, `someArray[3].someMethod('bar')`, `user.role in ['admin', 'editor']`, `post.isPublished and post.author == user`, `not`, `and`, `or`, `in`, `not in`, `matches`, `starts with`, `ends with`, `contains`, `..`, `?:`; functions `constant()`, `date()`, `min()`, `max()`, `random()`, `range()`, `service()`, `parameter()`, `env()`.

**Canyon implementation: new `Canyon\Core\Expression`**
```php
class Expression
{
    public function __construct(array $functions = []);
    public function evaluate(string $expression, array $values = []): mixed;
    public function compile(string $expression, array $names = []): string;
    public function lint(string $expression, array $names = []): ?string;  // null = valid
    public function addFunction(string $name, callable $compiler, callable $evaluator): static;
    public function addFunctionProvider(callable $provider): static;
}
```

**Where Canyon needs it:**
- **Access rules** - store `'post.author_id == user.id or "admin" in user.roles'` in `auth_item.description` or a `rules` table, evaluate per request.
- **Settings conditions** - `Settings::get()` with a conditional expression.
- **Validator** - the `Expression` constraint (`#[Assert\Expression("value > 0")]`).
- **Route conditions** - `Router::get('/admin', ...)->when('user.role == "admin"')`.
- **Widget visibility** - `Widget::register(..., ['when' => 'user.isLoggedIn'])`.
- **Security:** never `eval()` raw user strings - always run through `Expression::evaluate()` with an explicit `$values` whitelist. This is the whole point of the component.

---

### 2.13 Rate Limiter

**Symfony model.** `RateLimiterFactory`, `Limit` (`getLimit(): int`, `getInterval(): string`, `getRetryAfter(): \DateTimeImmutable`, `accept(): bool`, `reserve()`, `isAccepted()`, `getRemainingTokens()`, `getRetryAfter()`); policies `FixedWindow`, `SlidingWindow`, `TokenBucket`; `RateLimit` attribute; `RateLimiterInterface::consume(int $tokens = 1): Limit`; `NoLimiter`; `ResettableRateLimiterInterface`; storage in cache.

**Canyon implementation - the current `Security::throttle()` is session-only and cookie-rotatable**
```
app/Core/RateLimit/RateLimiter.php
app/Core/RateLimit/Limit.php
app/Core/RateLimit/Policy/FixedWindow.php, SlidingWindow.php, TokenBucket.php
app/Core/RateLimit/Storage/CacheStorage.php
```

```php
class RateLimiter
{
    public function __construct(private PolicyInterface $policy, private Cache $storage) {}
    public function consume(string $key, int $tokens = 1): Limit;
    public function reset(string $key): void;
    public function peek(string $key): Limit;
}

class Limit
{
    public function getLimit(): int;
    public function getRemainingTokens(): int;
    public function isAccepted(): bool;
    public function getRetryAfter(): \DateTimeImmutable;
    public function getInterval(): string;      // "60s", "1 hour"
    public function toArray(): array;          // for X-RateLimit-* headers
}
```

**Three policies (implement all three):**
- **FixedWindow** - `limit` per `interval`; reset on window boundary; simple, bursty at edges.
- **SlidingWindow** - weighted sum of current + previous window; removes edge bursts.
- **TokenBucket** - `rate` tokens/sec, `burst` capacity; best for smoothing.

**Canyon wiring:** `Security::throttle()` → `RateLimiter::consume('ip:'.ip, 1)`; login → `consume('login:'.$user.'|'.ip, 1)`; REST → `consume('api:'.$userId)` returning `429` + `Retry-After`. Always key by **both** user and IP (the skill already flags session-only throttling as a bypass).

---

### 2.14 Cache Contracts

**Symfony model.** PSR-6 `CacheItemPoolInterface` + `CacheItemInterface`; `CacheInterface` (Contracts) with `get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed`, `delete(string $key): bool`; `CacheTrait` with `doGet`, `doHave`, `doClear`, `doDelete`, `doSave`; `PruneableInterface`, `ResetInterface`, `TagAwareAdapterInterface`, `InvalidArgumentException`, `CacheItem::isHit()`, `CacheItem::get()`, `CacheItem::set()`, `CacheItem::expiresAfter()`, `CacheItem::tag()`, `CacheItem::getKey()`, `CacheItem::getMetadata()`; adapters `ArrayAdapter`, `FilesystemAdapter`, `RedisAdapter`, `MemcachedAdapter`, `ApcuAdapter`, `DoctrineAdapter`, `PdoAdapter`, `NullAdapter`, `ChainAdapter`, `TraceableAdapter`; `CacheItem::getPreviousTags()`, `early expiration`, `stampede prevention` via `CacheItem::expiresAfter()` + `get()` callback beta.

**Canyon implementation: add Contracts + PSR-6 alongside the existing `Cache` façade**
```
app/Core/Cache/Contracts/CacheInterface.php     # get(), delete()
app/Core/Cache/Contracts/CacheTrait.php
app/Core/Cache/Psr/CacheItemPool.php, CacheItem.php
app/Core/Cache/Adapter/ArrayAdapter.php, FileAdapter.php, RedisAdapter.php,
        MemcachedAdapter.php, DatabaseAdapter.php, NullAdapter.php, ChainAdapter.php
app/Core/Cache/Adapter/TagAwareAdapter.php
```

```php
interface CacheInterface  // Contracts - the one to type-hint
{
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed;
    public function delete(string $key): bool;
}
```

**Stampede prevention via `$beta`:** `Cache::get('key', $callback, beta: 1.0)` - when the remaining TTL < `beta * TTL`, the callback runs in the background (or inline) to refresh while the stale value is still served. Combined with `Lock` (§2.9) this is the correct stampede defence; the current `Cache::remember()` has none.

**Tags:** `TagAwareAdapter::invalidateTags(['posts'])` → clears every item tagged. Required for the `Entity` cache and page cache invalidation.

---

### 2.15 Webpack Encore / Asset Pipeline

**Symfony model.** Webpack Encore wraps webpack: `webpack.config.js`, `Encore.addEntry()`, `Encore.splitEntryChunks()`, `Encore.enableVersioning()`, `Encore.enableIntegrityHashes()`, `Encore.enableSourceMaps()`, `Encore.setOutputPath()`, `Encore.enableSingleRuntimeChunk()`, `Encore.enablePostCssLoader()`, `Encore.addStyleEntry()`, `Encore.copyFiles()`, `entrypoints.json` + `manifest.json`; Twig functions `encore_entry_script_tags()`, `encore_entry_link_tags()`, `encore_entry_css_source()`; `AssetMapper` (modern replacement) with `AssetMapperInterface`, `AssetMapperCompilerInterface`, `ImportMapController`, `JavaScriptImportPathCompiler`, `AssetPackage`, `ManifestAssetPackage`, `VendorPrefixPathResolver`, `ImportMapGenerator`, `AssetMapperConfigReader`.

**Canyon implementation: keep `AssetOptimizer`, add a manifest/versioning layer**
```
app/Core/Asset/Manifest.php          # reads manifest.json -> hashed filenames
app/Core/Asset/AssetManager.php      # addEntry(), entryScriptTags(), entryLinkTags(), integrity()
app/Core/Asset/Entrypoints.php        # reads entrypoints.json
app/Core/Asset/ImportMap.php         # bare-specifier resolution for ES modules
```

```php
class AssetManager
{
    public static function entryScriptTags(string $entryName, array $attributes = []): string;
    public static function entryLinkTags(string $entryName, array $attributes = []): string;
    public static function asset(string $path): string;      // manifest lookup + version query
    public static function integrity(string $path): string;  // SRI hash
}
```

**Canyon specifics:** `AssetOptimizer` already minifies CSS/JS/HTML and versions assets - keep it. Add: (1) a `manifest.json` writer that maps logical names to content-hashed files (so cache-busting is automatic), (2) `integrity` SRI hashes for `script-src`/`style-src`, (3) an `ImportMap` for bare-specifier ES module imports (`import 'lodash'` → `/assets/vendor/lodash.js`), (4) a `canyon build-assets` command that runs the optimizer and writes the manifest. This is the piece that makes `AssetOptimizer` usable in production rather than only in dev.

---

## 3. Architecture Comparison

| Dimension | Yii2 | Symfony | Canyon today | Canyon target |
|---|---|---|---|---|
| Core pattern | `Yii::$app` service locator + components | Container + kernel + bundles | Static facades on `Container` | Static facades **over** a compiled container |
| Request/Response | `yii\web\Request`/`Response` (extends `Component`) | `HttpFoundation\Request`/`Response` (plain value objects) | `$_GET`/`$_SERVER` globals, `header()`+`echo` | `Http\Request`/`Http\Response` value objects |
| Routing | `urlManager` + `UrlRule` classes, rules as config arrays | `#[Route]` attributes, YAML, PHP loaders, `RequestMatcher` | Static `Router::get()` calls in `routes/web.php` | Keep static API, add `Router::resource()`, `Request` injection, conditions |
| ORM | `yii\db\ActiveRecord` + `ActiveQuery` | Doctrine ORM / DBAL (external) | `Entity` (partial AR) + raw `Database` | Real AR + `Query` builder + relations + eager loading |
| Validation | `yii\validators\*` (class-per-rule) + `Model::rules()` | Constraint objects + `Validator` | Pipe-string `match` in `Validation` | Constraint objects, keep pipe syntax as façade |
| Auth | `yii\web\User` + `AccessControl` filter + RBAC | `SecurityBundle` firewalls + voters | `AccessControl` (in-memory roles) + `Permissions` (callbacks) | RBAC (persistent) **+** voters, firewall, authenticators |
| Events | `Component` events + behaviors | `EventDispatcher` + subscribers + stop propagation | `EventDispatcher::listen/dispatch` | Add `stopPropagation`, wildcards, subscribers |
| Queue | `yii\queue\*` (DB/Redis/Beanstalkd/RabbitMQ/Gearman) + `JobInterface` | Messenger transports + middleware + envelopes | File `.job` serialize | Messenger-style bus + transports + envelopes + retry |
| Caching | `yii\caching\Cache` + dependencies | PSR-6 + Contracts + adapters + tags | File cache + TTL | Contracts + PSR-6 + tags + stampede protection |
| CLI | `yii\console\Controller` + `yii\base\Action` | Console `Command` + `Input`/`Output` | Ad-hoc `switch` in `canyon` | `Command` base + `ArgvInput` + `ConsoleOutput` + testers |
| Testing | Codeception + fixtures + `TestRequest` | PHPUnit + `TestCase` + `KernelBrowser` + `Panther` | Empty `tests/` | `TestCase` + `BrowserKit` + fixtures |
| Debugging | `yii\debug\Module` + panels | `WebProfilerBundle` + `stopwatch` + `VarDumper` | None | `DebugBar` + panels |
| Extensibility | Extensions + modules | Bundles + Flex + recipes | Plugins (`canyon-plugin.php`) | Plugins + lifecycle hooks + DI tags |
| Config | PHP arrays + `Yii::$app->params` | YAML/XML/PHP + env vars + `ParameterBag` | `Config` + `.env` | `Config` + env + nested files + compiled container |

**Key architectural decision for Canyon:** keep the static façade API (`Cache::get()`, `Router::get()`) because 30+ existing classes and the plugin contract depend on it, but back every façade with an instance object resolved from the container. This gives Symfony's testability and DI without breaking the existing developer experience.

---

## 4. Recommended Build Order

### Phase 1 - Foundations (unblocks everything)
1. **`Http\Request` / `Http\Response`** (Symfony §2.1) - value objects, `createFromGlobals()`, `prepare()`, `isNotModified()`.
2. **`Container` autowiring + compiled cache** (Symfony §2.2) - reflection-based `make()`, `call()`, tags, `ContainerBuilder`.
3. **`Console\Command` base** (Symfony §2.3) - replace the `canyon` `switch`; add `ArgvInput`, `ConsoleOutput`, `Table`, `ProgressBar`, `QuestionHelper`.
4. **Fix `Entity::find()` signature** - `find(int|string $id)`, add `findOrFail`, `hydrate`, dirty tracking.
5. **`Validation` constraint objects** (Symfony §2.7) - add `Constraint` hierarchy; keep pipe syntax as façade.

### Phase 2 - Data & Domain
6. **`Query` builder + `Entity` relations** (Yii2 §1.2/§1.3) - `with()`, eager loading, `hasOne/hasMany/belongsTo`.
7. **`RbacManager`** (Yii2 §1.4) - persistent roles/permissions/assignments; migrate `AccessControl` to delegate.
8. **`VoterInterface` + `AuthorizationChecker`** (Symfony §2.4) - merge with RBAC; `denyAccessUnlessGranted()`.
9. **`Behavior` + `TimestampBehavior`/`SoftDeleteBehavior`** (Yii2 §1.5).
10. **`Form` + `FormView` + `ActiveForm` widget** (Symfony §2.5 + Yii2 §1.6).

### Phase 3 - Infrastructure
11. **`Messenger`** (Symfony §2.8) - replace `Queue`; transports, envelopes, retry.
12. **`Lock`** (Symfony §2.9) - wrap cron + workers.
13. **`RateLimiter`** (Symfony §2.13) - replace session-only throttling.
14. **`Cache` contracts + tags + stampede** (Symfony §2.14 + Yii2 §1.9).
15. **`Serializer` + groups** (Symfony §2.6) - required for REST.

### Phase 4 - API & UI
16. **`RestController` + `Router::resource()` + content negotiation** (Yii2 §1.7).
17. **`Generator` (Gii)** (Yii2 §1.1) - model/CRUD/migration generators from schema.
18. **`Widget` class layer + `GridView`/`Pagination`/`Menu`** (Yii2 §1.6).
19. **`AssetManager` + manifest + SRI** (Symfony §2.15).
20. **`DebugBar`** (Yii2 §1.12).

### Phase 5 - Cross-cutting
21. **`Expression`** (Symfony §2.12) - access rules, widget conditions, validation.
22. **`Finder`** (Symfony §2.10) + **`Process`** (Symfony §2.11).
23. **`I18n` pluralization + fallback + formatter** (Yii2 §1.8).
24. **`TestCase` + `BrowserKit` + fixtures** (Symfony/ Yii2 testing).
25. **Plugin lifecycle hooks** (`activate`/`deactivate`/`uninstall`) + DI tags.

---

## 5. Implementation Rules (Canyon-specific)

1. **One class per file, PSR-4, `namespace Canyon\Core\...`** - no exceptions; the existing 30 classes set the pattern.
2. **Static façade over instance object** - `Cache::get()` internally does `Container::make(CacheStore::class)->get()`. Never `new` inside a façade.
3. **Never `eval()` or raw `shell_exec`** - generators write files; `Process` uses `proc_open` with an array command.
4. **Parameterize all SQL** - `QueryBuilder` always returns `[sql, bindings]`; `toSql()` must be available for the debug toolbar.
5. **Unknown validation rules must fail loudly** - the current `default => true` in `Validation::applyRule()` is a hazard; replace with `throw new InvalidArgumentException("Unknown rule: {$rule}")`.
6. **Every cross-cutting feature gets a `guard()`** - `Generator::guard()`, `DebugBar::guard()`, `RateLimiter` - checking `APP_DEBUG` and IP allow-lists.
7. **Cache everything that is hot and expensive** - RBAC graph, container, route table, config, schema introspection. All have a `clear-cache` path.
8. **Keep the plugin contract stable** - `canyon-plugin.php` returning an array; new features (services, tags, voters, generators) are additive keys.
9. **Type-hint everything** (PHP 8.1+): `string`, `int`, `bool`, `array`, `void`, `?string`, `mixed` sparingly; `never` for terminal response methods.
10. **Test every new feature with `TestCase` + `BrowserKit`** before merging - the empty `tests/` directory is the gap to close first.
