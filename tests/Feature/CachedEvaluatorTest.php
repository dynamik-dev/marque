<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Evaluators\CachedEvaluator;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

function makeTestRequest(string $action, ?string $scope = null): EvaluationRequest
{
    return new EvaluationRequest(
        principal: new Principal(type: 'user', id: 1),
        action: $action,
        resource: null,
        context: new Context(scope: $scope),
    );
}

function makeAllowResult(): EvaluationResult
{
    return new EvaluationResult(
        decision: Decision::Allow,
        decidedBy: 'role:editor',
    );
}

function makeDenyResult(): EvaluationResult
{
    return new EvaluationResult(
        decision: Decision::Deny,
        decidedBy: 'default-deny',
    );
}

beforeEach(function (): void {
    config()->set('marque.cache.enabled', true);
    config()->set('marque.cache.store', 'array');
    config()->set('marque.cache.ttl', 300);
});

// --- Delegates on miss ---

it('calls inner evaluator once on cache miss and returns result', function (): void {
    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.create');
    $expected = makeAllowResult();

    $inner->expects('evaluate')
        ->once()
        ->with(Mockery::type(EvaluationRequest::class))
        ->andReturn($expected);

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));
    $result = $evaluator->evaluate($request);

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('role:editor');
});

// --- Returns cached on hit ---

it('returns cached result on second call without calling inner again', function (): void {
    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.read');

    $inner->expects('evaluate')
        ->once()
        ->andReturn(makeAllowResult());

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $first = $evaluator->evaluate($request);
    $second = $evaluator->evaluate($request);

    expect($first->decision)->toBe(Decision::Allow)
        ->and($second->decision)->toBe(Decision::Allow);
});

// --- Bypasses cache when disabled ---

it('bypasses cache when cache is disabled and calls inner twice', function (): void {
    config()->set('marque.cache.enabled', false);

    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.delete');

    $inner->expects('evaluate')
        ->twice()
        ->andReturn(makeDenyResult());

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $evaluator->evaluate($request);
    $evaluator->evaluate($request);
});

// --- Different requests cached separately ---

it('caches different actions under separate keys and calls inner once each', function (): void {
    $inner = Mockery::mock(Evaluator::class);
    $createRequest = makeTestRequest('posts.create');
    $deleteRequest = makeTestRequest('posts.delete');

    $inner->expects('evaluate')
        ->twice()
        ->andReturnUsing(function (EvaluationRequest $req): EvaluationResult {
            return $req->action === 'posts.create' ? makeAllowResult() : makeDenyResult();
        });

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $createResult = $evaluator->evaluate($createRequest);
    $deleteResult = $evaluator->evaluate($deleteRequest);

    // Call again to verify cache hit — no additional inner calls.
    $evaluator->evaluate($createRequest);
    $evaluator->evaluate($deleteRequest);

    expect($createResult->decision)->toBe(Decision::Allow)
        ->and($deleteResult->decision)->toBe(Decision::Deny);
});

// --- Resilient to corrupted cache values ---

it('falls through to inner evaluator when cached decision value is unrecognized', function (): void {
    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.update');

    $cache = app(CacheManager::class);
    $principal = $request->principal;
    $generation = CacheStoreResolver::subjectGeneration($cache, $principal->type, (string) $principal->id);
    $globalGeneration = CacheStoreResolver::globalGeneration($cache);
    $store = CacheStoreResolver::forSubject($cache, $principal->type, (string) $principal->id);
    $cacheKey = CachedEvaluator::key($request, $generation, $globalGeneration);

    $store->put($cacheKey, ['d' => 'Maybe', 'b' => 'foo'], 300);

    $inner->expects('evaluate')
        ->once()
        ->with(Mockery::type(EvaluationRequest::class))
        ->andReturn(makeAllowResult());

    $evaluator = new CachedEvaluator(inner: $inner, cache: $cache);
    $result = $evaluator->evaluate($request);

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('role:editor');

    // Subsequent call should now hit the rewritten valid cache entry.
    $second = $evaluator->evaluate($request);
    expect($second->decision)->toBe(Decision::Allow);
});

// --- Principal id type normalization ---

