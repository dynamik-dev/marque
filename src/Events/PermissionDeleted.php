<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

class PermissionDeleted
{
    public function __construct(
        public readonly string $permission,
    ) {}
}
