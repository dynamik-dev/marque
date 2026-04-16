<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Exceptions;

class PermissionNotFoundException extends MarqueException
{
    public static function forId(string $id): self
    {
        return new self("Permission [{$id}] not found.");
    }
}
