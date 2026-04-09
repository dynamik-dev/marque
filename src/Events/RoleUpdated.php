<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

use DynamikDev\Marque\Models\Role;

readonly class RoleUpdated
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public readonly Role $role,
        public readonly array $changes,
    ) {}
}
