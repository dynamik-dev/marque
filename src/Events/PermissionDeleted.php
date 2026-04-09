<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

readonly class PermissionDeleted
{
    public function __construct(
        public readonly string $permission,
    ) {}
}
