# Using Conditions

Conditions add runtime checks to permissions. A permission with conditions only applies when every condition passes. Use them when static role-based rules are not enough -- for example, restricting edits to users in the same department as the resource, or limiting access to business hours.

## Adding conditions to role permissions

Attach conditions to a `PolicyStatement` to make it context-dependent. The evaluator checks all conditions during permission evaluation, and the statement is only applied when every condition passes.

```php
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;

$statement = new PolicyStatement(
    effect: Effect::Allow,
    action: 'documents.update',
    conditions: [
        new Condition('attribute_equals', [
            'subject_key' => 'department_id',
            'resource_key' => 'department_id',
        ]),
    ],
    source: 'policy:same-department',
);
```

This statement allows `documents.update` only when the user's `department_id` matches the resource's `department_id`. If the condition fails, the statement is silently excluded from evaluation -- it behaves as if it does not exist.

Multiple conditions on the same statement are AND-ed together. All must pass for the statement to apply.

## Using the attribute_equals condition

Compare an attribute on the principal (user) against an attribute on the resource. Both attributes must exist for the condition to pass.

```php
new Condition('attribute_equals', [
    'subject_key' => 'department_id',
    'resource_key' => 'department_id',
]);
```

| Parameter      | Type     | Description                                      |
| -------------- | -------- | ------------------------------------------------ |
| `subject_key`  | `string` | Key to read from the principal's attributes       |
| `resource_key` | `string` | Key to read from the resource's attributes         |

The condition fails when:
- No resource is present in the evaluation request
- Either key is missing from its respective attributes array
- The values are not strictly equal

## Using the attribute_in condition

Check whether a value from any source (principal, resource, or environment) is in an allowed set.

```php
new Condition('attribute_in', [
    'source' => 'resource',
    'key' => 'status',
    'values' => ['draft', 'review'],
]);
```

| Parameter | Type       | Description                                          |
| --------- | ---------- | ---------------------------------------------------- |
| `source`  | `string`   | Where to read the value: `principal`, `resource`, or `environment` |
| `key`     | `string`   | Attribute key to look up in the source               |
| `values`  | `array`    | Allowed values (checked with strict comparison)      |

### Checking a principal attribute

```php
new Condition('attribute_in', [
    'source' => 'principal',
    'key' => 'tier',
    'values' => ['pro', 'enterprise'],
]);
```

### Checking an environment value

```php
new Condition('attribute_in', [
    'source' => 'environment',
    'key' => 'region',
    'values' => ['us-east-1', 'us-west-2'],
]);
```

> `attribute_in` uses strict type comparison. The integer `1` does not match the string `"1"`.

## Using the environment_equals condition

Check a single value in the environment context against an expected value.

```php
new Condition('environment_equals', [
    'key' => 'region',
    'value' => 'us-east-1',
]);
```

| Parameter | Type     | Description                              |
| --------- | -------- | ---------------------------------------- |
| `key`     | `string` | Key to read from `context.environment`   |
| `value`   | `mixed`  | Expected value (strict equality)         |

The condition fails if the key is absent from the environment array.

## Using the ip_range condition

Restrict access to requests from specific IP ranges using CIDR notation.

```php
new Condition('ip_range', [
    'ranges' => ['10.0.0.0/8', '172.16.0.0/12'],
]);
```

| Parameter | Type              | Description                    |
| --------- | ----------------- | ------------------------------ |
| `ranges`  | `array<string>`   | CIDR ranges or exact IPs       |

The evaluator reads the IP from `context.environment['ip']`. You must pass the IP when calling `canDo()`:

```php
$user->canDo('admin.access', environment: ['ip' => $request->ip()]);
```

Ranges support both CIDR notation (`10.0.0.0/8`) and exact IPs (`192.168.1.100`). The condition passes if the IP matches any range in the array.

> IPv4 only. The evaluator uses `ip2long()` internally.

## Using the time_between condition

Restrict access to a time window within a given timezone.

```php
new Condition('time_between', [
    'start' => '09:00',
    'end' => '17:00',
    'timezone' => 'America/New_York',
]);
```

| Parameter  | Type     | Description                              |
| ---------- | -------- | ---------------------------------------- |
| `start`    | `string` | Start time in `H:i` format              |
| `end`      | `string` | End time in `H:i` format                |
| `timezone` | `string` | IANA timezone (defaults to `UTC`)        |

