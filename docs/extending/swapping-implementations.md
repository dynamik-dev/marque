# Swapping Implementations

Every behavior in Policy Engine is behind a contract (interface). The package ships default Eloquent-based implementations, but you can replace any of them by rebinding in the service container. The DX layer — traits, middleware, Blade directives, commands — doesn't change.

## Replacing a store

```php
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use App\Auth\RedisPermissionStore;

// In a service provider's register() method
$this->app->bind(PermissionStore::class, RedisPermissionStore::class);
```

Every trait method, middleware check, and Artisan command that touches permissions now uses your Redis implementation.

## What you can replace

| Contract | Default | You might swap to... |
| --- | --- | --- |
| `PermissionStore` | `EloquentPermissionStore` | Redis, API-backed, config-driven |
| `RoleStore` | `EloquentRoleStore` | Redis, Postgres views, flat file |
| `AssignmentStore` | `EloquentAssignmentStore` | External identity service, LDAP |
| `BoundaryStore` | `EloquentBoundaryStore` | Config-driven, plan-based lookup |
| `Evaluator` | `CachedEvaluator` | Custom caching strategy, external policy engine |
| `Matcher` | `WildcardMatcher` | Regex matcher, bitfield matcher |
| `ScopeResolver` | `ModelScopeResolver` | DTO-based resolver, string parser |
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

Import, export, and Artisan commands all use the parser contract — your YAML format works everywhere.
