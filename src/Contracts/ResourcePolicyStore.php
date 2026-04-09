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
}
