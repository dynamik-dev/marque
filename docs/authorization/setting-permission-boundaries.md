# Setting Permission Boundaries

Boundaries define the maximum permissions available within a scope. They act as a ceiling — even if a user's role grants a permission, the boundary can block it if that permission isn't in the scope's allowed set.

## Setting a boundary on a scope

```php
use DynamikDev\PolicyEngine\Facades\PolicyEngine;

PolicyEngine::boundary('org::acme', [
    'posts.*',
    'comments.*',
]);
```

This scope now has a ceiling. Only permissions matching `posts.*` or `comments.*` can be exercised within `org::acme`. Any other permission is denied regardless of role assignments.

## How boundary enforcement works

The evaluator checks boundaries before processing allow/deny rules. The order is:

1. Find the user's assignments (global + scoped)
2. Check the scope's boundary (if one exists)
3. If the required permission is **not** covered by any boundary pattern, deny immediately
4. If the boundary passes, proceed to normal deny/allow evaluation

```php
PolicyEngine::boundary('org::acme', ['posts.*', 'comments.*']);

PolicyEngine::role('admin', 'Admin')->grant(['*.*']);
$user->assign('admin', scope: 'org::acme');

$user->can('posts.create', 'org::acme');    // true — within boundary
$user->can('members.remove', 'org::acme');  // false — outside boundary
```

The admin role grants `*.*`, but the boundary restricts the scope to posts and comments only.

## Boundaries only apply to scoped checks by default

A boundary set on `org::acme` only affects permission checks made within that scope. Global checks (no scope) are not affected by any boundary, unless you enable [`enforce_boundaries_on_global`](#enforcing-boundaries-on-global-checks).

```php
$user->can('members.remove');                       // true — no scope, no boundary
$user->can('members.remove', 'org::acme');          // false — blocked by boundary
```

## Updating a boundary

```php
PolicyEngine::boundary('org::acme', [
    'posts.*',
    'comments.*',
    'members.invite',
]);
```

Calling `boundary()` again replaces the entire permission set. There is no merge — you always provide the full list.

## Removing a boundary

```php
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;

app(BoundaryStore::class)->remove('org::acme');
```

With the boundary removed, the scope has no ceiling and all permissions are available based on role assignments alone.

## Querying an existing boundary

```php
$boundary = app(BoundaryStore::class)->find('org::acme');

$boundary->scope;            // "org::acme"
$boundary->max_permissions;  // ["posts.*", "comments.*"]
```

Returns `null` if no boundary exists for the scope.

## Boundaries support wildcards

Boundary patterns use the same wildcard matching as permissions:

| Pattern | Covers |
| --- | --- |
| `posts.*` | `posts.create`, `posts.delete.any`, etc. |
| `*.read` | `posts.read`, `comments.read`, etc. |
| `*.*` | Everything (effectively no boundary) |

## Use cases for boundaries

- **Plan-based feature gating** — a free plan scope gets `['posts.read', 'comments.read']`, a pro plan gets `['posts.*', 'comments.*', 'analytics.*']`
- **Tenant isolation** — each tenant's scope boundary limits what permissions are possible, regardless of what roles exist
- **Regulatory compliance** — certain scopes are structurally prevented from accessing specific resources

> Boundaries are checked per-scope. If a user has roles in multiple scopes, each scope's boundary applies independently. A permission allowed in one scope can be blocked by a different scope's boundary.

## Denying permissions in scopes without boundaries

By default, if a scope has no boundary defined, permissions are unrestricted in that scope. The `deny_unbounded_scopes` config option changes this to fail-closed: scoped permission checks are denied when no boundary exists for the scope.

```php
// config/policy-engine.php
'deny_unbounded_scopes' => true,
```

```php
PolicyEngine::boundary('team::5', ['posts.*']);

$user->can('posts.create', 'team::5');  // true — boundary exists and allows it
$user->can('posts.create', 'team::99'); // false — no boundary for team::99
```

This is useful when every scope must be explicitly configured before permissions are granted within it. Global (unscoped) checks are unaffected.

## Enforcing boundaries on global checks

By default, global (unscoped) assignments bypass all boundary checks. A user with a global `admin` role can exercise any permission without scope restrictions, even if boundaries exist on every scope.

The `enforce_boundaries_on_global` config option changes this behavior. When enabled, unscoped permission checks must pass at least one boundary's `max_permissions` to be allowed.

```php
// config/policy-engine.php
'enforce_boundaries_on_global' => true,
```

With this enabled:

```php
PolicyEngine::boundary('team::5', ['posts.*']);
PolicyEngine::boundary('org::acme', ['billing.*']);

PolicyEngine::role('admin', 'Admin')->grant(['*.*']);
$user->assign('admin'); // global assignment, no scope

$user->can('posts.create');     // true — matches team::5 boundary
$user->can('billing.manage');   // true — matches org::acme boundary
$user->can('members.remove');   // false — no boundary allows it
```

If no boundaries are defined at all, global checks still pass. The restriction only applies when at least one boundary exists.

This is recommended for multi-tenant applications where global roles should not bypass scope ceilings. It prevents a globally-assigned admin from accessing permissions that no scope has been configured to allow.
