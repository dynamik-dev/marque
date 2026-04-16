<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class CacheStoreResolver
{
    /**
     * Long TTL (30 days) for generation counters.
     *
     * Counters are not stored "forever" so that cache stores under memory
     * pressure can still evict them eventually, but the TTL is long enough
     * that it always outlives any cached evaluation result (which uses
     * the much shorter marque.cache.ttl). When a counter is missing on
     * read, we seed a fresh time-derived value to ensure no stale entry
     * keyed under a prior generation can collide.
     */
    private const GENERATION_TTL_SECONDS = 2_592_000;

    private const GLOBAL_GEN_KEY = 'marque:gen:global';

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
     * Tagged stores get both 'marque' (global) and per-subject tags,
     * allowing either per-subject or full invalidation.
     * Non-tagged stores return the raw store.
     */
    public static function forSubject(CacheManager $cache, string $subjectType, string|int $subjectId): CacheRepository
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return $store->tags([
                'marque',
                "pe:{$subjectType}:{$subjectId}",
            ]);
        }

        return $store;
    }

    /**
     * Flush cache entries for a single subject.
     *
     * On tagged stores, flushes only entries tagged with this subject.
     * On non-tagged stores, atomically increments a per-subject generation
     * counter with a long TTL so previously cached entries (keyed with the
     * old generation) become unreachable and expire naturally via TTL.
     */
    public static function flushSubject(CacheManager $cache, string $subjectType, string|int $subjectId): void
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(["pe:{$subjectType}:{$subjectId}"])->flush();

            return;
        }

        self::bumpGeneration($store, self::generationKey($subjectType, $subjectId));
    }

    /**
     * Get the current cache generation for a subject.
     *
     * Returns 0 for tagged stores (generation is not needed).
     * For non-tagged stores, returns the counter that gets incremented
     * on each flushSubject() call, which callers embed in cache keys.
     * If the counter is missing (eviction or first access), seeds a
     * fresh time-derived value so any prior cached entries cannot collide.
     */
    public static function subjectGeneration(CacheManager $cache, string $subjectType, string|int $subjectId): int
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return 0;
        }

        return self::readOrSeedGeneration($store, self::generationKey($subjectType, $subjectId));
    }

    private static function generationKey(string $subjectType, string|int $subjectId): string
    {
        return "marque:gen:{$subjectType}:{$subjectId}";
    }

    /**
     * Flush all marque cache entries.
     *
     * Uses tag-scoped flush when available. On non-tagged stores, atomically
     * increments a global generation counter with a long TTL so all previously
     * cached entries (keyed with the old generation) become unreachable and
     * expire naturally via TTL.
     */
    public static function flush(CacheManager $cache): void
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(['marque'])->flush();

            return;
        }

        self::bumpGeneration($store, self::GLOBAL_GEN_KEY);
    }

    /**
     * Get the current global cache generation.
     *
     * Returns 0 for tagged stores (generation is not needed).
     * For non-tagged stores, returns the counter that gets incremented
     * on each flush() call, which callers embed in cache keys.
     * If the counter is missing (eviction or first access), seeds a
     * fresh time-derived value so any prior cached entries cannot collide.
     */
    public static function globalGeneration(CacheManager $cache): int
    {
        $store = self::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            return 0;
        }

        return self::readOrSeedGeneration($store, self::GLOBAL_GEN_KEY);
    }

    /**
     * Atomically bump a generation counter, refreshing its TTL.
     *
     * Strategy: try add() to seed the counter if missing (atomic on Redis/
     * Memcached via SET NX); then increment() (atomic on most drivers);
     * finally put() to refresh the TTL window. If add() succeeded we are
     * the seeder and skip the increment because the seed value is already
     * fresh (max-seen + 1).
     */
    private static function bumpGeneration(CacheRepository $store, string $key): void
    {
        $seed = self::freshGenerationSeed();

        if ($store->add($key, $seed, self::GENERATION_TTL_SECONDS)) {
            return;
        }

        $store->increment($key, 1);

        /** @var int|false $current */
        $current = $store->get($key, false);

        if ($current !== false) {
            $store->put($key, (int) $current, self::GENERATION_TTL_SECONDS);
        }
    }

    /**
     * Read a generation counter, seeding a fresh value if missing.
     *
     * On a cache miss (eviction or never-set), seed via add() so any prior
     * cached entry keyed under an earlier generation cannot collide. The
     * seed is time-derived (microsecond precision) which guarantees it
     * exceeds any small integer counter that may have been in use before
     * eviction. add() is atomic; if another process beat us we fall back
     * to reading the value they wrote.
     */
    private static function readOrSeedGeneration(CacheRepository $store, string $key): int
    {
        /** @var int|null $value */
        $value = $store->get($key);

        if ($value !== null) {
            return (int) $value;
        }

        $seed = self::freshGenerationSeed();

        if ($store->add($key, $seed, self::GENERATION_TTL_SECONDS)) {
            return $seed;
        }

        /** @var int $existing */
        $existing = $store->get($key, $seed);

        return (int) $existing;
    }

    /**
     * Generate a fresh seed value that exceeds any prior plausible counter.
     *
     * Uses microsecond Unix time so any cached entry from a prior
     * generation (which used small integers) cannot share a key with
     * a new entry stored under the seeded generation.
     */
    private static function freshGenerationSeed(): int
    {
        return (int) (microtime(true) * 1000);
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
        $storeName = config('marque.cache.store', 'default');

        return $cache->store($storeName === 'default' ? null : $storeName);
    }
}
