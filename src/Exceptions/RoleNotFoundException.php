<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Exceptions;

class RoleNotFoundException extends MarqueException
{
    public static function forId(string $id): self
    {
        return new self("Role [{$id}] not found.");
    }
}
