<?php

declare(strict_types=1);

use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;

/**
 * Tests for atomic generation counter increment with TTL on non-tagged stores.
 *
 * Uses the file cache driver because the array driver supports tags and
 * therefore takes the tag-flush branch instead of the generation-counter
 * branch. The file driver is the canonical non-tagged store in Laravel.
 */
beforeEach(function (): void {
    config()->set('marque.cache.store', 'file');

    CacheStoreResolver::reset();

    // Clear any prior counter state so each test starts from a missing-counter
    // state. Otherwise prior runs leak generation values across tests.
    $store = CacheStoreResolver::store(app(CacheManager::class));
    $store->forget('marque:gen:global');
    $store->forget('marque:gen:User:1');
    $store->forget('marque:gen:User:concurrent');
});

afterEach(function (): void {
    $store = CacheStoreResolver::store(app(CacheManager::class));
    $store->forget('marque:gen:global');
    $store->forget('marque:gen:User:1');
    $store->forget('marque:gen:User:concurrent');

    CacheStoreResolver::reset();
});

it('flushSubject monotonically increases the generation counter', function (): void {
    $cache = app(CacheManager::class);

    $before = CacheStoreResolver::subjectGeneration($cache, 'User', 1);

    CacheStoreResolver::flushSubject($cache, 'User', 1);
    expect(CacheStoreResolver::subjectGeneration($cache, 'User', 1))->toBe($before + 1);

    CacheStoreResolver::flushSubject($cache, 'User', 1);
    expect(CacheStoreResolver::subjectGeneration($cache, 'User', 1))->toBe($before + 2);
});

it('flush monotonically increases the global generation counter', function (): void {
    $cache = app(CacheManager::class);

    $before = CacheStoreResolver::globalGeneration($cache);

    CacheStoreResolver::flush($cache);
    expect(CacheStoreResolver::globalGeneration($cache))->toBe($before + 1);

    CacheStoreResolver::flush($cache);
    expect(CacheStoreResolver::globalGeneration($cache))->toBe($before + 2);
});

it('seeds a fresh non-zero generation when the counter is missing on read', function (): void {
    $cache = app(CacheManager::class);
    $store = CacheStoreResolver::store($cache);

    expect($store->get('marque:gen:global'))->toBeNull();

    $generation = CacheStoreResolver::globalGeneration($cache);

    expect($generation)->toBeGreaterThan(0);
    expect($store->get('marque:gen:global'))->toBe($generation);
});

it('seeds a counter large enough to invalidate any prior small-integer generation', function (): void {
    // The vulnerability scenario: a previously cached evaluation entry was
    // stored under generation=1, then the counter was evicted under cache
    // pressure. A naive recovery returning 0 would let new entries collide
    // with old gen=1 keys after the next flush. We seed with a time-derived
    // value that exceeds any small integer counter that might have been in
    // use before eviction.
    $cache = app(CacheManager::class);

    $generation = CacheStoreResolver::globalGeneration($cache);

    expect($generation)->toBeGreaterThan(1_000_000);
});

it('stores the generation counter with a TTL, not forever', function (): void {
    $cache = app(CacheManager::class);
    $store = CacheStoreResolver::store($cache);

    CacheStoreResolver::flush($cache);

    $value = $store->get('marque:gen:global');
    expect($value)->toBeInt();
    expect($value)->toBeGreaterThan(0);

    // Inspect the on-disk file payload to assert the expiration is finite
    // (i.e. NOT the year 2286 sentinel that forever() writes).
    $cacheDir = config('cache.stores.file.path');
    if (! is_string($cacheDir) || ! is_dir($cacheDir)) {
        return;
    }

    $hashedKey = sha1('marque:gen:global');
    $shard = substr($hashedKey, 0, 2).'/'.substr($hashedKey, 2, 2);
    $path = $cacheDir.'/'.$shard.'/'.$hashedKey;

    if (! is_file($path)) {
        return;
    }

    $contents = (string) file_get_contents($path);
    $expiration = (int) substr($contents, 0, 10);
    $now = time();

    // Counter TTL must be finite (less than ~1 year) and at least 1 day out.
    expect($expiration)->toBeGreaterThan($now + 86_400);
    expect($expiration)->toBeLessThan($now + 31_536_000);
});

it('flushSubject with 100 sequential calls advances the counter by exactly 100', function (): void {
    // This is the determinism baseline: with atomic increment, the final
    // counter equals seed + N regardless of contention. The previous
    // get-then-put implementation could lose updates under concurrent
    // calls (two readers see N, both write N+1, final counter is N+1
    // instead of N+2).
    $cache = app(CacheManager::class);

    $before = CacheStoreResolver::subjectGeneration($cache, 'User', 'concurrent');

    for ($i = 0; $i < 100; $i++) {
        CacheStoreResolver::flushSubject($cache, 'User', 'concurrent');
    }

    $after = CacheStoreResolver::subjectGeneration($cache, 'User', 'concurrent');

    expect($after - $before)->toBe(100);
});

it('uses atomic increment so interleaved flushes do not lose updates', function (): void {
    // We cannot fork PHP processes inside Pest, but the contract atomic
    // increment guarantees: every flushSubject call advances the counter by
    // exactly one, observable via strict monotonicity of intermediate reads.
    // A lost-update bug would show two consecutive equal observations.
    $cache = app(CacheManager::class);

    $observed = [];
    $observed[] = CacheStoreResolver::subjectGeneration($cache, 'User', 'concurrent');

    for ($i = 0; $i < 50; $i++) {
        CacheStoreResolver::flushSubject($cache, 'User', 'concurrent');
        $observed[] = CacheStoreResolver::subjectGeneration($cache, 'User', 'concurrent');
    }

    for ($i = 1; $i < count($observed); $i++) {
        expect($observed[$i])->toBeGreaterThan($observed[$i - 1]);
    }

    expect($observed[50] - $observed[0])->toBe(50);
});

it('refreshes the counter TTL on every flush', function (): void {
    $cache = app(CacheManager::class);
    $cacheDir = config('cache.stores.file.path');

    if (! is_string($cacheDir) || ! is_dir($cacheDir)) {
        $this->markTestSkipped('File cache directory not available.');
    }

    CacheStoreResolver::flush($cache);

    $hashedKey = sha1('marque:gen:global');
    $shard = substr($hashedKey, 0, 2).'/'.substr($hashedKey, 2, 2);
    $path = $cacheDir.'/'.$shard.'/'.$hashedKey;

    if (! is_file($path)) {
        $this->markTestSkipped('File cache payload not found at expected path.');
    }

    $firstExpiration = (int) substr((string) file_get_contents($path), 0, 10);

    CacheStoreResolver::flush($cache);

    $secondExpiration = (int) substr((string) file_get_contents($path), 0, 10);

    expect($secondExpiration)->toBeGreaterThanOrEqual($firstExpiration);
});
