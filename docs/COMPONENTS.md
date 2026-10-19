# Canyon Components

Canyon is assembled from **components**: a mandatory `core` kernel plus optional
addons. Each component is a self-contained directory with a manifest, so a
feature can be enabled, disabled or shipped independently instead of living in
one flat pile of classes.

```
components/
  core/          # kernel — routing, view, database, config, security   (required)
  ai/            # multi-provider LLM integration, chatbot widget
  social/        # Elgg-based community layer (river, groups, profiles, widgets…)
  security/      # web application firewall + RBAC / access control
  performance/   # asset & image optimisation, OPcache, preload, heartbeat
```

## The manifest

Every component is described by `components/<name>/component.json`:

```json
{
    "name": "social",
    "title": "Canyon Social",
    "description": "Elgg-based community layer: activity river, groups, messaging, …",
    "version": "1.0.0",
    "type": "addon",
    "enabled": true,
    "requires": ["core"],
    "autoload": { "psr-4": { "Canyon\\Core\\": "src" } },
    "classes": ["ActivityFeed", "GroupSystem", "Menu", "…"]
}
```

| Key | Meaning |
|---|---|
| `name` | Unique component id; also the directory name. |
| `version` | Semantic version, surfaced by `canyon component list`. |
| `type` | `core` (mandatory) or `addon`. |
| `enabled` | Default state. `components.json` can override it. |
| `requires` | Component names that must also be enabled. Resolved transitively. |
| `autoload.psr-4` | Namespace prefix → directory, relative to the component. |
| `files` | Plain PHP files to `require` up front (for non-autoloadable helpers). |
| `classes` | The classes the component provides — documentation and tooling. |

Several components may declare the **same** PSR-4 prefix (`Canyon\Core\`, as all
of Canyon's do). The loader flattens them into one prefix → list-of-directories
map, so the namespace stays stable while the physical layout is componentised.

## Enabling and disabling

```bash
./canyon component list              # installed components and their state
./canyon component show social       # full manifest detail
./canyon component disable social    # persists the decision
./canyon component enable social     # turns it back on, with its dependencies
./canyon info                        # framework summary, incl. components
```

Decisions are written to `components.json` at the framework root:

```json
{ "disabled": ["social"] }
```

Rules the loader enforces:

- `core` can never be disabled — without it nothing loads.
- Disabling a component also disables anything whose `requires` names it, so a
  request can never fail while autoloading a missing parent.
- Re-enabling a component re-enables the dependencies it needs.
- `CANYON_COMPONENTS_DISABLED` in `config.php` is merged with `components.json`,
  so a deployment can pin a set without touching the file the CLI writes.

## How loading works

`app/Helpers/components.php` runs **before** the autoloader exists — it is what
configures the autoloader — so it uses nothing but the standard library:

1. `canyon_discover_components()` — glob `components/*/component.json`.
2. `canyon_resolve_components()` — apply `enabled`, the disable list and
   `requires` (transitively); `core` is always added.
3. `canyon_component_psr4()` — flatten the manifests into prefix → directories.
4. `canyon_component_files()` — the `files` entries to require up front.

`bootstrap.php` then registers a single autoloader that resolves a class in this
order:

1. **Application** (`CANYON_APP_PATH`) — so an app can override a framework
   default of the same name.
2. **Enabled components** — the kernel and addons.
3. **The framework's app skeleton** — `Canyon\Controllers\Controller` and friends.

Once the autoloader is live, `Canyon\Core\Component` (`components/core/src/Component.php`)
provides the object-oriented API used by the CLI and available to applications:

```php
Component::enabledNames();     // ['ai', 'core', 'performance', 'security', 'social']
Component::isEnabled('social');
Component::manifest('core');
Component::srcPath('social');
Component::info();             // rows for a listing / admin screen
Component::setEnabled('social', false);
```

## Running many applications off one checkout

An application does **not** need its own copy of the framework. Point it at a
shared checkout and it inherits every fix:

```php
// the application's config.php
define('CANYON_SHARED_PATH', '/home/user/htdocs/shared/canyon');
```

`public/index.php` then loads `config.php` (which defines that path) and requires
`$CANYON_SHARED_PATH/bootstrap.php`. `bootstrap.php` reads the components from
there, and the app's own `app/` directory still takes precedence, so application
code can override a framework class.

Constants:

| Constant | Purpose |
|---|---|
| `CANYON_BASE_PATH` | The application root (storage, routes, resources, app). |
| `CANYON_SHARED_PATH` | The framework checkout. Defaults to `CANYON_BASE_PATH`. |
| `CANYON_FRAMEWORK_PATH` | Alias of `CANYON_SHARED_PATH`. |
| `CANYON_COMPONENTS_PATH` | `<framework>/components`. |
| `CANYON_COMPONENTS_DISABLED` | Addons to switch off for this deployment. |

### Updating a consuming application

```bash
cd shared/canyon && git pull          # update the framework once
# or, from an application that defines CANYON_SHARED_PATH:
bash bin/update-framework.sh --check  # show what would change
bash bin/update-framework.sh          # pull, and report which components moved
```

Because nothing framework-owned is copied into the app, one pull updates every
application that points at that checkout.

## Adding a component

1. Create `components/<name>/src/` and put the classes in it.
2. Add `components/<name>/component.json` with a `name`, `version`, `requires`
   and an `autoload.psr-4` map.
3. `./canyon component list` — it appears immediately; no registration step.

Keep `requires` honest: the loader uses it both to pull dependencies in and to
cascade a disable, so a wrong dependency list surfaces as a class that cannot be
autoloaded rather than a clear error.
