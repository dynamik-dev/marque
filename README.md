# Policy Engine for Laravel

Scoped, composable permissions for Laravel. Hooks into the Gate so `$user->can()`, `@can`, `$this->authorize()`, and middleware all work out of the box.

```php
// assign a role — globally or within a scope
$user->assign('editor');
$user->assign('moderator', $team);

// check permissions — standard Laravel, nothing custom
$user->can('posts.create');
$user->can('posts.create', $team);

// works everywhere Laravel authorization works
Route::middleware('can:posts.create')->post('/posts', [PostController::class, 'store']);
```

```blade
@can('posts.create', $team)
    <button>New Post</button>
@endcan
```

```php
// roles are just named permission sets — deny rules, wildcards, scoping built in
Primitives::role('editor', 'Editor')
    ->grant(['posts.create', 'posts.update.own', 'comments.*', '!posts.delete']);
```

Permissions are as granular as you need. Use dot-notation to model resource, action, and ownership — like IAM policies.

```php
// fine-grained: resource.action.ownership
'posts.create'
'posts.update.own'
'posts.update.any'
'posts.delete.pinned'

// wildcards at any level
'posts.*'           // all post actions
'*.read'            // read anything
'*.*'               // superadmin
```

Boundaries cap what's possible in a scope — even if a role grants it, the boundary has final say.

```php
// free plan can only read, pro plan gets everything
Primitives::boundary('plan::free', ['posts.read', 'comments.read']);
Primitives::boundary('plan::pro', ['posts.*', 'comments.*', 'analytics.*']);

$user->assign('admin', $freeOrg);
$user->can('analytics.view', $freeOrg);  // false — boundary blocks it
$user->can('analytics.view', $proOrg);   // true
```

Version-control your entire authorization config as portable JSON documents.

```json
{
    "version": "1.0",
    "permissions": ["posts.read", "posts.create", "posts.update.own", "posts.delete.any"],
    "roles": [
        {
            "id": "editor",
            "name": "Editor",
            "permissions": ["posts.read", "posts.create", "posts.update.own", "!posts.delete.any"]
        }
    ],
    "boundaries": [
        { "scope": "org::acme", "max_permissions": ["posts.*", "comments.*"] }
    ]
}
```

```bash
php artisan primitives:import policies/production.json
php artisan primitives:export --path=policies/backup.json
```

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
