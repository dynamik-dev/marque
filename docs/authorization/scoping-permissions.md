# Scoping Permissions

Scopes let you assign roles within a context — a team, group, organization, or any model that represents a container. A user can be an admin in one group and a viewer in another, with a single set of role definitions.

## How scopes work

A scope is a `type::id` string like `group::5` or `org::acme`. When a user is assigned a role with a scope, that role only applies when checking permissions within that scope. Global assignments (no scope) apply everywhere.

## Making a model act as a scope

Add the `Scopeable` trait. The scope type is inferred from the class name by default:

```php
use DynamikDev\Marque\Concerns\Scopeable;

class Team extends Model
{
    use Scopeable;
}

$team->toScope(); // "team::12"
```

### Overriding the scope type

Use the `#[ScopeType]` attribute when you need a name that differs from the class:

```php
use DynamikDev\Marque\Attributes\ScopeType;
use DynamikDev\Marque\Concerns\Scopeable;

#[ScopeType('org')]
class Organization extends Model
{
    use Scopeable;
}

$org->toScope(); // "org::1"
```

You can also use a property: `protected string $scopeType = 'org';`. The resolution order is attribute > property > class basename.

## Assigning a role within a scope

```php
$user->assign('editor', scope: $team);
```

This user now holds the `editor` role, but only in the context of this team.

### Assigning the same role in different scopes

```php
$user->assign('admin', scope: $teamAlpha);
$user->assign('viewer', scope: $teamBeta);
```

The same user can hold different roles in different scopes simultaneously.

## Checking permissions in a scope

```php
$user->can('posts.create', $team);
```

This checks both:
1. Scoped assignments — roles the user holds specifically in `$team`
2. Global assignments — roles the user holds with no scope

If either path grants the permission, the check passes.

### Using a raw scope string

```php
$user->can('posts.create', 'team::12');
```

Raw strings work anywhere a scope is accepted. Use them when you have the identifier but not the model instance.

## Listing roles within a scope

```php
$roles = $user->getRolesFor(scope: $team);
```

Returns only the roles the user holds within this scope — not their global roles.

## Listing assignments within a scope

```php
$assignments = $user->assignmentsFor(scope: $team);
```

## Listing effective permissions within a scope

```php
$permissions = $user->effectivePermissions(scope: $team);
```

This combines permissions from both global and scoped assignments, applies deny rules and boundary filtering, and returns the net result.

## Querying members of a scope

From the scope model side, you can find who has roles within it.

```php
$team->members();                    // all assignments in this team
$team->membersWithRole('admin');     // just the admins
```

Both return `Collection<Assignment>` — each with `subject_type`, `subject_id`, `role_id`, and `scope`.

## Revoking a scoped role

```php
$user->revoke('editor', scope: $team);
```

This removes only the scoped assignment. Global assignments and assignments in other scopes are unaffected.

## How scope resolution works internally

The `ScopeResolver` contract handles converting scope arguments into strings:

| Input | Resolved to |
| --- | --- |
| `null` | `null` (global) |
| `'team::12'` | `'team::12'` (pass-through) |
| `$team` (Scopeable model) | `$team->toScope()` |
| Anything else | `InvalidArgumentException` |

You never need to call the resolver directly — `can()`, `canDo()`, `assign()`, `revoke()`, and the middleware all resolve scopes automatically.
