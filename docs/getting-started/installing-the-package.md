# Installing the Package

## Requiring the package

```bash
composer require dynamik-dev/laravel-marque
```

The service provider registers automatically via package discovery.

## Publishing the config

```bash
php artisan vendor:publish --tag=marque-config
```

This creates `config/marque.php`. See [configuration reference](../reference/configuration.md) for all options.

## Running the migrations

```bash
php artisan migrate
```

The package creates five tables: `permissions`, `roles`, `role_permissions`, `assignments`, and `boundaries`. If you need to customize the migrations before running them, publish them first:

```bash
php artisan vendor:publish --tag=marque-migrations
```

## Adding the trait to your User model

Any Eloquent model can be a permission subject. Add the `HasPermissions` trait to your User model (or any model that needs authorization).

```php
use DynamikDev\Marque\Concerns\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions;
}
```

This gives the model `assign()`, `revoke()`, `hasRole()`, `getRoles()`, and powers `$user->can()` for dot-notated permissions via the Gate hook.

## Making a model act as a scope

If you have models that represent containers — teams, groups, organizations — add the `Scopeable` trait so they can be used as permission scopes.

```php
use DynamikDev\Marque\Attributes\ScopeType;
use DynamikDev\Marque\Concerns\Scopeable;

#[ScopeType('group')]
class Group extends Model
{
    use Scopeable;
}
```

The scope type defines the prefix used in scope strings. A Group with ID 5 resolves to `group::5`. If you omit `#[ScopeType]`, the type is inferred from the class name (`Group` → `'group'`), so the attribute is only needed when you want a name that differs from the class.

## Verifying the installation

Run the permissions list command to confirm everything is wired up:

```bash
php artisan marque:permissions
```

If the table is empty, that's expected — you haven't seeded any permissions yet. Head to [seeding permissions and roles](seeding-permissions-and-roles.md) to set up your initial authorization config.
