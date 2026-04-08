<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Support;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CacheStoreResolver
{
    /**
     * Memoized per CacheManager instance ID, so Octane's fresh app
     * instances get fresh resolutions without manual resets.
     */
    private static ?int $managerHash = null;

    private static ?CacheRepository $resolvedStore = null;

    private static ?CacheRepository $rawStore = null;

    /**
     * Resolve the configured policy-engine cache store (without tag wrapping).
     */
    public static function store(CacheManager $cache): CacheRepository
    {
        self::resetIfStale($cache);

        return self::$rawStore ??= self::buildStore($cache);
    }

    /**
     * Resolve the configured store with tag support when available.
     *
     * Returns a tagged cache if the driver supports tags,
     * otherwise returns the raw store.
     */
    public static function resolve(CacheManager $cache): CacheRepository
    {
        self::resetIfStale($cache);

        return self::$resolvedStore ??= self::buildResolved($cache);
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

    /**
     * Reset memoized instances.
     */
    public static function reset(): void
    {
        self::$resolvedStore = null;
        self::$rawStore = null;
        self::$managerHash = null;
    }

    /**
     * Reset if the CacheManager instance changed (Octane, testing).
     */
    private static function resetIfStale(CacheManager $cache): void
    {
        $hash = spl_object_id($cache);

        if (self::$managerHash !== $hash) {
            self::reset();
            self::$managerHash = $hash;
        }
    }

    private static function buildStore(CacheManager $cache): CacheRepository
    {
        /** @var string $storeName */
        $storeName = config('policy-engine.cache.store', 'default');

        return $cache->store($storeName === 'default' ? null : $storeName);
    }

    private static function buildResolved(CacheManager $cache): CacheRepository
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return $store->tags(['policy-engine']);
        }

        return $store;
    }
}
