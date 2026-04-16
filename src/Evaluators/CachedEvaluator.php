<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Evaluators;

use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Log;

/**
 * Cache decorator around an inner Evaluator.
 *
 * # Cache-aside race window
 *
 * Cache-aside is inherently racy. The window manifests like this on
 * non-tagged stores:
 *
 *   T=0  evaluate() captures generation = N
 *   T=1  evaluate() reads underlying state from the inner evaluator
 *        (sees pre-mutation DB rows)
 *   T=2  a concurrent mutation commits and dispatches its event
 *        afterCommit, which bumps the generation counter to N+1
 *   T=3  evaluate() writes its result under the now-stale generation
 *        N key
 *
 * Without mitigation the stale entry lives until the next flush or until
 * the TTL expires, whichever comes first.
 *
 * # Mitigation: post-write generation re-check (CAS)
 *
 * After put(), the writer re-reads both generation counters. If either
 * counter has advanced past the value captured at T=0, the entry under
 * the captured-generation key is now stale by definition: it was
 * computed from state older than the most recent flush. The writer
 * deletes its own entry to keep the cache empty rather than poisoned.
 * The next reader misses the cache, recomputes against committed state,
 * and writes under the new generation.
 *
 * The CAS narrows the window from `TTL` to the round-trip between the
 * inner evaluation and the post-write counter read, which is bounded by
 * the cache driver latency rather than by the TTL.
 *
 * # TTL as a race-window bound
 *
 * config('marque.cache.ttl') defaults to 300 seconds. This is short for
 * an authorization cache because the TTL is the worst-case lifetime of
 * any stale entry the CAS or the generation counter fails to catch
 * (network partitions between writer and counter, tagged stores where
 * generations are inert, cache driver eviction races). The default is
 * intentionally shorter than typical session lifetimes; consumers who
 * tolerate a longer staleness window can raise it knowing the upper
 * bound on permission lag.
 *
 * # Tagged-store note
 *
 * On tagged stores generation counters are inert (always 0), so CAS is
 * a no-op. The race window for tagged stores is bounded by the tag
 * flush latency, which is the responsibility of the cache driver, plus
 * the TTL fallback above.
 */
class CachedEvaluator implements Evaluator
{
    public function __construct(
        private readonly Evaluator $inner,
        private readonly CacheManager $cache,
    ) {}

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        if (! config('marque.cache.enabled')) {
            return $this->inner->evaluate($request);
        }

        // Bypass cache when trace is enabled. Trace data (matchedStatements,
        // trace lines) is variable-size and not currently serialized into the
        // cache value; caching a stripped result would silently drop trace
        // output on cache hits, breaking marque.trace=true.
        if (config('marque.trace') === true) {
            return $this->inner->evaluate($request);
        }

        $principal = $request->principal;
        $generation = CacheStoreResolver::subjectGeneration($this->cache, $principal->type, (string) $principal->id);
        $globalGeneration = CacheStoreResolver::globalGeneration($this->cache);
        $store = CacheStoreResolver::forSubject($this->cache, $principal->type, (string) $principal->id);
        $cacheKey = self::key($request, $generation, $globalGeneration);

        $cached = $store->get($cacheKey);

        if (is_array($cached) && is_string($cached['d'] ?? null) && is_string($cached['b'] ?? null)) {
            $decision = Decision::tryFrom($cached['d']);

            if ($decision instanceof Decision) {
                return new EvaluationResult(
                    decision: $decision,
                    decidedBy: $cached['b'],
                );
            }

            Log::debug('marque: discarded corrupted cached evaluation entry', [
                'key' => $cacheKey,
                'decision_value' => $cached['d'],
            ]);
        }

        $result = $this->inner->evaluate($request);

        $store->put($cacheKey, ['d' => $result->decision->value, 'b' => $result->decidedBy], $this->ttl());

        // CAS re-check: if either generation counter advanced while we were
        // computing and writing, a flush happened against pre-commit state we
        // observed and our just-written entry is stale. Delete it to avoid
        // poisoning the cache for the duration of the TTL.
        $generationAfter = CacheStoreResolver::subjectGeneration($this->cache, $principal->type, (string) $principal->id);
        $globalGenerationAfter = CacheStoreResolver::globalGeneration($this->cache);

        if ($generationAfter !== $generation || $globalGenerationAfter !== $globalGeneration) {
            $store->forget($cacheKey);
        }

        return $result;
    }

    /**
     * Build a namespaced cache key for an EvaluationRequest.
     *
     * Format: marque:eval:{principalType}:{principalId}[:g{global}.{subject}]:{md5hash}
     * The hash encodes the full request identity (principal, action, resource, scope)
     * to prevent collisions across different request shapes sharing the same subject.
     *
     * The generation segment encodes global and per-subject generations as a
     * composite pair on non-tagged stores. Global generation is incremented by
     * flush() (role/permission/boundary changes). Subject generation is
     * incremented by flushSubject() (assignment changes). Both must be embedded
     * so either invalidation path renders old keys unreachable.
     */
    public static function key(EvaluationRequest $request, int $generation = 0, int $globalGeneration = 0): string
    {
        $principal = $request->principal;
        // Normalize principal id to string so int(5) and string("5") produce
        // the same cache key for the same logical principal. Eloquent and
        // raw query paths may return ids as different scalar types depending
        // on the driver, the cast configuration, and PHP's type juggling.
        $principalId = (string) $principal->id;
        $key = "marque:eval:{$principal->type}:{$principalId}";

        if ($globalGeneration > 0 || $generation > 0) {
            $key .= ":g{$globalGeneration}.{$generation}";
        }

        $hash = md5(serialize([
            $principal->type,
            $principalId,
            $request->action,
            $request->resource?->type,
            $request->resource?->id !== null ? (string) $request->resource->id : null,
            $request->context->scope,
        ]));

        return "{$key}:{$hash}";
    }

    private function ttl(): int
    {
        /** @var int $ttl */
        $ttl = config('marque.cache.ttl', 300);

        return $ttl;
    }
}
