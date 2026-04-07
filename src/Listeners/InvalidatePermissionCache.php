<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Listeners;

use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Events\BoundaryRemoved;
use DynamikDev\PolicyEngine\Events\BoundarySet;
use DynamikDev\PolicyEngine\Events\PermissionDeleted;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;

class InvalidatePermissionCache
{
    public function __construct(
        private readonly CacheManager $cache,
    ) {}

    public function handle(AssignmentCreated|AssignmentRevoked|RoleUpdated|RoleDeleted|PermissionDeleted|BoundarySet|BoundaryRemoved $event): void
    {
        if (! config('policy-engine.cache.enabled')) {
            return;
        }

        /** @var string $storeName */
        $storeName = config('policy-engine.cache.store', 'default');

        $store = $this->cache->store($storeName === 'default' ? null : $storeName);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(['policy-engine'])->flush();

            return;
        }

        // For stores without tag support, we cannot selectively clear policy-engine keys.
        // Users should configure a dedicated cache store via policy-engine.cache.store
        // to avoid flushing unrelated cache entries.
        $store->clear();
    }
}
