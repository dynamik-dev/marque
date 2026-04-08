# Working with Roles

Roles are named collections of permissions. Assign them to users globally or within a scope, and manage their permissions through the `PolicyEngine` facade or directly through the `RoleStore` contract.

## Creating a role

```php
use DynamikDev\PolicyEngine\Facades\PolicyEngine;

PolicyEngine::role('editor', 'Editor')
    ->grant(['posts.create', 'posts.update.any', 'posts.delete.own']);
```

The first argument is the role ID (stored as a string primary key), the second is a human-readable name. `grant()` returns the `RoleBuilder`, so you can chain calls.

## Creating a system-locked role

```php
PolicyEngine::role('admin', 'Admin', system: true)
    ->grant(['*.*']);
```

System roles cannot be deleted at runtime when `protect_system_roles` is enabled in the config. Use this for roles that should only change through seeders.

## Adding permissions to an existing role

```php
PolicyEngine::role('editor', 'Editor')
    ->grant(['comments.create', 'comments.delete.own']);
```

`grant()` merges with existing permissions. Calling it with permissions the role already has is a no-op for those permissions.

## Removing permissions from a role

```php
PolicyEngine::role('editor', 'Editor')
    ->ungrant(['posts.delete.own']);
```

`ungrant()` removes the specified permissions from the role. Permissions not currently on the role are ignored.

## Deleting a role

```php
PolicyEngine::role('editor', 'Editor')->remove();
```

This deletes the role and cascades to all assignments. Users who held this role lose it immediately.

> Deleting a system role when `protect_system_roles` is `true` throws a `RuntimeException`. Set the config to `false` or remove the `system` flag first.

## Assigning a role to a user

```php
$user->assign('editor');
```

This creates a global assignment — the user holds the role everywhere, regardless of scope.

### Assigning a role within a scope

```php
$user->assign('editor', scope: $group);
```

The second argument accepts a `Scopeable` model or a raw scope string like `'group::5'`. Scoped assignments only apply when checking permissions within that scope.

### Assigning is idempotent

Assigning the same role to the same user in the same scope twice is a no-op. No exception, no duplicate row.

## Revoking a role from a user

```php
$user->revoke('editor');
$user->revoke('editor', scope: $group);
```

Revoking a role that isn't assigned is a no-op.

## Checking if a user has a role

```php
$user->hasRole('editor');
```

Returns `true` if the user holds the role globally.

### Checking within a scope

```php
$user->hasRole('editor', scope: $group);
```

Checks only scoped assignments for the given scope. A global `editor` role does not satisfy a scoped `hasRole()` check.

> The `role` middleware behaves differently — it includes both global and scoped assignments. See [restricting routes with middleware](../integrations/restricting-routes-with-middleware.md#requiring-a-scoped-role) for details.

## Querying role membership from a scope

If you have a `Scopeable` model, you can query who holds roles within it.

```php
$group->members();                    // all assignments in this scope
$group->membersWithRole('editor');    // only editors in this scope
```

Both return a `Collection` of `Assignment` models.

## Inspecting a role's permissions

```php
use DynamikDev\PolicyEngine\Contracts\RoleStore;

$permissions = app(RoleStore::class)->permissionsFor('editor');
// ['posts.create', 'posts.update.any', 'comments.create', ...]
```

## Finding a role by ID

```php
$role = app(RoleStore::class)->find('editor');
// Role { id: 'editor', name: 'Editor', is_system: false }
```

Returns `null` if the role doesn't exist.

## Listing all roles

```php
$roles = app(RoleStore::class)->all();
```

Returns a `Collection` of all `Role` models.
