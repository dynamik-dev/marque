<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Support;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CacheStoreResolver
{
    private static ?int $managerHash = null;

    private static ?CacheRepository $rawStore = null;

    /**
     * Resolve the raw configured cache store (without tags).
     */
    public static function store(CacheManager $cache): CacheRepository
    {
        self::resetIfStale($cache);

        return self::$rawStore ??= self::buildStore($cache);
    }

    /**
     * Resolve a tagged store scoped to a specific subject.
     *
     * Tagged stores get both 'policy-engine' (global) and per-subject tags,
     * allowing either per-subject or full invalidation.
     * Non-tagged stores return the raw store.
     */
    public static function forSubject(CacheManager $cache, string $subjectType, string|int $subjectId): CacheRepository
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return $store->tags([
                'policy-engine',
                "pe:{$subjectType}:{$subjectId}",
            ]);
        }

        return $store;
    }

    /**
     * Flush cache entries for a single subject.
     *
     * On tagged stores, flushes only entries tagged with this subject.
     * On non-tagged stores, falls back to clearing the entire store.
     */
    public static function flushSubject(CacheManager $cache, string $subjectType, string|int $subjectId): void
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(["pe:{$subjectType}:{$subjectId}"])->flush();

            return;
        }

        $store->clear();
    }

    /**
     * Flush all policy-engine cache entries.
     *
     * Uses tag-scoped flush when available, otherwise clears the entire store.
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
        self::$rawStore = null;
        self::$managerHash = null;
    }

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
}
