# Checking Permissions

Use `canDo()` to check whether a subject holds a specific permission. This is the primary authorization method — it resolves assignments, roles, permissions, boundaries, and deny rules in a single call.

## Checking a permission on a user

```php
$user->canDo('posts.create');
```

Returns `true` if any of the user's assigned roles grant `posts.create` (or a wildcard that covers it). Returns `false` otherwise.

## Checking a scoped permission

```php
$user->canDo('posts.create', scope: $group);
```

Pass any `Scopeable` model as the second argument. The check evaluates both scoped assignments (roles the user holds in that group) and global assignments (roles with no scope).

### Using a raw scope string

```php
$user->canDo('posts.create', scope: 'group::5');
```

Strings pass through as-is. Use this when you have the scope identifier but not the model.

## Checking the inverse

```php
$user->cannotDo('posts.delete');
```

`cannotDo()` is the negation of `canDo()`. It reads better in guard clauses and conditionals.

## Using canDo in a controller

```php
class PostController extends Controller
{
    public function store(Group $group)
    {
        abort_unless(auth()->user()->canDo('posts.create', scope: $group), 403);

        // create post...
    }
}
```

For pure permission checks with no business logic, `canDo()` directly in the controller is the simplest approach. When authorization depends on the state of a specific resource (ownership, flags, timestamps), use a [model policy](../integrations/integrating-with-model-policies.md) instead.

## Listing a user's effective permissions

```php
$permissions = $user->effectivePermissions();
```

Returns a flat array of permission strings the user is allowed, after deny rules have been applied. Useful for rendering UI states like checkbox grids.

### Listing effective permissions in a scope

```php
$permissions = $user->effectivePermissions(scope: $group);
```

This combines permissions from both global and scoped assignments, then filters out anything covered by a deny rule.

## Listing a user's roles

```php
$roles = $user->roles();
```

Returns a `Collection` of `Role` models — deduplicated across all scopes.

### Listing roles in a specific scope

```php
$roles = $user->rolesFor(scope: $group);
```

## Listing a user's assignments

```php
$assignments = $user->assignments();
```

Returns a `Collection` of `Assignment` models with `role_id` and `scope` on each.

### Listing assignments in a specific scope

```php
$assignments = $user->assignmentsFor(scope: $group);
```

## Debugging a permission decision

```php
$trace = $user->explain('posts.delete', scope: $group);
```

Returns an `EvaluationTrace` with the full decision path: which assignments were found, which permissions were checked, whether a boundary blocked it, and the final result.

```php
$trace->subject;      // "App\Models\User:42"
$trace->required;     // "posts.delete:group::5"
$trace->result;       // EvaluationResult::Allow or EvaluationResult::Deny
$trace->assignments;  // array of role/scope/permissions_checked
$trace->boundary;     // null or boundary denial message
$trace->cacheHit;     // bool
```

> `explain()` is disabled by default. Set `POLICY_ENGINE_EXPLAIN=true` in your `.env` or `config('policy-engine.explain', true)` to enable it. Calling `explain()` when disabled throws a `RuntimeException`.
