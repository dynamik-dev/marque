<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

class BoundaryRemoved
{
    public function __construct(
        public readonly string $scope,
    ) {}
}
