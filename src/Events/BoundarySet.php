<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

use DynamikDev\Marque\Models\Boundary;

readonly class BoundarySet
{
    public function __construct(
        public readonly Boundary $boundary,
    ) {}
}
