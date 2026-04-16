<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Models\Permission;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

/**
 * Decorator that caches the expensive `all()` lookup used by boundary enforcement.
 *
 * The unfiltered `all()` is a hot path: BoundaryPolicyResolver calls it on every
 * authorization check involving a scope, and SanctumPolicyResolver calls it on every
 * Sanctum-scoped request. Without caching, this is `SELECT * FROM permissions` per eval.
 *
 * Write-through: all mutations delegate to the inner store (which dispatches events).
 * The `PermissionCreated`/`PermissionDeleted` event listeners trigger a full cache flush
 * via `CacheStoreResolver::flush()`, which invalidates the cached `all()` result automatically.
 *
 * Prefix-filtered `all($prefix)` calls bypass the cache: prefix queries are cheap
 * (LIKE on indexed PK) and seldom hot, so caching them adds key-explosion risk
 * without measurable benefit.
 */
class CachingPermissionStore implements PermissionStore
{
    private const ALL_CACHE_KEY = 'marque:permissions:all';

    public function __construct(
        private readonly PermissionStore $inner,
        private readonly CacheManager $cache,
    ) {}

    public function register(string|array $permissions): void
    {
        $this->inner->register($permissions);
    }

    public function remove(string $id): void
    {
        $this->inner->remove($id);
    }

    /**
     * Retrieve all permissions, serving the unfiltered set from cache when enabled.
     *
     * Prefix-filtered calls always go through to the underlying store: prefix
     * queries are cheap (indexed LIKE) and would multiply cache keys without
     * meaningful payoff.
     *
     * The cached entry is stored under the 'marque' tag (when the driver
     * supports tags) so that `CacheStoreResolver::flush()` — triggered by
     * `PermissionCreated`/`PermissionDeleted` events — automatically invalidates it.
     *
     * On non-tagged stores, the global generation counter is embedded in the
     * cache key so that flush() (which increments the generation) renders old
     * entries unreachable without clearing the entire store.
     *
     * @return Collection<int, Permission>
     */
    public function all(?string $prefix = null): Collection
    {
        if ($prefix !== null) {
            return $this->inner->all($prefix);
        }

        if (! config('marque.cache.enabled')) {
            return $this->inner->all();
        }

        $store = $this->resolveStore();
        $cacheKey = $this->allCacheKey();
        $cached = $store->get($cacheKey);

        if ($cached instanceof Collection) {
            return $cached;
        }

        $result = $this->inner->all();

        /** @var int $ttl */
        $ttl = config('marque.cache.ttl', 300);
        $store->put($cacheKey, $result, $ttl);

        return $result;
    }

    public function exists(string $id): bool
    {
        return $this->inner->exists($id);
    }

    public function find(string $id): ?Permission
    {
        return $this->inner->find($id);
    }

    public function removeAll(): void
    {
        $this->inner->removeAll();
    }

    /**
     * Build the cache key for the `all()` result.
     *
     * On tagged stores the key is static (tag-scoped flush handles invalidation).
     * On non-tagged stores the global generation counter is embedded so that
     * `CacheStoreResolver::flush()` renders old entries unreachable.
     */
    private function allCacheKey(): string
    {
        $globalGen = CacheStoreResolver::globalGeneration($this->cache);

        if ($globalGen > 0) {
            return self::ALL_CACHE_KEY.":g{$globalGen}";
        }

        return self::ALL_CACHE_KEY;
    }

    /**
     * Resolve the cache store, applying the 'marque' tag when supported.
     *
     * This ensures the permission cache is flushed together with all other
     * marque entries during `CacheStoreResolver::flush()`.
     */
    private function resolveStore(): CacheRepository
    {
        $store = CacheStoreResolver::store($this->cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return $store->tags(['marque']);
        }

        return $store;
    }
}
