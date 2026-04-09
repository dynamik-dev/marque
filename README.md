# Policy Engine for Laravel

[![Pint](https://img.shields.io/github/actions/workflow/status/dynamik-dev/laravel-policy-engine/lint.yml?branch=main&label=Pint&logo=laravel)](https://github.com/dynamik-dev/laravel-policy-engine/actions/workflows/lint.yml)
[![Larastan](https://img.shields.io/github/actions/workflow/status/dynamik-dev/laravel-policy-engine/static.yml?branch=main&label=Larastan&logo=php)](https://github.com/dynamik-dev/laravel-policy-engine/actions/workflows/static.yml)
[![Pest](https://img.shields.io/github/actions/workflow/status/dynamik-dev/laravel-policy-engine/tests.yml?branch=main&label=Pest&logo=php)](https://github.com/dynamik-dev/laravel-policy-engine/actions/workflows/tests.yml)

An IAM-style policy engine for Laravel. Define your authorization as declarative JSON documents and import them the way you'd manage AWS IAM policies.

```json
{
  "version": "1.0",
  "permissions": [
    "posts.read",
    "posts.create",
    "posts.update.own",
    "posts.delete.any"
  ],
  "roles": [
    {
      "id": "editor",
      "name": "Editor",
      "permissions": [
        "posts.read",
        "posts.create",
        "posts.update.own",
        "!posts.delete.any"
      ]
    }
  ],
  "boundaries": [
    {
      "scope": "plan::free",
      "max_permissions": ["posts.read", "comments.read"]
    },
    {
      "scope": "plan::pro",
      "max_permissions": ["posts.*", "comments.*", "analytics.*"]
    }
  ]
}
```

```bash
php artisan policy-engine:import policies/production.json
php artisan policy-engine:export --path=policies/backup.json
```

### Wired into the Gate

Policy Engine hooks into Laravel's Gate, so `$user->can()`, `@can`, `$this->authorize()`, and `can:` middleware all work without learning a custom API.

```php
$user->assign('editor', $acmeOrg);
$user->assign('viewer', $personalOrg);

$user->can('posts.create', $acmeOrg);     // true
$user->can('posts.create', $personalOrg); // false

Route::middleware('can:posts.create')->post('/posts', [PostController::class, 'store']);
```

```blade
@can('posts.create', $team)
    <button>New Post</button>
@endcan
```

### Deny rules

Prefix any permission with `!` and the denial wins, no matter how many other roles allow it.

```php
PolicyEngine::role('editor', 'Editor')
    ->grant(['posts.*', 'comments.*'])
    ->deny(['posts.delete']);

$editor->can('posts.create');  // true
$editor->can('posts.delete');  // false -- deny wins
```

### Permission boundaries

Boundaries cap what's possible in a scope. Even if a user holds `admin`, the boundary has final say.

```php
PolicyEngine::boundary('plan::free', ['posts.read', 'comments.read']);
PolicyEngine::boundary('plan::pro', ['posts.*', 'comments.*', 'analytics.*']);

$user->assign('admin', $freeOrg);
$user->can('analytics.view', $freeOrg);  // false -- boundary blocks it
$user->can('analytics.view', $proOrg);   // true
```

### Wildcards

```php
'posts.*'           // all post actions
'*.read'            // read anything
'*.*'               // superadmin
'posts.update.own'  // fine-grained ownership
```

### Contract-driven

Every component is coded to a PHP interface. Swap any implementation via the service container. See [Swapping implementations](docs/extending/swapping-implementations.md).

### Why not Spatie?

[Spatie laravel-permission](https://github.com/spatie/laravel-permission) is a good package for flat RBAC. Policy Engine covers the cases where Spatie runs out of road: polymorphic scoping, deny rules, permission boundaries, and declarative policy documents. See [Comparison with Spatie](docs/comparison-with-spatie.md).

## Requirements

| Dependency     | Supported Versions |
| -------------- | ------------------ |
| PHP            | 8.4, 8.5           |
| Laravel        | 12, 13             |
| PostgreSQL     | 17+                |
| SQLite         | 3.35+              |
| Valkey / Redis | 8+                 |

SQLite works out of the box for development. PostgreSQL and Valkey are optional — the package tests against both in CI. MySQL is not officially supported but should work fine since Laravel's query builder abstracts the differences.

## Documentation

### Getting Started

- [Installing the package](docs/getting-started/installing-the-package.md)
- [Seeding permissions and roles](docs/getting-started/seeding-permissions-and-roles.md)

### Authorization

- [Checking permissions](docs/authorization/checking-permissions.md)
- [Working with roles](docs/authorization/working-with-roles.md)
- [Scoping permissions](docs/authorization/scoping-permissions.md)
- [Using deny rules](docs/authorization/using-deny-rules.md)
- [Setting permission boundaries](docs/authorization/setting-permission-boundaries.md)

### Integrations

- [Restricting routes with middleware](docs/integrations/restricting-routes-with-middleware.md)
- [Checking permissions in Blade templates](docs/integrations/checking-permissions-in-blade.md)
- [Integrating with model policies](docs/integrations/integrating-with-model-policies.md)
- [Scoping Sanctum tokens](docs/integrations/scoping-sanctum-tokens.md)

### Policy Documents

- [Understanding the document format](docs/policy-documents/document-format.md)
- [Importing and exporting](docs/policy-documents/importing-and-exporting.md)

### Extending

- [Swapping implementations](docs/extending/swapping-implementations.md)
- [Listening to events](docs/extending/listening-to-events.md)
- [Customizing the cache](docs/extending/customizing-the-cache.md)

### CLI

- [Using the Artisan commands](docs/cli/artisan-commands.md)

### Reference

- [Configuration](docs/reference/configuration.md)
- [Contracts](docs/reference/contracts.md)
- [Events](docs/reference/events.md)
- [Comparison with Spatie](docs/comparison-with-spatie.md)
