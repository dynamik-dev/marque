<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Exceptions;

class ScopeUnresolvableException extends MarqueException
{
    public static function forNullScope(): self
    {
        return new self('Boundary requires a non-null scope.');
    }
}
