# Checking Permissions

Use `$user->can()` to check whether a subject holds a specific permission. The package hooks into Laravel's Gate via `Gate::before()`, so dot-notated permissions like `posts.create` route through the Policy Engine evaluation pipeline automatically.

## Checking a permission on a user

```php
$user->can('posts.create');
```

Returns `true` if any of the user's assigned roles grant `posts.create` (or a wildcard that covers it).

## Checking a scoped permission

```php
$user->can('posts.create', $group);
```

Pass any `Scopeable` model as the second argument. The check evaluates both scoped assignments (roles the user holds in that group) and global assignments (roles with no scope).

### Using a raw scope string

```php
$user->can('posts.create', 'group::5');
```

Strings pass through as-is. Use this when you have the scope identifier but not the model.

## Checking the inverse

```php
$user->cannot('posts.delete');
```

`cannot()` is the negation of `can()`. It reads better in guard clauses and conditionals.

## Checking permissions in a controller

```php
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    public function store(Group $group)
    {
        $this->authorize('posts.create', $group);

        // create post...
    }

    public function destroy(Post $post)
    {
        abort_unless(Gate::allows('posts.delete', $post->group), 403);

        // delete post...
    }
}
```

`$this->authorize()` and `Gate::allows()` both route through the Gate hook for dot-notated permissions. When authorization depends on resource state (ownership, flags, timestamps), use a [model policy](../integrations/integrating-with-model-policies.md) instead.

## Using canDo and cannotDo directly

```php
$user->canDo('posts.create', scope: $group);
$user->cannotDo('posts.delete');
```

`canDo()` and `cannotDo()` bypass the Gate and call the evaluator directly. Prefer `$user->can()` in application code — it integrates with `@can`, `$this->authorize()`, and middleware.

Use `canDo()` inside [model policies](../integrations/integrating-with-model-policies.md#writing-a-policy-that-uses-cando), where the policy method needs to check permissions without re-entering the Gate.

### Passing a resource to canDo

```php
use DynamikDev\PolicyEngine\DTOs\Resource;

$resource = new Resource(type: 'post', id: $post->id);
$user->canDo('posts.update', scope: $group, resource: $resource);
```

The `resource` parameter makes the evaluation request available to `ResourcePolicyResolver` and any conditions that inspect resource attributes. Models that use the `HasResourcePolicies` trait can convert themselves with `$post->toPolicyResource()`.

### Passing environment data to canDo

```php
$user->canDo('posts.publish', scope: $group, environment: [
    'ip' => request()->ip(),
    'time' => now()->toIso8601String(),
]);
```

The `environment` array is forwarded to the evaluation context. Condition evaluators (like `ip_range` or `time_between`) read from it to make runtime decisions.

## Listing a user's effective permissions

```php
$permissions = $user->effectivePermissions();
```

Returns a flat array of permission strings the user is allowed from global assignments, after deny rules have been applied. Useful for rendering UI states like checkbox grids.

### Listing effective permissions in a scope

```php
$permissions = $user->effectivePermissions(scope: $group);
```

When a scope is provided, this combines permissions from both global and scoped assignments, then filters out anything covered by a deny rule or blocked by a boundary. Without a scope, only global assignments are included.

## Listing a user's roles

```php
$roles = $user->getRoles();
```

Returns a `Collection` of `Role` models — deduplicated across all scopes.

### Listing roles in a specific scope

```php
$roles = $user->getRolesFor(scope: $group);
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
$result = $user->explain('posts.delete', scope: $group);
```

Returns an `EvaluationResult` with the decision, the resolver that decided it, any matched policy statements, and a trace log.

```php
$result->decision;          // Decision::Allow or Decision::Deny
$result->decidedBy;         // "role:admin", "boundary:group::5", etc.
$result->matchedStatements; // array of PolicyStatement objects
$result->trace;             // array of string trace entries
```

Each entry in `matchedStatements` is a `PolicyStatement` with `effect`, `action`, `source`, and optional `conditions`. The `trace` array contains human-readable strings describing each step of the evaluation.

> The `matchedStatements` and `trace` arrays are only populated when the `trace` config key is `true`. Set `POLICY_ENGINE_TRACE=true` in your `.env` or `config('policy-engine.trace', true)` to enable it. When disabled, `explain()` still returns the `decision` and `decidedBy` fields, but the arrays are empty.
