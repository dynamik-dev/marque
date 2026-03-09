# Customizing the Cache

The `CachedEvaluator` wraps the `DefaultEvaluator` with Laravel's cache. It caches the result of each `can()` check and invalidates automatically when authorization state changes.

## How caching works

Every `canDo()` call generates a cache key based on the subject type, subject ID, and permission string:

```
policy-engine:{subject_type}:{subject_id}:{permission}
```

For example: `policy-engine:App\Models\User:42:posts.create:group::5`.

The cached value is a boolean. On cache hit, the evaluator skips all database queries and returns the stored result.

## Configuring the cache store

```php
// config/policy-engine.php
'cache' => [
    'enabled' => true,
    'store' => env('POLICY_ENGINE_CACHE_STORE', 'default'),
    'ttl' => 60 * 60,
],
```

The `store` value maps to a Laravel cache store defined in `config/cache.php`. Set it to `'redis'`, `'memcached'`, `'array'`, or any configured store. The value `'default'` uses your application's default cache store.

## Changing the TTL

```php
'cache' => [
    'ttl' => 60 * 30, // 30 minutes
],
```

The TTL is in seconds. Cached results expire after this duration even if no invalidation event fires.

## Disabling the cache entirely

```php
'cache' => [
    'enabled' => false,
],
```

When disabled, every `canDo()` call queries the database directly. The `CachedEvaluator` delegates straight to the `DefaultEvaluator` with no caching layer.

## How cache invalidation works

The package registers an `InvalidatePermissionCache` listener that flushes the entire cache store when any of these events fire:

- `AssignmentCreated` — a role was assigned
- `AssignmentRevoked` — a role was revoked
- `RoleUpdated` — a role's permissions changed
- `RoleDeleted` — a role was deleted
- `PermissionDeleted` — a permission was removed
- `BoundarySet` — a scope boundary was created or changed
- `BoundaryRemoved` — a scope boundary was removed

The flush is store-wide, not targeted per-subject. This is simple and correct — any authorization change could affect any subject's cached results.

## Clearing the cache manually

```bash
php artisan primitives:cache-clear
```

Or programmatically:

```php
use Illuminate\Cache\CacheManager;

app(CacheManager::class)
    ->store(config('policy-engine.cache.store'))
    ->flush();
```

## Replacing the caching strategy

If you need per-subject cache keys, tagged caches, or a completely different strategy, bind your own `Evaluator`:

```php
use DynamikDev\PolicyEngine\Contracts\Evaluator;

$this->app->bind(Evaluator::class, MyCustomCachedEvaluator::class);
```

Your evaluator implements the `Evaluator` contract and handles caching however you need. The `DefaultEvaluator` is still available for the actual evaluation logic:

```php
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;

class MyCustomCachedEvaluator implements Evaluator
{
    public function __construct(
        private readonly DefaultEvaluator $inner,
        private readonly CacheManager $cache,
    ) {}

    public function can(string $subjectType, string|int $subjectId, string $permission): bool
    {
        // your caching logic here
        return $this->inner->can($subjectType, $subjectId, $permission);
    }

    // ...
}
```

## Using no cache at all

Bind the `DefaultEvaluator` directly to bypass the `CachedEvaluator` wrapper entirely:

```php
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;

$this->app->bind(Evaluator::class, DefaultEvaluator::class);
```

> The `explain()` and `effectivePermissions()` methods are never cached, even when caching is enabled. Only `can()` results are cached.
