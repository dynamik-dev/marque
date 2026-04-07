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
use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;

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

        CacheStoreResolver::flush($this->cache);
    }
}
