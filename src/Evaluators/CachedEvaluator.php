<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Evaluators;

use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;

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

        $principal = $request->principal;
        $generation = CacheStoreResolver::subjectGeneration($this->cache, $principal->type, (string) $principal->id);
        $globalGeneration = CacheStoreResolver::globalGeneration($this->cache);
        $store = CacheStoreResolver::forSubject($this->cache, $principal->type, (string) $principal->id);
        $cacheKey = self::key($request, $generation, $globalGeneration);

        $cached = $store->get($cacheKey);

        if (is_array($cached) && is_string($cached['d'] ?? null) && is_string($cached['b'] ?? null)) {
            return new EvaluationResult(
                decision: Decision::from($cached['d']),
                decidedBy: $cached['b'],
            );
        }

        $result = $this->inner->evaluate($request);

        $store->put($cacheKey, ['d' => $result->decision->value, 'b' => $result->decidedBy], $this->ttl());

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
        $key = "marque:eval:{$principal->type}:{$principal->id}";

        if ($globalGeneration > 0 || $generation > 0) {
            $key .= ":g{$globalGeneration}.{$generation}";
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
        /** @var int $ttl */
        $ttl = config('marque.cache.ttl', 300);

        return $ttl;
    }
}
