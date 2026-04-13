# How Authorization Works

Marque evaluates permissions through a pipeline of policy resolvers, a wildcard matcher, and a set of rules that determine the final allow or deny decision. This page walks through the pipeline from the simplest case to the full evaluation model.

## Starting a permission check

The most common way to check a permission is through Laravel's Gate.

```php
$user->can('posts.create');
```

The `MarqueServiceProvider` registers a `Gate::before()` hook that intercepts any ability containing a dot. When the Gate sees `posts.create`, it routes the check to Marque instead of looking for a Laravel Policy or Gate definition. Non-dot abilities (like `viewAny` or `update`) pass through to Laravel's standard authorization.

This means every `$user->can()`, `$this->authorize()`, `Gate::allows()`, `@can` Blade directive, and `can:` middleware call works with Marque permissions out of the box -- as long as the ability string contains a dot.

## Understanding subjects and principals

A subject is any Eloquent model with the `HasPermissions` trait. Typically this is your `User` model.

```php
use DynamikDev\Marque\Concerns\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions;
}
```

When a permission check starts, the subject is converted into a `Principal` DTO that carries the model's morph class, primary key, and any custom attributes. The evaluator works with this DTO, not the model directly.

```php
// Internally, $user becomes:
// Principal { type: 'App\Models\User', id: 1, attributes: [] }
```

Override `principalAttributes()` on your model to include domain-specific context that conditions can inspect at evaluation time.

```php
protected function principalAttributes(): array
{
    return [
        'department_id' => $this->department_id,
        'tier' => $this->subscription_tier,
    ];
}
```

## Following the simplest path: roles and permissions

The core authorization model is: subjects are assigned roles, roles contain permissions, and the evaluator checks whether any assigned role grants the requested permission.

```php
// Setup: create a role with permissions, assign it to a user
Marque::role('editor')
    ->permissions(['posts.create', 'posts.update', 'posts.delete']);

$user->assign('editor');

// Check: does this user have posts.create?
$user->can('posts.create'); // true
$user->can('comments.moderate'); // false
```

The `IdentityPolicyResolver` handles this path. It looks up the user's assignments, collects every permission from those roles, and converts each one into a `PolicyStatement` with an `Allow` or `Deny` effect. The evaluator then checks whether any of those statements match the requested action.

## Tracing the full pipeline

Every permission check follows these steps, in order:

1. **Build the request.** The subject, action, scope, resource, and environment are assembled into an `EvaluationRequest`.
2. **Collect statements.** Each registered `PolicyResolver` produces `PolicyStatement` objects for this request.
3. **Filter to applicable statements.** The evaluator keeps only statements whose action, principal pattern, resource pattern, and conditions all match the request.
4. **Apply deny-wins.** If any applicable statement has a `Deny` effect, the result is Deny. Otherwise, if any statement has an `Allow` effect, the result is Allow. If no statements match at all, the result is Deny (default-deny).

The config file lists the resolvers that participate in every evaluation.

```php
// config/marque.php
'resolvers' => [
    IdentityPolicyResolver::class,  // roles and assignments
    BoundaryPolicyResolver::class,  // scope permission ceilings
    ResourcePolicyResolver::class,  // per-resource policies
    SanctumPolicyResolver::class,   // API token restrictions
],
```

All four resolvers run on every check. Their statements are combined into a single collection before the evaluator applies deny-wins logic.

## Scoping permissions to a team or group

Scopes let you assign roles within a context -- a team, an organization, a project. A user might be an `editor` in one team and a `viewer` in another.

```php
$user->assign('editor', scope: $team);

$user->can('posts.create', $team); // true
$user->can('posts.create', $otherTeam); // false (no assignment here)
```

Scope models use the `Scopeable` trait, which converts them into a `type::id` string (e.g., `team::5`). You can also pass raw scope strings directly.

```php
$user->can('posts.create', 'team::5');
```

When a scoped check runs, the `IdentityPolicyResolver` collects both scoped assignments (roles held in that specific scope) and global assignments (roles with no scope). Global roles apply everywhere.

```php
$user->assign('member');             // global -- applies in every scope
$user->assign('editor', scope: $team); // scoped -- applies only in $team

$user->can('posts.create', $team);      // checks both 'member' and 'editor' roles
$user->can('posts.create', $otherTeam); // checks only 'member' role
```

## Setting permission ceilings with boundaries

Boundaries cap what permissions are possible within a scope, regardless of what roles are assigned. Even if a user holds a role that grants `posts.delete`, a boundary on the scope can block it.

