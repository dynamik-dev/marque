# Adding Policy Resolvers

Policy resolvers are the sources of authorization statements. The evaluator collects `PolicyStatement` objects from each resolver, filters them against the current request, and applies deny-wins to produce a final decision. Add a custom resolver when you need authorization logic that does not fit into roles, boundaries, or resource policies.

## Understanding the resolver chain

The evaluator runs each resolver in the order defined by the `resolvers` config array. Every resolver returns a `Collection` of `PolicyStatement` objects. The evaluator merges all statements, filters them by action/principal/resource/condition matching, then checks for denies before allows.

```php
// config/policy-engine.php

'resolvers' => [
    IdentityPolicyResolver::class,
    BoundaryPolicyResolver::class,
    ResourcePolicyResolver::class,
    SanctumPolicyResolver::class,
],
```

A deny from any resolver blocks the action regardless of allows from other resolvers. Order matters only for the `decidedBy` trace -- the first matching deny or allow is recorded as the deciding source.

## Creating a custom resolver

Implement the `PolicyResolver` interface. The `resolve()` method receives an `EvaluationRequest` and returns a `Collection` of `PolicyStatement` objects.

```php
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\Enums\Effect;
use Illuminate\Support\Collection;

class MaintenanceModeResolver implements PolicyResolver
{
    /**
     * @return Collection<int, PolicyStatement>
     */
    public function resolve(EvaluationRequest $request): Collection
    {
        if (! app()->isDownForMaintenance()) {
            return collect();
        }

        return collect([
            new PolicyStatement(
                effect: Effect::Deny,
                action: '*.*',
                source: 'maintenance-mode',
            ),
        ]);
    }
}
```

This resolver denies everything when the application is in maintenance mode. Return an empty collection when the resolver has nothing to contribute -- the evaluator skips it.

### Returning conditional statements

Resolvers can return statements with [conditions](../authorization/using-conditions.md). The evaluator checks conditions during filtering, so the resolver does not need to evaluate them.

```php
class GeoRestrictionResolver implements PolicyResolver
{
    public function resolve(EvaluationRequest $request): Collection
    {
        return collect([
            new PolicyStatement(
                effect: Effect::Allow,
                action: 'streaming.*',
                conditions: [
                    new Condition('attribute_in', [
                        'source' => 'environment',
                        'key' => 'country',
                        'values' => ['US', 'CA', 'GB'],
                    ]),
                ],
                source: 'geo-restriction',
            ),
        ]);
    }
}
```

## Registering your resolver

Add your resolver class to the `resolvers` array in `config/policy-engine.php`:

```php
use App\Auth\MaintenanceModeResolver;
use DynamikDev\PolicyEngine\Resolvers\BoundaryPolicyResolver;
use DynamikDev\PolicyEngine\Resolvers\IdentityPolicyResolver;
use DynamikDev\PolicyEngine\Resolvers\ResourcePolicyResolver;
use DynamikDev\PolicyEngine\Resolvers\SanctumPolicyResolver;

'resolvers' => [
    IdentityPolicyResolver::class,
    BoundaryPolicyResolver::class,
    ResourcePolicyResolver::class,
    SanctumPolicyResolver::class,
    MaintenanceModeResolver::class,
],
```

Position does not affect deny-wins behavior -- a deny from any resolver blocks access. However, the order determines which resolver's `source` string appears in `decidedBy` when multiple resolvers produce matching statements.

### Removing a built-in resolver

Remove a resolver from the array to disable it. For example, to disable Sanctum token scoping:

```php
'resolvers' => [
    IdentityPolicyResolver::class,
    BoundaryPolicyResolver::class,
    ResourcePolicyResolver::class,
    // SanctumPolicyResolver removed
],
```

### Injecting dependencies

The service container resolves each resolver class. Use constructor injection for dependencies:

```php
class FeatureFlagResolver implements PolicyResolver
{
    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function resolve(EvaluationRequest $request): Collection
    {
        if ($this->flags->isEnabled('beta-features')) {
            return collect();
        }

        return collect([
            new PolicyStatement(
                effect: Effect::Deny,
                action: 'beta.*',
                source: 'feature-flag:beta-features',
            ),
        ]);
    }
}
```

If your resolver needs special construction, bind it in a service provider:

```php
$this->app->singleton(FeatureFlagResolver::class, function ($app) {
    return new FeatureFlagResolver(
        flags: $app->make(FeatureFlagService::class),
    );
});
```

## Reference

### Built-in resolvers

| Resolver                  | Description                                                  |
| ------------------------- | ------------------------------------------------------------ |
| `IdentityPolicyResolver`  | RBAC: assignments to roles to permissions. Allow and deny statements from role permissions. |
| `BoundaryPolicyResolver`  | Permission ceilings per scope. Emits deny statements for permissions outside the boundary. |
| `ResourcePolicyResolver`  | Policies attached to resources via the `ResourcePolicyStore`. |
| `SanctumPolicyResolver`   | Restricts permissions to the current Sanctum token's abilities. Emits denies for anything outside the token scope. |

All resolver classes are in the `DynamikDev\PolicyEngine\Resolvers\` namespace.

### `PolicyResolver`

The contract every resolver implements.

| Method                                    | Returns                          | Description                              |
| ----------------------------------------- | -------------------------------- | ---------------------------------------- |
| `resolve(EvaluationRequest $request)`     | `Collection<int, PolicyStatement>` | Return statements relevant to the request |

**Default implementations:** `IdentityPolicyResolver`, `BoundaryPolicyResolver`, `ResourcePolicyResolver`, `SanctumPolicyResolver`

### `PolicyStatement`

The DTO that resolvers return.

| Property           | Type                | Description                                      |
| ------------------ | ------------------- | ------------------------------------------------ |
| `$effect`          | `Effect`            | `Effect::Allow` or `Effect::Deny`                |
| `$action`          | `string`            | Permission string to match (supports wildcards)  |
| `$principalPattern`| `?string`           | Principal to match, `"*"` for all, `null` for any |
| `$resourcePattern` | `?string`           | Resource to match, `"*"` for all, `null` for any  |
| `$conditions`      | `array<Condition>`  | Conditions that must pass for this statement      |
| `$source`          | `string`            | Label for tracing (appears in `decidedBy`)        |
