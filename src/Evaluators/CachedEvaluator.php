<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Evaluators;

use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\EvaluationResult;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;

class CachedEvaluator implements Evaluator
{
    public function __construct(
        private readonly Evaluator $inner,
        private readonly CacheManager $cache,
    ) {}

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        if (! config('policy-engine.cache.enabled')) {
            return $this->inner->evaluate($request);
        }

        $principal = $request->principal;
        $generation = CacheStoreResolver::subjectGeneration($this->cache, $principal->type, (string) $principal->id);
        $globalGeneration = CacheStoreResolver::globalGeneration($this->cache);
        $store = CacheStoreResolver::forSubject($this->cache, $principal->type, (string) $principal->id);
        $cacheKey = self::key($request, $generation, $globalGeneration);

        $cached = $store->get($cacheKey);

        if (is_array($cached)) {
            return new EvaluationResult(
                decision: Decision::from($cached['d']),
                decidedBy: $cached['b'],
            );
        }

        $result = $this->inner->evaluate($request);

        $store->put($cacheKey, ['d' => $result->decision->name, 'b' => $result->decidedBy], $this->ttl());

        return $result;
    }

    /**
     * Build a namespaced cache key for an EvaluationRequest.
     *
     * Format: policy-engine:eval:{principalType}:{principalId}[:g{combinedGen}]:{md5hash}
     * The hash encodes the full request identity (principal, action, resource, scope)
     * to prevent collisions across different request shapes sharing the same subject.
     *
     * The generation segment combines global and per-subject generations on
     * non-tagged stores. Global generation is incremented by flush() (role/
     * permission/boundary changes). Subject generation is incremented by
     * flushSubject() (assignment changes). Both must be embedded so either
     * invalidation path renders old keys unreachable.
     */
    public static function key(EvaluationRequest $request, int $generation = 0, int $globalGeneration = 0): string
    {
        $principal = $request->principal;
        $key = "policy-engine:eval:{$principal->type}:{$principal->id}";

        $combinedGeneration = $globalGeneration + $generation;

        if ($combinedGeneration > 0) {
            $key .= ":g{$combinedGeneration}";
        }

        $hash = md5(serialize([
            $principal->type,
            $principal->id,
            $request->action,
            $request->resource?->type,
            $request->resource?->id,
            $request->context->scope,
        ]));

        return "{$key}:{$hash}";
    }

    private function ttl(): int
    {
        return (int) config('policy-engine.cache.ttl', 300);
    }
}
