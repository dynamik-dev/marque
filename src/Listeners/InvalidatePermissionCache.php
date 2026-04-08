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

        // Assignment events affect a single subject — invalidate only their cache.
        // This is the high-frequency path (users joining/leaving groups).
        if ($event instanceof AssignmentCreated || $event instanceof AssignmentRevoked) {
            CacheStoreResolver::flushSubject(
                $this->cache,
                $event->assignment->subject_type,
                $event->assignment->subject_id,
            );

            return;
        }

        // Role, permission, and boundary changes are rare admin operations
        // that can affect any subject. Full flush is acceptable here.
        CacheStoreResolver::flush($this->cache);
    }
}