it('treats integer and string principal ids as the same cache key', function (): void {
    $intRequest = new EvaluationRequest(
        principal: new Principal(type: 'user', id: 5),
        action: 'posts.read',
        resource: null,
        context: new Context(scope: null),
    );

    $stringRequest = new EvaluationRequest(
        principal: new Principal(type: 'user', id: '5'),
        action: 'posts.read',
        resource: null,
        context: new Context(scope: null),
    );

    $inner = Mockery::mock(Evaluator::class);
    // Inner should be invoked exactly once — second call must hit cache despite
    // the principal id arriving as a string instead of an int.
    $inner->expects('evaluate')
        ->once()
        ->andReturn(makeAllowResult());

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $first = $evaluator->evaluate($intRequest);
    $second = $evaluator->evaluate($stringRequest);

    expect($first->decision)->toBe(Decision::Allow)
        ->and($second->decision)->toBe(Decision::Allow);
});

it('produces identical cache keys for integer and string principal ids', function (): void {
    $intRequest = new EvaluationRequest(
        principal: new Principal(type: 'user', id: 42),
        action: 'posts.update',
        resource: null,
        context: new Context(scope: 'group::7'),
    );

    $stringRequest = new EvaluationRequest(
        principal: new Principal(type: 'user', id: '42'),
        action: 'posts.update',
        resource: null,
        context: new Context(scope: 'group::7'),
    );

    expect(CachedEvaluator::key($intRequest, 0, 0))
        ->toBe(CachedEvaluator::key($stringRequest, 0, 0));
});

// --- CAS post-write generation re-check ---

/**
 * Bind a non-taggable cache store under the name 'untagged' so the CAS tests
 * exercise the generation-counter path. Built-in 'array' extends TaggableStore,
 * so `forSubject` returns a tagged repository and the generation counters stay
 * at 0 — making the race impossible to simulate via the resolver alone.
 */
function bindUntaggedCacheStore(): void
{
    $cache = app(CacheManager::class);
    $store = new Repository(
        new class implements Store
        {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get($key): mixed
            {
                return $this->data[$key] ?? null;
            }

            /**
             * @param  array<int, string>  $keys
             * @return array<string, mixed>
             */
            public function many(array $keys): array
            {
                $out = [];
                foreach ($keys as $key) {
                    $out[$key] = $this->data[$key] ?? null;
                }

                return $out;
            }

            public function put($key, $value, $seconds): bool
            {
                $this->data[$key] = $value;

                return true;
            }

            /**
             * @param  array<string, mixed>  $values
             */
            public function putMany(array $values, $seconds): bool
            {
                foreach ($values as $key => $value) {
                    $this->data[$key] = $value;
                }

                return true;
            }

            public function increment($key, $value = 1): int
            {
                /** @var int $current */
                $current = is_numeric($this->data[$key] ?? null) ? (int) $this->data[$key] : 0;
                /** @var int $value */
                $this->data[$key] = $current + (int) $value;

                /** @var int */
                return $this->data[$key];
            }

            public function decrement($key, $value = 1): int
            {
                /** @var int $value */
                return $this->increment($key, -$value);
            }

            public function forever($key, $value): bool
            {
                $this->data[$key] = $value;

                return true;
            }

            public function forget($key): bool
            {
                unset($this->data[$key]);

                return true;
            }

            public function flush(): bool
            {
                $this->data = [];

                return true;
            }

            public function getPrefix(): string
            {
                return '';
            }
        }
    );
    $cache->extend('untagged', fn () => $store);
    config()->set('cache.stores.untagged', ['driver' => 'untagged']);
    config()->set('marque.cache.store', 'untagged');
    CacheStoreResolver::reset();
}

