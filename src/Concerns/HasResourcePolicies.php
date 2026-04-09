<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Concerns;

use DynamikDev\PolicyEngine\DTOs\Resource;

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
}
