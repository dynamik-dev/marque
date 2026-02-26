<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

use DynamikDev\PolicyEngine\Models\Role;

class RoleUpdated
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public readonly Role $role,
        public readonly array $changes,
    ) {}
}