The evaluator compares the current time (via `Carbon::now()`) against the window. Windows that wrap midnight are supported -- a start of `22:00` with an end of `06:00` permits overnight access.

## Exposing attributes for condition evaluation

Conditions read attributes from the `Principal` and `Resource` DTOs. Override the attribute methods on your models to control what data is available.

### Exposing principal attributes

On any model using `HasPermissions`, override `principalAttributes()`:

```php
use DynamikDev\Marque\Concerns\HasPermissions;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasPermissions;

    protected function principalAttributes(): array
    {
        return [
            'department_id' => $this->department_id,
            'tier' => $this->subscription?->tier,
        ];
    }
}
```

These attributes are embedded in the `Principal` DTO whenever `toPrincipal()` is called, which happens automatically during every `canDo()` or Gate check.

### Exposing resource attributes

On any model using `HasResourcePolicies`, override `resourceAttributes()`:

```php
use DynamikDev\Marque\Concerns\HasResourcePolicies;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasResourcePolicies;

    protected $fillable = ['title', 'department_id', 'status'];

    protected function resourceAttributes(): array
    {
        return [
            'department_id' => $this->department_id,
            'status' => $this->status,
            'owner_id' => $this->owner_id,
        ];
    }
}
```

The default `resourceAttributes()` returns `$this->only($this->getFillable())`. Override it when you need non-fillable attributes or computed values.

## Passing environment context

Environment data is passed through the `environment` parameter on `canDo()`:

```php
$user->canDo('admin.access', environment: [
    'ip' => $request->ip(),
    'region' => config('app.region'),
]);
```

Through the Gate, environment context is not directly supported. Use `canDo()` when you need to pass environment data:

```php
class AdminController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->cannotDo('admin.access', environment: ['ip' => $request->ip()])) {
            abort(403);
        }

        // ...
    }
}
```

## Registering a custom condition type

Implement the `ConditionEvaluator` interface and register it with the `ConditionRegistry`.

```php
use DynamikDev\Marque\Contracts\ConditionEvaluator;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\EvaluationRequest;

class OwnershipEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        if ($request->resource === null) {
            return false;
        }

        $ownerKey = $condition->parameters['owner_key'] ?? 'owner_id';

        return ($request->resource->attributes[$ownerKey] ?? null)
            === $request->principal->id;
    }
}
```

### Registering in a service provider

```php
use DynamikDev\Marque\Contracts\ConditionRegistry;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(ConditionRegistry::class)->register('ownership', OwnershipEvaluator::class);
    }
}
```

After registration, use the type string in any `Condition`:

```php
new Condition('ownership', ['owner_key' => 'created_by']);
```

## Reference

### Built-in condition types

| Type                 | Evaluator Class              | Description                                     |
| -------------------- | ---------------------------- | ----------------------------------------------- |
| `attribute_equals`   | `AttributeEqualsEvaluator`   | Compare principal and resource attribute values  |
| `attribute_in`       | `AttributeInEvaluator`       | Check a value is in an allowed set               |
| `environment_equals` | `EnvironmentEqualsEvaluator` | Match an environment context value               |
| `ip_range`           | `IpRangeEvaluator`           | CIDR range check on `environment['ip']`          |
| `time_between`       | `TimeBetweenEvaluator`       | Current time within a window                     |

All evaluator classes are in the `DynamikDev\Marque\Conditions\` namespace.

### `ConditionEvaluator`

The contract for custom condition evaluators.

| Method                                                  | Returns | Description                              |
| ------------------------------------------------------- | ------- | ---------------------------------------- |
| `passes(Condition $condition, EvaluationRequest $request)` | `bool`  | Return true if the condition is satisfied |

### `ConditionRegistry`

Manages the mapping from condition type strings to evaluator classes.

| Method                                                | Returns              | Description                              |
| ----------------------------------------------------- | -------------------- | ---------------------------------------- |
| `register(string $type, string $evaluatorClass)`      | `void`               | Register an evaluator for a type         |
| `evaluatorFor(string $type)`                          | `ConditionEvaluator` | Resolve the evaluator for a type         |

**Default implementation:** `DynamikDev\Marque\Conditions\DefaultConditionRegistry`
