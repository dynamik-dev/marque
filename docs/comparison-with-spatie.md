# Comparing Marque with Spatie Permission

Both `dynamik-dev/laravel-marque` and `spatie/laravel-permission` solve role-based access control in Laravel. They make different trade-offs. This page lays out the differences so you can pick the right tool for your project.

## Quick comparison

| Feature | Marque | Spatie Permission |
| --- | --- | --- |
| Scoping model | Polymorphic `type::id` per assignment | Single `team_id` column, global mutable state |
| Deny rules | `!posts.delete` with deny-wins semantics | Not supported |
| Permission boundaries | Max permission ceilings per scope | Not supported |
| Wildcard matching | Built-in, on by default | Opt-in via config |
| Architecture | Contract-driven, 3-layer, fully swappable | Logic in traits and models |
| Policy documents | JSON import/export | Not supported |
| Caching strategy | Per-check caching (individual results) | Loads all permissions into memory per request |
| Gate integration | Two-lane: dot-notated to engine, non-dot to Policies | Registers permissions as Gate abilities |
| Octane safety | `spl_object_id`-based memoization | Known singleton state issues |
| Guard support | Single guard | Multiple guards (web, api, admin) |
| Route macros | Standard Laravel middleware | `Route::role()`, `Route::permission()` |
| Primary keys | String (`posts.create`) | Auto-increment integer |
| Direct permissions | Via hidden internal roles | Separate pivot table |
| Community | New package | 11k+ stars, large ecosystem |

## Choosing Marque

Pick Marque when your authorization model goes beyond flat role-permission lookups.

**You need real scoping.** Marque scopes assignments to any model via polymorphic `type::id` strings. A user can be an `admin` in one organization and a `viewer` in another, stored as separate assignments. Spatie uses a single `team_id` column set via global mutable state (`setPermissionsTeamId()`), which is error-prone in queued jobs, middleware ordering, and Octane's persistent worker processes.

**You need deny rules.** Marque supports deny permissions (`!posts.delete`) with deny-wins semantics. If any role in a user's assignments denies a permission, the denial stands regardless of other roles that allow it. Spatie has no deny concept -- you can only grant, not explicitly forbid.

**You need permission ceilings.** Boundaries let you cap what any role can do within a scope. Even if a user has `admin` globally, a boundary on `group::5` can restrict them to `posts.read` within that group. Spatie has no equivalent.

**You need portable authorization config.** Policy documents let you define roles, permissions, and boundaries in JSON, version them in git, and import them across environments. Spatie stores everything in the database with no built-in export format.

**You want swappable internals.** Every piece of Marque is coded to a contract. Swap the evaluator, the permission store, the scope resolver, or the cache layer without touching application code. Spatie's logic lives in traits and concrete Eloquent models.

**You run Laravel Octane.** Marque's `CacheStoreResolver` uses `spl_object_id` for safe per-request memoization. Spatie has [documented singleton state issues](https://github.com/spatie/laravel-permission/issues/2575) in long-running processes.

## Choosing Spatie Permission

Pick Spatie when simplicity and ecosystem matter more than advanced authorization features.

**Your RBAC is flat.** If you need roles and permissions without scoping, deny rules, or boundaries, Spatie is simpler. Fewer concepts means less to learn and less to misconfigure.

**You need multiple auth guards.** Spatie separates permissions by guard (`web`, `api`, `admin`). Each guard maintains its own permission set. Marque does not distinguish between guards.

**You need route macros.** Spatie provides `Route::role('admin')` and `Route::permission('posts.edit')` as convenience methods. Marque uses Laravel's standard `can:` middleware and a dedicated `role:` middleware.

**Ecosystem trust matters.** Spatie has 11k+ GitHub stars, years of production use, and a large community answering questions. Marque is newer with a smaller community.

**You need exact role matching.** Spatie's `hasExactRoles()` checks whether a user has precisely a given set of roles, no more and no less. Marque does not have this method.

**You want extensive migration tooling.** Spatie ships commands for upgrading between major versions of its own schema. Marque's migration story is simpler but less mature.

## Comparing scoping models

This is the largest architectural difference between the two packages.

### Marque: polymorphic scopes

```php
$user->assign('editor', scope: $organization);
$user->assign('viewer', scope: $project);

$user->can('posts.edit', $organization); // true
$user->can('posts.edit', $project);      // false
```

Each assignment carries its own scope. A user can hold different roles in different contexts simultaneously. Scopes are stored as `type::id` strings and accept any model that uses the `Scopeable` trait.

### Spatie: team_id global state

```php
setPermissionsTeamId($organization->id);
$user->assignRole('editor');

// Later, in a different context:
setPermissionsTeamId($project->id);
$user->assignRole('viewer');
```

Spatie uses a single global `team_id` that affects all permission operations. You must set it before every check or assignment. This works for simple multi-tenant apps but becomes fragile when you need to check permissions across multiple scopes in a single request, in queued jobs where global state may not be set, or in Octane where state persists between requests.

## Comparing deny rules and boundaries

### Deny rules

Marque lets you attach deny permissions to roles:

```php
$role->addPermissions(['posts.*', '!posts.delete']);
```

The user can do anything under `posts.*` except delete. Denials always win, regardless of how many other roles grant the permission.

Spatie has no deny concept. To restrict a permission, you must remove it from every role that grants it or restructure your role hierarchy.

### Boundaries

Marque lets you set maximum permission ceilings per scope:

```php
Marque::boundaries()->set('group::5', ['posts.read', 'posts.comment']);
```

Even if a user holds a role that grants `posts.delete`, the boundary on `group::5` caps their effective permissions to `posts.read` and `posts.comment` within that scope.

Spatie has no equivalent feature.

## Comparing caching strategies

**Marque** caches individual evaluation results (can-check, hasRole, effectivePermissions). Cache keys are scoped to the specific subject, permission, and scope being checked. Invalidation is granular -- changing a role's permissions only busts the cache entries that depend on that role.

**Spatie** loads all of a user's permissions into a single cached collection on the first permission check of each request. This is fast for repeated checks within one request but means every request pays the cost of loading the full permission set, even if only one permission is checked. For users with many roles across many teams, this collection can grow large.

## Comparing Gate integration

**Marque** hooks into Laravel's Gate with a two-lane model. Dot-notated abilities (`posts.create`, `comments.delete`) route to the policy engine. Non-dot abilities (`update`, `viewAny`) pass through to standard Laravel Policies. Both systems coexist without conflict.

**Spatie** registers every permission as a Gate ability. This means `$user->can('edit articles')` works directly, but custom Policies for the same ability name can conflict. Spatie provides a `GateRegistrar` to customize this behavior, but the default registration is global.

> For a deeper look at how Marque integrates with Laravel's Gate and Policies, see [Integrating with model policies](integrations/integrating-with-model-policies.md).
