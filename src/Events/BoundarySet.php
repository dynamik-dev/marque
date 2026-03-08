<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

use DynamikDev\PolicyEngine\Models\Boundary;

class BoundarySet
{
    public function __construct(
        public readonly Boundary $boundary,
    ) {}
}
