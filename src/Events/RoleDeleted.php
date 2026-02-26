<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

class RoleDeleted
{
    public function __construct(
        public readonly string $roleId,
    ) {}
}
