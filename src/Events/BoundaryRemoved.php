<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

readonly class BoundaryRemoved
{
    public function __construct(
        public readonly string $scope,
    ) {}
}
