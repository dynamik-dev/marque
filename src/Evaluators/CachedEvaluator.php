<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Evaluators;

use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;

class CachedEvaluator implements Evaluator
{
    public function __construct(
        private readonly Evaluator $inner,
        private readonly CacheManager $cache,
    ) {}

    public function can(string $subjectType, string|int $subjectId, string $permission): bool
    {
        if (! config('policy-engine.cache.enabled')) {
            return $this->inner->can($subjectType, $subjectId, $permission);
        }

        $cacheKey = self::cacheKey($subjectType, $subjectId, $permission);
        $store = CacheStoreResolver::resolve($this->cache);

        // Race window (TOCTOU): Between the cache miss and the put() below,
        // another request may revoke a role and clear the cache. This request
        // would then write a stale "allowed" result that persists for the full
        // TTL. This is inherent to cache-aside patterns. Mitigations:
        // - Use a shorter TTL for security-critical applications
        // - Use a dedicated cache store to isolate invalidation
        // - See "Customizing the Cache" docs for advanced strategies
        $result = $store->get($cacheKey);

        if ($result !== null) {
            return (bool) $result;
        }

        $result = $this->inner->can($subjectType, $subjectId, $permission);
        $store->put($cacheKey, $result, $this->ttl());

        return $result;
    }

    public function explain(string $subjectType, string|int $subjectId, string $permission): EvaluationTrace
    {
        return $this->inner->explain($subjectType, $subjectId, $permission);
    }

    /**
     * @return array<int, string>
     */
    public function effectivePermissions(string $subjectType, string|int $subjectId, ?string $scope = null): array
    {
        return $this->inner->effectivePermissions($subjectType, $subjectId, $scope);
    }

    public static function cacheKey(string $subjectType, string|int $subjectId, ?string $permission = null): string
    {
        $key = "policy-engine:{$subjectType}:{$subjectId}";

        if ($permission !== null) {
            $key .= ":{$permission}";
        }

        return $key;
    }

    private function ttl(): int
    {
        /** @var int $ttl */
        $ttl = config('policy-engine.cache.ttl', 3600);

        return $ttl;
    }
}