```php
use DynamikDev\Marque\Facades\Marque;

Marque::boundary('team::5')
    ->allow(['posts.create', 'posts.update', 'posts.read']);

// posts.delete is NOT in the boundary ceiling
$user->assign('editor', scope: $team); // editor has posts.delete
$user->can('posts.delete', $team);     // false -- blocked by boundary
$user->can('posts.create', $team);     // true -- within the ceiling
```

The `BoundaryPolicyResolver` produces `Deny` statements for every registered permission that falls outside the boundary's ceiling. Because deny-wins applies across all resolvers, those denials override any allows from role assignments.

Boundaries only apply to scoped checks by default. Two config options change this behavior:

- `deny_unbounded_scopes` -- when `true`, any scoped check against a scope with no boundary defined denies all permissions
- `enforce_boundaries_on_global` -- when `true`, boundaries are also enforced on unscoped permission checks

See [setting permission boundaries](../authorization/setting-permission-boundaries.md) for the full configuration and usage guide.

## Using deny rules in roles

Prefix a permission with `!` to create a deny rule. Deny rules are converted into `PolicyStatement` objects with a `Deny` effect.

```php
Marque::role('restricted-editor')
    ->permissions(['posts.*', '!posts.delete']);
```

The wildcard `posts.*` grants all post permissions. The explicit `!posts.delete` deny overrides it. Because the evaluator processes deny-wins across all applicable statements, the deny always takes precedence.

```php
$user->assign('restricted-editor');

$user->can('posts.create'); // true -- matched by posts.*
$user->can('posts.delete'); // false -- denied by !posts.delete
```

Deny rules from any source block the action. If one role allows `posts.delete` and another role denies it, the denial wins.

```php
$user->assign('editor');             // has posts.delete
$user->assign('restricted-editor');  // has !posts.delete

$user->can('posts.delete'); // false -- deny from any role wins
```

## Matching permissions with wildcards

Permissions are dot-notated strings. The `WildcardMatcher` supports `*` segments that match one or more segments at any position.

| Pattern | Matches | Does not match |
| --- | --- | --- |
| `posts.create` | `posts.create` | `posts.update` |
| `posts.*` | `posts.create`, `posts.delete`, `posts.delete.own` | `comments.create` |
| `*.create` | `posts.create`, `comments.create` | `posts.delete` |
| `*.*` | Any two-or-more segment permission | (nothing excluded) |

A `*` at any segment position matches one or more segments. `posts.*` matches `posts.create` (one segment) and `posts.delete.own` (two segments).

## Attaching policies directly to resources

Resource policies attach rules to specific model instances or entire model types, bypassing the role system.

```php
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;

$post->attachPolicy(new PolicyStatement(
    effect: Effect::Allow,
    action: 'posts.read',
    principalPattern: '*',
    source: 'resource-policy',
));
```

The `ResourcePolicyResolver` collects these policies when the evaluation request includes a resource. Resource policies only participate in checks where a resource is present -- a bare `$user->can('posts.create')` with no model argument skips this resolver.

See [using resource policies](../authorization/using-resource-policies.md) for attaching, detaching, owner rules, and the fluent builder.

## Restricting access with Sanctum tokens

When a request is authenticated with a Sanctum token, the `SanctumPolicyResolver` compares the token's abilities against all registered permissions. Any permission not covered by the token's ability list receives a `Deny` statement.

```php
$token = $user->createToken('api', ['posts.read', 'posts.create']);
```

With this token, `posts.delete` is denied even if the user's roles grant it. A token with `['*']` as its abilities list skips this resolver entirely, applying no additional restrictions.

> Sanctum token abilities are matched against the permission string only. Scope information is not considered during token matching.

## Evaluating conditions at check time

Conditions are runtime checks attached to individual `PolicyStatement` objects. Every condition on a statement must pass for that statement to be considered applicable. If any condition fails, the statement is excluded from evaluation as if it did not exist.

The package ships with five built-in condition types:

| Type | What it checks |
| --- | --- |
| `attribute_equals` | A principal attribute equals a resource attribute |
| `attribute_in` | A principal, resource, or environment attribute is in an allowed set |
| `environment_equals` | An environment context value equals an expected value |
| `ip_range` | The request IP falls within one or more CIDR ranges |
| `time_between` | The current time falls within a time window |

Conditions are typically set through role permission definitions or resource policies, not through application code. The `attribute_equals` condition powers the `ownerCan()` resource policy builder:

```php
// This builder call:
Marque::resource(Post::class)
    ->ownerCan('posts.update');

// Produces a PolicyStatement with an attribute_equals condition that checks:
// principal.id === resource.user_id
```

For IP-based or time-based restrictions, conditions are attached to role permissions in a policy document or through the `RoleStore`:

