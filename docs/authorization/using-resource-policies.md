# Using Resource Policies

Resource policies attach authorization rules directly to a resource instead of (or in addition to) granting them through roles. Use them for per-object access control -- making a document publicly readable, restricting a post to specific users, or denying deletion on archived records.

## Attaching a policy to a resource

Add the `HasResourcePolicies` trait to your model, then call `attachPolicy()` with a `PolicyStatement`.

```php
use DynamikDev\Marque\Concerns\HasResourcePolicies;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasResourcePolicies;

    protected $fillable = ['title', 'status'];
}
```

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

This makes `$post` readable by anyone. The `principalPattern: '*'` matches all principals.

### Restricting to a specific user

```php
$post->attachPolicy(new PolicyStatement(
    effect: Effect::Allow,
    action: 'posts.update',
    principalPattern: "App\\Models\\User:{$owner->id}",
    source: 'resource-policy',
));
```

The `principalPattern` uses the format `type:id`, where `type` is the model's morph class.

## How resource policies work

The `ResourcePolicyResolver` collects policies attached to the resource in the evaluation request. These policies are combined with identity policies (from role assignments) and boundary policies. The evaluator applies deny-wins across all sources -- a deny from any resolver blocks access regardless of allows from other resolvers.

Resource policies only participate in evaluation when a resource is present in the request. Permission checks without a resource (like `$user->canDo('posts.create')`) skip the resource policy resolver entirely.

## Building a Resource from a model

The `HasResourcePolicies` trait provides `toPolicyResource()`, which builds a `Resource` DTO from the model.

```php
$resource = $post->toPolicyResource();
// Resource { type: 'App\Models\Post', id: 1, attributes: ['title' => '...', 'status' => 'draft'] }
```

By default, `resourceAttributes()` returns the model's fillable attributes via `$this->only($this->getFillable())`. Override it to include computed or non-fillable values:

```php
class Post extends Model
{
    use HasResourcePolicies;

    protected $fillable = ['title', 'status'];

    protected function resourceAttributes(): array
    {
        return [
            'title' => $this->title,
            'status' => $this->status,
            'owner_id' => $this->user_id,
            'is_published' => $this->published_at !== null,
        ];
    }
}
```

> The method is named `toPolicyResource()` (not `toResource()`) to avoid collisions with Laravel API Resources.

## Passing resources through the Gate

The Gate hook recognizes three patterns for passing a resource into a permission check.

### Model with trait as single argument

```php
$user->can('posts.update', $post);
```

When `$post` uses `HasResourcePolicies`, the Gate hook calls `$post->toPolicyResource()` automatically.

### Scope and resource as two arguments

```php
$user->can('posts.update', [$team, $post]);
```

The first argument is resolved as the scope, the second as the resource. Use this when the permission check is both scoped and resource-aware.

### Resource DTO directly

```php
use DynamikDev\Marque\DTOs\Resource;

$resource = new Resource(
    type: 'posts',
    id: $postId,
    attributes: ['status' => 'draft'],
);

$user->can('posts.update', $resource);
```

Build the DTO manually when you do not have a model instance or need custom attributes.

### Passing resources to canDo

When calling `canDo()` directly, pass the resource via the `resource` parameter:

```php
$user->canDo('posts.update', resource: $post->toPolicyResource());
```

## Attaching type-level policies

Pass `null` as the resource ID to create a policy that applies to all instances of a resource type.

```php
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;

app(ResourcePolicyStore::class)->attach(
    'App\\Models\\Post',
    null,
    new PolicyStatement(
        effect: Effect::Deny,
        action: 'posts.delete',
        source: 'resource-policy',
    ),
);
```

This denies `posts.delete` on every `Post` instance. Type-level policies are combined with instance-level policies when a specific resource is queried.

### Instance-level policies

```php
$post->attachPolicy(new PolicyStatement(
    effect: Effect::Allow,
    action: 'posts.read',
    principalPattern: '*',
    source: 'resource-policy',
));
```

This only applies to this specific `$post` instance.

When both type-level and instance-level policies exist for the same resource, all of them are evaluated together. Deny-wins applies across the combined set.

## Detaching a policy from a resource

```php
$post->detachPolicy('posts.read');
```

This removes the policy matching the given action from this specific resource instance. Other actions attached to the same resource are not affected.

### Detaching a type-level policy

```php
app(ResourcePolicyStore::class)->detach('App\\Models\\Post', null, 'posts.delete');
```

## Including resource policies in policy documents

The `resource_policies` array in a policy document defines resource-level authorization rules.

```json
{
    "version": "2.0",
    "permissions": ["posts.read", "posts.update", "posts.delete"],
    "resource_policies": [
        {
            "resource_type": "App\\Models\\Post",
            "resource_id": null,
            "effect": "Deny",
            "action": "posts.delete",
            "principal_pattern": null,
            "conditions": []
        },
        {
            "resource_type": "App\\Models\\Post",
            "resource_id": "42",
            "effect": "Allow",
            "action": "posts.read",
            "principal_pattern": "*",
            "conditions": []
        }
    ]
}
```

Each entry in `resource_policies` requires `resource_type`, `effect`, and `action`. The `resource_id`, `principal_pattern`, and `conditions` fields are optional.

Set `resource_id` to `null` for type-level policies. Set `principal_pattern` to `"*"` for policies that apply to all users, or use `"type:id"` format to target a specific principal.

### Adding conditions to resource policies in documents

```json
{
    "resource_type": "App\\Models\\Post",
    "resource_id": null,
    "effect": "Allow",
    "action": "posts.update",
    "principal_pattern": null,
    "conditions": [
        {
            "type": "attribute_equals",
            "parameters": {
                "subject_key": "department_id",
                "resource_key": "department_id"
            }
        }
    ]
}
```

See [using conditions](using-conditions.md) for the full list of condition types and their parameters.

## Reference

### `HasResourcePolicies`

Trait for Eloquent models that act as authorization resources.

| Method                                      | Returns          | Description                                    |
| ------------------------------------------- | ---------------- | ---------------------------------------------- |
| `toPolicyResource()`                        | `Resource`       | Build a Resource DTO from this model            |
| `attachPolicy(PolicyStatement $statement)`  | `void`           | Attach an authorization policy to this instance |
| `detachPolicy(string $action)`              | `void`           | Remove a policy by action string                |

**Protected method:** `resourceAttributes(): array` -- override to customize the attributes embedded in the Resource DTO. Defaults to fillable attributes.

### `ResourcePolicyStore`

Contract for the resource policy persistence layer.

| Method                                                                         | Returns              | Description                                 |
| ------------------------------------------------------------------------------ | -------------------- | ------------------------------------------- |
| `forResource(string $type, string\|int\|null $id)`                            | `Collection<PolicyStatement>` | Get all policies for a resource (type + instance level) |
| `attach(string $resourceType, string\|int\|null $resourceId, PolicyStatement $statement)` | `void`               | Attach a policy to a resource               |
| `detach(string $resourceType, string\|int\|null $resourceId, string $action)` | `void`               | Remove a policy by action                   |

**Default implementation:** `DynamikDev\Marque\Stores\EloquentResourcePolicyStore`
