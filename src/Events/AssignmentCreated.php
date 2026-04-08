<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

use DynamikDev\PolicyEngine\Models\Assignment;

readonly class AssignmentCreated
{
    public function __construct(
        public readonly Assignment $assignment,
    ) {}
}