```json
{
    "role": "night-operator",
    "permissions": [
        {
            "id": "systems.restart",
            "conditions": [
                {
                    "type": "time_between",
                    "parameters": { "start": "22:00", "end": "06:00", "timezone": "UTC" }
                }
            ]
        }
    ]
}
```

Custom condition types can be registered with the `ConditionRegistry`. See the contract reference below.

## Understanding default-deny

If no applicable statement matches the requested action from any resolver, the evaluator returns `Deny` with the source `default-deny`. The system does not fail open. You must explicitly grant permissions through roles, resource policies, or another resolver for any check to succeed.

## Inspecting an evaluation result

Use `explain()` to see the full evaluation result for a permission check, including which statements matched and which resolver decided the outcome.

```php
$result = $user->explain('posts.delete', $team);

$result->decision;    // Decision::Deny
$result->decidedBy;   // 'boundary:team::5'
```

The `decidedBy` string tells you which source produced the deciding statement. Common values:

| `decidedBy` value | Meaning |
| --- | --- |
| `role:editor` | A role's permission statement decided |
| `boundary:team::5` | A scope boundary blocked the action |
| `boundary:unbounded:team::5` | The scope has no boundary and `deny_unbounded_scopes` is enabled |
| `sanctum-token` | The Sanctum token does not include this permission |
| `resource-policy` | A resource-level policy decided |
| `default-deny` | No statements matched the request at all |

> Enable the `trace` config option to populate `matchedStatements` on the result. Keep this disabled in production -- it exposes internal authorization details. See [protecting the explain trace](../extending/security-considerations.md#protecting-the-explain-trace).

## Caching evaluation results

The `CachedEvaluator` wraps the `DefaultEvaluator` and caches results keyed by principal, action, scope, and resource. The default TTL is 300 seconds (5 minutes). Cache is automatically invalidated when assignments, roles, permissions, or boundaries change.

```php
// config/marque.php
'cache' => [
    'enabled' => true,
    'store' => env('MARQUE_CACHE_STORE', 'default'),
    'ttl' => 60 * 5,
],
```

See [customizing the cache](../extending/customizing-the-cache.md) for details on invalidation strategies, per-subject flushing, and non-tagged store behavior.

## Reference

### Policy resolvers

Each resolver implements the `PolicyResolver` contract and produces `PolicyStatement` objects for a given `EvaluationRequest`.

| Resolver | Source | What it produces |
| --- | --- | --- |
| `IdentityPolicyResolver` | Role assignments | Allow/Deny statements from the subject's assigned roles |
| `BoundaryPolicyResolver` | Scope boundaries | Deny statements for permissions outside the boundary ceiling |
| `ResourcePolicyResolver` | Resource policies | Allow/Deny statements attached to the resource in the request |
| `SanctumPolicyResolver` | Sanctum tokens | Deny statements for permissions not in the token's ability list |

### `PolicyStatement`

The unit of authorization data. Every resolver produces these, and the evaluator filters and decides on them.

| Property | Type | Description |
| --- | --- | --- |
| `$effect` | `Effect` | `Allow` or `Deny` |
| `$action` | `string` | The permission pattern this statement covers |
| `$principalPattern` | `?string` | Principal to match (`type:id`, `*`, or `null` for any) |
| `$resourcePattern` | `?string` | Resource to match (`type:id`, `*`, or `null` for any) |
| `$conditions` | `array<Condition>` | Runtime conditions that must all pass |
| `$source` | `string` | Identifier for where this statement originated |

### `EvaluationRequest`

The input to every evaluation.

| Property | Type | Description |
| --- | --- | --- |
| `$principal` | `Principal` | The subject making the request |
| `$action` | `string` | The permission being checked |
| `$resource` | `?Resource` | The resource being acted on, if any |
| `$context` | `Context` | Scope and environment data |

### `EvaluationResult`

The output of every evaluation.

| Property | Type | Description |
| --- | --- | --- |
| `$decision` | `Decision` | `Allow` or `Deny` |
| `$decidedBy` | `string` | Source identifier of the deciding statement |
| `$matchedStatements` | `array<PolicyStatement>` | All applicable statements (when tracing is enabled) |
| `$trace` | `array<string>` | Trace log entries |

### `ConditionRegistry`

Manages the mapping from condition type strings to evaluator classes.

| Method | Returns | Description |
| --- | --- | --- |
| `register(string $type, string $evaluatorClass)` | `void` | Register a condition type with its evaluator class |
| `evaluatorFor(string $type)` | `ConditionEvaluator` | Get the evaluator for a condition type |

**Default implementation:** `DynamikDev\Marque\Conditions\DefaultConditionRegistry`

The five built-in condition types (`attribute_equals`, `attribute_in`, `environment_equals`, `ip_range`, `time_between`) are registered automatically by the service provider.
