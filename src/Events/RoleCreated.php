<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

use DynamikDev\PolicyEngine\Models\Role;

class RoleCreated
{
    public function __construct(
        public readonly Role $role,
    ) {}
}
