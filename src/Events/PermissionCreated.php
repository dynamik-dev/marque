<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

readonly class PermissionCreated
{
    public function __construct(
        public readonly string $permission,
    ) {}
}
