<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Listeners;

use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Events\PermissionDeleted;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;

class InvalidatePermissionCache
{
    public function __construct(
        private readonly CacheManager $cache,
    ) {}

    public function handle(AssignmentCreated|AssignmentRevoked|RoleUpdated|RoleDeleted|PermissionDeleted $event): void
    {
        if (! config('policy-engine.cache.enabled')) {
            return;
        }

        $store = $this->cacheStore();

        // For assignment events, we know the exact subject and can forget their specific key.
        // However, scoped keys are unpredictable, so we flush the whole store.
        // For role/permission changes, any number of subjects may be affected — flush all.
        $store->flush();
    }

    private function cacheStore(): Repository
    {
        $storeName = config('policy-engine.cache.store', 'default');

        return $this->cache->store($storeName === 'default' ? null : $storeName);
    }
}