it('deletes its own cache entry when the subject generation advances during evaluation', function (): void {
    bindUntaggedCacheStore();
    $request = makeTestRequest('posts.create');
    $cache = app(CacheManager::class);

    // Inner evaluator simulates the cache-aside race window: while it is
    // computing the result, a concurrent mutation commits and bumps the
    // subject generation counter. The CAS re-check must observe the bump
    // and forget the stale write.
    $inner = new class implements Evaluator
    {
        public function evaluate(EvaluationRequest $request): EvaluationResult
        {
            CacheStoreResolver::flushSubject(
                app(CacheManager::class),
                $request->principal->type,
                (string) $request->principal->id,
            );

            return makeAllowResult();
        }
    };

    $evaluator = new CachedEvaluator(inner: $inner, cache: $cache);
    $principal = $request->principal;
    $generationBefore = CacheStoreResolver::subjectGeneration($cache, $principal->type, (string) $principal->id);
    $globalBefore = CacheStoreResolver::globalGeneration($cache);
    $staleKey = CachedEvaluator::key($request, $generationBefore, $globalBefore);

    $result = $evaluator->evaluate($request);

    // Result is still returned to the caller.
    expect($result->decision)->toBe(Decision::Allow);

    // But the cache must not contain the stale entry under the pre-mutation
    // generation. Without CAS this entry would live until TTL.
    $store = CacheStoreResolver::forSubject($cache, $principal->type, (string) $principal->id);
    expect($store->get($staleKey))->toBeNull();
});

it('deletes its own cache entry when the global generation advances during evaluation', function (): void {
    bindUntaggedCacheStore();
    $request = makeTestRequest('posts.update');
    $cache = app(CacheManager::class);

    // Same race shape, but the concurrent mutation is a role/permission/
    // boundary change that bumps the global generation rather than a single
    // subject's generation.
    $inner = new class implements Evaluator
    {
        public function evaluate(EvaluationRequest $request): EvaluationResult
        {
            CacheStoreResolver::flush(app(CacheManager::class));

            return makeAllowResult();
        }
    };

    $evaluator = new CachedEvaluator(inner: $inner, cache: $cache);
    $principal = $request->principal;
    $generationBefore = CacheStoreResolver::subjectGeneration($cache, $principal->type, (string) $principal->id);
    $globalBefore = CacheStoreResolver::globalGeneration($cache);
    $staleKey = CachedEvaluator::key($request, $generationBefore, $globalBefore);

    $result = $evaluator->evaluate($request);

    expect($result->decision)->toBe(Decision::Allow);

    $store = CacheStoreResolver::forSubject($cache, $principal->type, (string) $principal->id);
    expect($store->get($staleKey))->toBeNull();
});

it('keeps its cache entry when no generation bump happens during evaluation', function (): void {
    bindUntaggedCacheStore();
    $request = makeTestRequest('posts.read');
    $cache = app(CacheManager::class);

    $inner = Mockery::mock(Evaluator::class);
    $inner->expects('evaluate')->once()->andReturn(makeAllowResult());

    $evaluator = new CachedEvaluator(inner: $inner, cache: $cache);
    $principal = $request->principal;
    $generationBefore = CacheStoreResolver::subjectGeneration($cache, $principal->type, (string) $principal->id);
    $globalBefore = CacheStoreResolver::globalGeneration($cache);
    $writtenKey = CachedEvaluator::key($request, $generationBefore, $globalBefore);

    $evaluator->evaluate($request);

    // CAS must not delete the entry when no concurrent flush occurred.
    $store = CacheStoreResolver::forSubject($cache, $principal->type, (string) $principal->id);
    expect($store->get($writtenKey))->toBe(['d' => Decision::Allow->value, 'b' => 'role:editor']);
});

// --- Trace bypass ---

it('bypasses cache when marque.trace is enabled so trace data survives repeated calls', function (): void {
    config()->set('marque.trace', true);

    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.publish');

    // Inner is invoked twice — cache is bypassed entirely under trace mode so
    // matchedStatements/trace returned by the inner evaluator are preserved
    // on every call (they would otherwise be dropped by the cache encoding).
    $tracedResult = new EvaluationResult(
        decision: Decision::Allow,
        decidedBy: 'role:editor',
        matchedStatements: [],
        trace: ['identity:role:editor allows posts.publish'],
    );

    $inner->expects('evaluate')
        ->twice()
        ->andReturn($tracedResult);

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $first = $evaluator->evaluate($request);
    $second = $evaluator->evaluate($request);

    expect($first->trace)->toBe(['identity:role:editor allows posts.publish'])
        ->and($second->trace)->toBe(['identity:role:editor allows posts.publish']);
});
