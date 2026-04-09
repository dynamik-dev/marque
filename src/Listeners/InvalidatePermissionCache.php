<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Listeners;

use DynamikDev\Marque\Events\AssignmentCreated;
use DynamikDev\Marque\Events\AssignmentRevoked;
use DynamikDev\Marque\Events\BoundaryRemoved;
use DynamikDev\Marque\Events\BoundarySet;
use DynamikDev\Marque\Events\DocumentImported;
use DynamikDev\Marque\Events\PermissionCreated;
use DynamikDev\Marque\Events\PermissionDeleted;
use DynamikDev\Marque\Events\RoleDeleted;
use DynamikDev\Marque\Events\RoleUpdated;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;

class InvalidatePermissionCache
{
    public function __construct(
        private readonly CacheManager $cache,
    ) {}

    public function handle(AssignmentCreated|AssignmentRevoked|RoleUpdated|RoleDeleted|PermissionCreated|PermissionDeleted|BoundarySet|BoundaryRemoved|DocumentImported $event): void
    {
        if (! config('marque.cache.enabled')) {
            return;
        }

        /*
         * Assignment events affect a single subject — invalidate only their cache.
         * This is the high-frequency path (users joining/leaving groups).
         */
        if ($event instanceof AssignmentCreated || $event instanceof AssignmentRevoked) {
            CacheStoreResolver::flushSubject(
                $this->cache,
                $event->assignment->subject_type,
                $event->assignment->subject_id,
            );

            return;
        }

        /*
         * Role, permission, and boundary changes are rare admin operations
         * that can affect any subject. Full flush is acceptable here.
         */
        CacheStoreResolver::flush($this->cache);
    }
}
