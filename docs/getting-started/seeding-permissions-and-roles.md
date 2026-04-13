# Seeding Permissions and Roles

Define your permissions and roles in a seeder so they're consistent across environments. The `Marque` facade makes this declarative and idempotent.

## Registering permissions

```php
use DynamikDev\Marque\Facades\Marque;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        Marque::permissions([
            'posts.read',
            'posts.create',
            'posts.update.own',
            'posts.update.any',
            'posts.delete.own',
            'posts.delete.any',
            'comments.read',
            'comments.create',
            'comments.delete.own',
            'comments.delete.any',
            'members.invite',
            'members.remove',
        ]);
    }
}
```

Permissions are dot-notated strings. The convention is `resource.verb` or `resource.verb.qualifier`. Registration is idempotent -- running the seeder twice creates no duplicates.

## Creating roles with permissions

```php
Marque::createRole('viewer', 'Viewer', system: true)
    ->grant(['posts.read', 'comments.read']);

Marque::createRole('member', 'Member', system: true)
    ->grant([
        'posts.read', 'posts.create', 'posts.update.own', 'posts.delete.own',
        'comments.read', 'comments.create', 'comments.delete.own',
    ]);

Marque::createRole('admin', 'Admin', system: true)
    ->grant(['*.*']);
```

`createRole()` creates the role and returns a `RoleBuilder`, so you can chain `grant()` and `deny()` calls immediately. The `system: true` flag protects the role from being deleted at runtime.

### Adding deny rules to a role

```php
Marque::createRole('moderator', 'Moderator', system: true)
    ->grant(['posts.*', 'comments.*'])
    ->deny(['members.remove']);
```

This moderator can do anything with posts and comments, but is explicitly denied the ability to remove members. See [using deny rules](../authorization/using-deny-rules.md) for more on how deny resolution works.

### Modifying an existing role

Use `Marque::role()` to get a builder for a role that already exists.

```php
Marque::role('member')->grant(['members.invite']);
```

This throws a `RuntimeException` if the role has not been created yet. Use it in subsequent seeders or migrations that add permissions to roles created elsewhere.

### Looking up a role

```php
$role = Marque::getRole('moderator');
```

Returns the `Role` model, or `null` if the role does not exist.

## Running the seeder

```bash
php artisan db:seed --class=PermissionSeeder
```

Or add it to your `DatabaseSeeder`:

```php
$this->call(PermissionSeeder::class);
```

## Syncing permissions after changes

When you add or modify permissions in your seeder, re-run it. The sync command does this for you:

```bash
php artisan marque:sync
```

This calls your `PermissionSeeder` idempotently. Existing permissions and roles are updated, not duplicated.

## Verifying your setup

List everything you just seeded:

```bash
php artisan marque:permissions
php artisan marque:roles
```

Next: [checking permissions](../authorization/checking-permissions.md).
