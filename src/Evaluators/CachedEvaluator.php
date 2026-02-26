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

        [$requiredPermission, $scope] = $this->parseScope($permission);

        $cacheKey = $this->cacheKey($subjectType, $subjectId, $scope);
        $store = $this->cacheStore();

        /** @var array<int, string>|null $effectivePermissions */
        $effectivePermissions = $store->get($cacheKey);

        if ($effectivePermissions === null) {
            $effectivePermissions = $this->inner->effectivePermissions($subjectType, $subjectId, $scope);
            $store->put($cacheKey, $effectivePermissions, $this->ttl());
        }

        foreach ($effectivePermissions as $perm) {
            if ($perm === $requiredPermission) {
                return true;
            }
        }

        return false;
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

    /**
     * Build the cache key for a subject, optionally scoped.
     */
    public static function cacheKey(string $subjectType, string|int $subjectId, ?string $scope = null): string
    {
        $key = "policy-engine:{$subjectType}:{$subjectId}";

        if ($scope !== null) {
            $key .= ":{$scope}";
        }

        return $key;
    }

    /**
     * Parse an optional scope suffix from a permission string.
     *
     * @return array{0: string, 1: ?string}
     */
    private function parseScope(string $permission): array
    {
        $colonPos = strpos($permission, ':');

        if ($colonPos === false) {
            return [$permission, null];
        }

        return [
            substr($permission, 0, $colonPos),
            substr($permission, $colonPos + 1),
        ];
    }

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $storeName = config('policy-engine.cache.store', 'default');

        return $this->cache->store($storeName === 'default' ? null : $storeName);
    }

    private function ttl(): int
    {
        return (int) config('policy-engine.cache.ttl', 3600);
    }
}
