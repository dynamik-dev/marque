<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Exceptions;

class BoundaryNotFoundException extends MarqueException
{
    public static function forScope(string $scope): self
    {
        return new self("Boundary for scope [{$scope}] not found.");
    }
}
