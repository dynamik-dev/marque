<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Evaluators;

use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
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
        $store = $this->cacheStore();

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

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        /** @var string $storeName */
        $storeName = config('policy-engine.cache.store', 'default');

        return $this->cache->store($storeName === 'default' ? null : $storeName);
    }

    private function ttl(): int
    {
        /** @var int $ttl */
        $ttl = config('policy-engine.cache.ttl', 3600);

        return $ttl;
    }
}
