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
     * On non-tagged stores, increments a per-subject generation counter so
     * previously cached entries (keyed with the old generation) become
     * unreachable and expire naturally via TTL.
     */
    public static function flushSubject(CacheManager $cache, string $subjectType, string|int $subjectId): void
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(["pe:{$subjectType}:{$subjectId}"])->flush();

            return;
        }

        $genKey = self::generationKey($subjectType, $subjectId);
        /** @var int $current */
        $current = $store->get($genKey, 0);
        $store->forever($genKey, $current + 1);
    }

    /**
     * Get the current cache generation for a subject.
     *
     * Returns 0 for tagged stores (generation is not needed).
     * For non-tagged stores, returns the counter that gets incremented
     * on each flushSubject() call, which callers embed in cache keys.
     */
    public static function subjectGeneration(CacheManager $cache, string $subjectType, string|int $subjectId): int
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return 0;
        }

        /** @var int */
        return $store->get(self::generationKey($subjectType, $subjectId), 0);
    }

    private static function generationKey(string $subjectType, string|int $subjectId): string
    {
        return "policy-engine:gen:{$subjectType}:{$subjectId}";
    }

    /**
     * Flush all policy-engine cache entries.
     *
     * Uses tag-scoped flush when available. On non-tagged stores, increments
     * a global generation counter so all previously cached entries (keyed with
     * the old generation) become unreachable and expire naturally via TTL.
     */
    public static function flush(CacheManager $cache): void
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(['policy-engine'])->flush();

            return;
        }

        $genKey = 'policy-engine:gen:global';
        /** @var int $current */
        $current = $store->get($genKey, 0);
        $store->forever($genKey, $current + 1);
    }

    /**
     * Get the current global cache generation.
     *
     * Returns 0 for tagged stores (generation is not needed).
     * For non-tagged stores, returns the counter that gets incremented
     * on each flush() call, which callers embed in cache keys.
     */
    public static function globalGeneration(CacheManager $cache): int
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return 0;
        }

        /** @var int */
        return $store->get('policy-engine:gen:global', 0);
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
