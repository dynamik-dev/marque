<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Concerns;

use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\DTOs\Resource;

trait HasResourcePolicies
{
    public function toPolicyResource(): Resource
    {
        return new Resource(
            type: $this->getMorphClass(),
            id: $this->getKey(),
            attributes: $this->resourceAttributes(),
        );
    }

    /** @return array<string, mixed> */
    protected function resourceAttributes(): array
    {
        return $this->only($this->getFillable());
    }

    public function attachPolicy(PolicyStatement $statement): void
    {
        app(ResourcePolicyStore::class)->attach(
            $this->getMorphClass(),
            $this->getKey(),
            $statement,
        );
    }

    public function detachPolicy(string $action): void
    {
        app(ResourcePolicyStore::class)->detach(
            $this->getMorphClass(),
            $this->getKey(),
            $action,
        );
    }
}
