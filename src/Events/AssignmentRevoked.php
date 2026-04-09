<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

use DynamikDev\Marque\Models\Assignment;

readonly class AssignmentRevoked
{
    public function __construct(
        public readonly Assignment $assignment,
    ) {}
}
