# Swapping Implementations

Every component is behind a contract. Replace any implementation by rebinding in the service container — traits, middleware, Blade directives, and commands all keep working.

## Replacing a store

```php
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use App\Auth\RedisPermissionStore;

// In a service provider's register() method
$this->app->bind(PermissionStore::class, RedisPermissionStore::class);
```

Everything that touches permissions now uses your Redis store.

## What you can replace

| Contract | Default | You might swap to... |
| --- | --- | --- |
| `PermissionStore` | `EloquentPermissionStore` | Redis, API-backed, config-driven |
| `RoleStore` | `EloquentRoleStore` | Redis, Postgres views, flat file |
| `AssignmentStore` | `EloquentAssignmentStore` | External identity service, LDAP |
| `BoundaryStore` | `EloquentBoundaryStore` | Config-driven, plan-based lookup |
| `ResourcePolicyStore` | `EloquentResourcePolicyStore` | JSON file, API-backed |
| `Evaluator` | `CachedEvaluator` | Custom caching strategy, external policy engine |
| `Matcher` | `WildcardMatcher` | Regex matcher, bitfield matcher |
| `ScopeResolver` | `ModelScopeResolver` | DTO-based resolver, string parser |
| `ConditionRegistry` | `DefaultConditionRegistry` | Custom registry with additional condition types |
| `DocumentParser` | `JsonDocumentParser` | YAML, TOML, custom format |
| `DocumentImporter` | `DefaultDocumentImporter` | Approval queue, audit-logged importer |
| `DocumentExporter` | `DefaultDocumentExporter` | Tenant-filtered, redacted exporter |

## Decorating instead of replacing

Add behavior without replacing the underlying implementation. Wrap the default in a decorator.

```php
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;

$this->app->bind(AssignmentStore::class, function ($app) {
    return new AuditingAssignmentStore(
        inner: $app->make(EloquentAssignmentStore::class),
        logger: $app->make(AuditLogger::class),
    );
});
```

Your `AuditingAssignmentStore` implements `AssignmentStore`, logs every call, and delegates the actual work to the Eloquent store.

## Writing a custom implementation

Implement the contract interface. All methods are documented with their expected behavior in the contract.

```php
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use Illuminate\Support\Collection;

class ConfigPermissionStore implements PermissionStore
{
    public function register(string|array $permissions): void
    {
        // no-op — permissions come from config
    }

    public function remove(string $id): void
    {
        throw new \RuntimeException('Cannot remove config-driven permissions.');
    }

    public function all(?string $prefix = null): Collection
    {
        $permissions = collect(config('permissions.all'));

        return $prefix
            ? $permissions->filter(fn (string $id) => str_starts_with($id, "{$prefix}."))
            : $permissions;
    }

    public function exists(string $id): bool
    {
        return in_array($id, config('permissions.all'), strict: true);
    }
}
```

## Dispatching events from custom implementations

The default stores dispatch events (`PermissionCreated`, `RoleUpdated`, `AssignmentCreated`, etc.) that drive cache invalidation. If your custom store modifies authorization state, dispatch the same events to keep the cache in sync.

```php
use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use Illuminate\Support\Facades\Event;

Event::dispatch(new AssignmentCreated($assignment));
```

See [listening to events](listening-to-events.md) for the full event list.

## Disabling caching

If your custom `Evaluator` handles its own caching, bind it directly to skip the `CachedEvaluator` wrapper:

```php
use DynamikDev\PolicyEngine\Contracts\Evaluator;

$this->app->bind(Evaluator::class, MyEvaluator::class);
```

Or bind the `DefaultEvaluator` directly to use the built-in evaluation logic without any caching:

```php
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;

$this->app->bind(Evaluator::class, DefaultEvaluator::class);
```

## Supporting a different document format

Replace the `DocumentParser` to support YAML, TOML, or any format:

```php
use DynamikDev\PolicyEngine\Contracts\DocumentParser;

class YamlDocumentParser implements DocumentParser
{
    public function parse(string $content): PolicyDocument { /* ... */ }
    public function serialize(PolicyDocument $document): string { /* ... */ }
    public function validate(string $content): ValidationResult { /* ... */ }
}

$this->app->bind(DocumentParser::class, YamlDocumentParser::class);
```

Your YAML format works everywhere the parser contract is used.

## Adding a custom PolicyResolver

Extend the evaluation pipeline by adding a `PolicyResolver` to the `resolvers` config array. Each resolver receives an `EvaluationRequest` and returns a collection of `PolicyStatement` objects.

```php
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\Enums\Effect;
use Illuminate\Support\Collection;

class MaintenanceModeResolver implements PolicyResolver
{
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

Register it in `config/policy-engine.php`:

```php
'resolvers' => [
    \DynamikDev\PolicyEngine\Resolvers\IdentityPolicyResolver::class,
    \DynamikDev\PolicyEngine\Resolvers\BoundaryPolicyResolver::class,
    \DynamikDev\PolicyEngine\Resolvers\ResourcePolicyResolver::class,
    \DynamikDev\PolicyEngine\Resolvers\SanctumPolicyResolver::class,
    \App\Auth\MaintenanceModeResolver::class,
],
```

The evaluator calls every resolver in order and merges the returned statements. Deny statements from any resolver override Allow statements from any other — order does not affect deny-wins logic.

> Return an empty collection from `resolve()` when your resolver has nothing to contribute. This is a no-op and adds no overhead to the evaluation.
