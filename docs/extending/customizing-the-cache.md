# Customizing the Cache

The `CachedEvaluator` wraps the `DefaultEvaluator` with Laravel's cache. It caches the result of each `can()` check and invalidates automatically when authorization state changes.

## How caching works

Every permission check generates a cache key based on the subject type, subject ID, and permission string:

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

When disabled, every permission check queries the database directly. The `CachedEvaluator` delegates straight to the `DefaultEvaluator` with no caching layer.

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
php artisan policy-engine:cache-clear
```

Or programmatically:

```php
use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;

CacheStoreResolver::flush(app(CacheManager::class));
```

This handles tag-scoped flushing when the driver supports it, and falls back to a full store clear otherwise.

## Understanding the cache race window

The `CachedEvaluator` uses a cache-aside pattern: check the cache, miss, query the database, write the result back. A narrow race condition exists between the cache miss and the cache write.

Here is the scenario step by step:

1. Request A checks the cache for `posts.delete` and gets a miss.
2. Request A begins querying the database to evaluate the permission.
3. Request B revokes the user's `editor` role and fires `AssignmentRevoked`, which flushes the cache.
4. Request A finishes its evaluation with the now-stale role data, writes `true` to the cache, and the stale result persists for the full TTL.

This is inherent to the cache-aside pattern, not a bug. The race window is small (milliseconds) and requires exact timing to trigger. For most applications, the default 1-hour TTL with event-based invalidation is sufficient. The sections below describe mitigations for security-critical workloads.

### Reducing the TTL

A shorter TTL limits how long a stale result can persist after the race occurs.

```php
// config/policy-engine.php
'cache' => [
    'ttl' => 60 * 5, // 5 minutes
],
```

For security-critical applications, 5 minutes or less keeps the exposure window tight. The tradeoff is more frequent database queries when cached results expire.

### Using a dedicated cache store

A dedicated Redis database (or separate store) for policy-engine cache isolates invalidation from your application cache. This prevents a `flush()` on a non-tagged store from clearing unrelated entries, and keeps policy-engine cache behavior predictable.

```php
// config/cache.php
'stores' => [
    'policy' => [
        'driver' => 'redis',
        'connection' => 'policy',
    ],
],

// config/database.php
'redis' => [
    'policy' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_POLICY_DB', '2'),
    ],
],
```

```php
// config/policy-engine.php
'cache' => [
    'store' => 'policy',
],
```

> If your cache driver supports tags (Redis, Memcached), the package already uses a `policy-engine` tag for scoped invalidation. A dedicated store is most useful for drivers that do not support tags, like the `file` or `database` drivers.

### Implementing a version-counter strategy

For workloads where even a millisecond-level race is unacceptable, implement a custom `Evaluator` that uses a version counter. The counter increments on every authorization change, so a stale write from a pre-increment evaluation is never read.

```php
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use Illuminate\Cache\CacheManager;

class VersionedCachedEvaluator implements Evaluator
{
    public function __construct(
        private readonly DefaultEvaluator $inner,
        private readonly CacheManager $cache,
    ) {}

    public function can(string $subjectType, string|int $subjectId, string $permission): bool
    {
        $store = $this->cache->store();
        $version = (int) $store->get('policy-engine:version', 0);
        $cacheKey = "policy-engine:v{$version}:{$subjectType}:{$subjectId}:{$permission}";

        return $store->remember($cacheKey, 3600, function () use ($subjectType, $subjectId, $permission): bool {
            return $this->inner->can($subjectType, $subjectId, $permission);
        });
    }

    // ... implement explain() and effectivePermissions() by delegating to $this->inner
}
```

Increment the version counter in your event listener whenever authorization state changes:

```php
Cache::increment('policy-engine:version');
```

Old versioned keys expire naturally via TTL. New evaluations always read the current version, so stale writes under a previous version number are invisible.

> This strategy trades cache storage (old version keys linger until TTL) for correctness. It works best with Redis or another store that handles TTL-based eviction efficiently.

### Accepting the tradeoff

For most applications, no mitigation is needed. The race requires all of the following to align:

- A cache miss on the exact permission being revoked
- A revocation event firing during the milliseconds between the miss and the cache write
- The stale write completing after the flush

The default 1-hour TTL with event-based invalidation covers the vast majority of use cases. If a revocation must take effect instantly across all in-flight requests, consider disabling the cache entirely for that operation using [the `enabled` config flag](#disabling-the-cache-entirely) or binding the `DefaultEvaluator` directly.

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
