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

        $cacheKey = self::key('can', $subjectType, $subjectId, $permission);
        $store = CacheStoreResolver::forSubject($this->cache, $subjectType, $subjectId);

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
        if (! config('policy-engine.cache.enabled')) {
            return $this->inner->effectivePermissions($subjectType, $subjectId, $scope);
        }

        $cacheKey = self::key('effective', $subjectType, $subjectId, $scope);
        $store = CacheStoreResolver::forSubject($this->cache, $subjectType, $subjectId);

        /** @var array<int, string>|null $result */
        $result = $store->get($cacheKey);

        if ($result !== null) {
            return $result;
        }

        $result = $this->inner->effectivePermissions($subjectType, $subjectId, $scope);
        $store->put($cacheKey, $result, $this->ttl());

        return $result;
    }

    public function hasRole(string $subjectType, string|int $subjectId, string $role, ?string $scope = null): bool
    {
        if (! config('policy-engine.cache.enabled')) {
            return $this->inner->hasRole($subjectType, $subjectId, $role, $scope);
        }

        $cacheKey = self::key('role', $subjectType, $subjectId, $role.($scope !== null ? ":{$scope}" : ''));
        $store = CacheStoreResolver::forSubject($this->cache, $subjectType, $subjectId);

        $result = $store->get($cacheKey);

        if ($result !== null) {
            return (bool) $result;
        }

        $result = $this->inner->hasRole($subjectType, $subjectId, $role, $scope);
        $store->put($cacheKey, $result, $this->ttl());

        return $result;
    }

    /**
     * Build a namespaced cache key.
     *
     * Format: policy-engine:{type}:{subjectType}:{subjectId}:{suffix}
     * The type prefix (can, role, effective) prevents collisions between
     * different cache entry kinds.
     */
    public static function key(string $type, string $subjectType, string|int $subjectId, ?string $suffix = null): string
    {
        $key = "policy-engine:{$type}:{$subjectType}:{$subjectId}";

        if ($suffix !== null) {
            $key .= ":{$suffix}";
        }

        return $key;
    }

    /**
     * @deprecated Use key() instead. Kept for backward compatibility.
     */
    public static function cacheKey(string $subjectType, string|int $subjectId, ?string $permission = null): string
    {
        return self::key('can', $subjectType, $subjectId, $permission);
    }

    private function ttl(): int
    {
        /** @var int $ttl */
        $ttl = config('policy-engine.cache.ttl', 3600);

        return $ttl;
    }
}
