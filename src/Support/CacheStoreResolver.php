<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Support;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CacheStoreResolver
{
    /**
     * Resolve the configured policy-engine cache store (without tag wrapping).
     */
    public static function store(CacheManager $cache): CacheRepository
    {
        /** @var string $storeName */
        $storeName = config('policy-engine.cache.store', 'default');

        return $cache->store($storeName === 'default' ? null : $storeName);
    }

    /**
     * Resolve the configured store with tag support when available.
     *
     * Returns a tagged cache if the driver supports tags,
     * otherwise returns the raw store.
     */
    public static function resolve(CacheManager $cache): CacheRepository
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return $store->tags(['policy-engine']);
        }

        return $store;
    }

    /**
     * Flush all policy-engine cache entries.
     *
     * Uses tag-scoped flush when the store supports tags,
     * otherwise clears the entire store.
     */
    public static function flush(CacheManager $cache): void
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(['policy-engine'])->flush();

            return;
        }

        $store->clear();
    }
}
