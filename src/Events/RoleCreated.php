<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

use DynamikDev\Marque\Models\Role;

readonly class RoleCreated
{
    public function __construct(
        public readonly Role $role,
    ) {}
}
