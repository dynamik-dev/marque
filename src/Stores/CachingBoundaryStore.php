<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Models\Boundary;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

/**
 * Decorator that caches the expensive `all()` lookup used by global boundary enforcement.
 *
 * Write-through: all mutations delegate to the inner store (which dispatches events).
 * The `BoundarySet`/`BoundaryRemoved` event listeners already trigger a full cache flush
 * via `CacheStoreResolver::flush()`, which invalidates the cached `all()` result automatically.
 */
class CachingBoundaryStore implements BoundaryStore
{
    private const ALL_CACHE_KEY = 'marque:boundaries:all';

    public function __construct(
        private readonly BoundaryStore $inner,
        private readonly CacheManager $cache,
    ) {}

    public function set(string $scope, array $maxPermissions): void
    {
        $this->inner->set($scope, $maxPermissions);
    }

    public function remove(string $scope): void
    {
        $this->inner->remove($scope);
    }

    public function find(string $scope): ?Boundary
    {
        return $this->inner->find($scope);
    }

    /**
     * Return all boundaries, serving from cache when the cache is enabled.
     *
     * The cached entry is stored under the 'marque' tag (when the driver
     * supports tags) so that `CacheStoreResolver::flush()` — triggered by
     * `BoundarySet`/`BoundaryRemoved` events — automatically invalidates it.
     *
     * On non-tagged stores, the global generation counter is embedded in the
     * cache key so that flush() (which increments the generation) renders old
     * entries unreachable without clearing the entire store.
     *
     * @return Collection<int, Boundary>
     */
    public function all(): Collection
    {
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
     * This ensures the boundary cache is flushed together with all other
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

    public function removeAll(): void
    {
        $this->inner->removeAll();
    }
}
