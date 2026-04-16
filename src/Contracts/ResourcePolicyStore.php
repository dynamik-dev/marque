<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\DTOs\PolicyStatement;
use Illuminate\Support\Collection;

interface ResourcePolicyStore
{
    /**
     * @return Collection<int, PolicyStatement>
     */
    public function forResource(string $type, string|int|null $id): Collection;

    public function attach(string $resourceType, string|int|null $resourceId, PolicyStatement $statement): void;

    public function detach(string $resourceType, string|int|null $resourceId, string $action): void;

    /**
     * Remove a single statement by its primary key.
     *
     * Use this when multiple statements share the same (resource_type, resource_id, action)
     * triple but differ by effect or conditions, and only one of them should be removed.
     */
    public function detachById(string $id): void;
}
