<p align="center">
  <img src="./marque.png" width="600"/>
</p>

[![Pint](https://img.shields.io/github/actions/workflow/status/dynamik-dev/marque/lint.yml?branch=main&label=Pint&logo=laravel&style=flat-square)](https://github.com/dynamik-dev/marque/actions/workflows/lint.yml)
[![Larastan](https://img.shields.io/github/actions/workflow/status/dynamik-dev/marque/static.yml?branch=main&label=Larastan&logo=php&style=flat-square)](https://github.com/dynamik-dev/marque/actions/workflows/static.yml)
[![Pest](https://img.shields.io/github/actions/workflow/status/dynamik-dev/marque/tests.yml?branch=main&label=Pest&logo=php&style=flat-square)](https://github.com/dynamik-dev/marque/actions/workflows/tests.yml)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-level%209-2a5099?style=flat-square&logo=php)](https://phpstan.org/)

A [letter of marque](https://en.wikipedia.org/wiki/Letter_of_marque) was a document issued by a government that turned pirates into privateers, granting them scoped permission to plunder in specific waters. This package does the same thing for Laravel (minus the plundering). A user can be an admin in one team and a viewer in another. Deny rules, permission boundaries, and JSON policy documents are built in. The whole thing plugs into Laravel's Gate.

```bash
composer require dynamik-dev/marque
```

---

## Quick look

```php
Marque::createRole('admin', 'Admin')
    ->grant(['members.*', 'posts.*'])
    ->assignTo($user, scope: $acmeTeam);

Marque::createRole('viewer', 'Viewer')
    ->grant(['posts.read'])
    ->assignTo($user, scope: $widgetTeam);

$user->can('members.remove', $acmeTeam);  // true
$user->can('members.remove', $widgetTeam); // false
```

Roles, boundaries, and deny rules can live in JSON files you import at deploy time:

```json
{
  "roles": [
    {
      "id": "editor",
      "permissions": ["posts.*", "comments.create", "!posts.delete"]
    }
  ],
  "boundaries": [
    { "scope": "plan::free", "max_permissions": ["posts.read", "comments.read"] },
    { "scope": "plan::pro", "max_permissions": ["posts.*", "comments.*", "analytics.*"] }
  ]
}
```

```bash
php artisan marque:import policies/production.json
```

---

## Features

### Wired into the Gate

`$user->can()`, `@can`, `$this->authorize()`, and `can:` middleware all work without any extra wiring.

```php
$user->assignRole('editor', $acmeOrg);

$user->can('posts.create', $acmeOrg); // true

Route::middleware('can:posts.create')->post('/posts', [PostController::class, 'store']);
```

```blade
@can('posts.create', $team)
    <button>New Post</button>
@endcan
```

### Deny rules

Prefix any permission with `!`. The denial overrides every other role that grants it.

```php
Marque::createRole('editor', 'Editor')
    ->grant(['posts.*', 'comments.*'])
    ->deny(['posts.delete']);

$editor->can('posts.create');  // true
$editor->can('posts.delete');  // false -- deny wins
```

### Permission boundaries

Boundaries set a ceiling on what any role can do inside a scope. A user with `admin` in a free-tier org still can't access pro-tier features. Pass a scope string or any Scopeable model.

```php
Marque::boundary($freeOrg)->permits(['posts.read', 'comments.read']);
Marque::boundary($proOrg)->permits(['posts.*', 'comments.*', 'analytics.*']);

$user->assignRole('admin', $freeOrg);
$user->can('analytics.view', $freeOrg);  // false -- boundary blocks it
$user->can('analytics.view', $proOrg);   // true
```

### Wildcards

```php
'posts.*'           // all post actions
'*.read'            // read anything
'*.*'               // superadmin
'posts.update.own'  // fine-grained qualifiers
```

### Resource policies

Attach authorization rules directly to a resource type. The `when()` closure receives the user and the resource instance.

```php
Marque::resource(Post::class)
    ->allow('update')
    ->when(fn ($user, $post) => $post->author_id === $user->id);

Marque::resource(Post::class)
    ->deny('delete');
```

### Contract-driven

Every component implements a PHP interface. You can swap any implementation through the service container. See [Swapping implementations](docs/extending/swapping-implementations.md).

---

## Why not Spatie?

[Spatie laravel-permission](https://github.com/spatie/laravel-permission) works well for flat RBAC. Marque adds scoped roles, deny rules, permission boundaries, and declarative policy documents. See the [full comparison](docs/comparison-with-spatie.md).

---

## Requirements

| Dependency     | Supported Versions |
| -------------- | ------------------ |
| PHP            | 8.4, 8.5           |
| Laravel        | 12, 13             |
| PostgreSQL     | 17+                |
| SQLite         | 3.35+              |
| Valkey / Redis | 8+                 |

SQLite works out of the box for development. PostgreSQL and Valkey are optional — the package tests against both in CI. MySQL is not officially supported but should work fine since Laravel's query builder abstracts the differences.

---

## Documentation

**Getting Started** &mdash; [How authorization works](docs/getting-started/how-authorization-works.md) | [Installation](docs/getting-started/installing-the-package.md) | [Permission naming](docs/getting-started/permission-naming-conventions.md) | [Seeding permissions and roles](docs/getting-started/seeding-permissions-and-roles.md)

**Authorization** &mdash; [Checking permissions](docs/authorization/checking-permissions.md) | [Roles](docs/authorization/working-with-roles.md) | [Scoped permissions](docs/authorization/scoping-permissions.md) | [Deny rules](docs/authorization/using-deny-rules.md) | [Boundaries](docs/authorization/setting-permission-boundaries.md) | [Resource policies](docs/authorization/using-resource-policies.md)

**Integrations** &mdash; [Middleware](docs/integrations/restricting-routes-with-middleware.md) | [Blade](docs/integrations/checking-permissions-in-blade.md) | [Model policies](docs/integrations/integrating-with-model-policies.md) | [Sanctum](docs/integrations/scoping-sanctum-tokens.md)

**Policy Documents** &mdash; [Document format](docs/policy-documents/document-format.md) | [Import / Export](docs/policy-documents/importing-and-exporting.md)

**Extending** &mdash; [Swapping implementations](docs/extending/swapping-implementations.md) | [Events](docs/extending/listening-to-events.md) | [Cache](docs/extending/customizing-the-cache.md)

**Testing** &mdash; [Testing authorization](docs/testing/testing-authorization.md)

**Reference** &mdash; [Configuration](docs/reference/configuration.md) | [Contracts](docs/reference/contracts.md) | [Facade](docs/reference/facade.md) | [Events](docs/reference/events.md) | [Artisan commands](docs/cli/artisan-commands.md) | [Comparison with Spatie](docs/comparison-with-spatie.md)
