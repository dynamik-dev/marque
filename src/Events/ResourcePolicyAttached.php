<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

readonly class ResourcePolicyAttached
{
    public function __construct(
        public readonly string $resourceType,
        public readonly string|int|null $resourceId,
        public readonly string $action,
    ) {}
}
