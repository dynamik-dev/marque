<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

class AssignmentRevoked
{
    public function __construct(
        public readonly string $subjectType,
        public readonly string|int $subjectId,
        public readonly string $roleId,
        public readonly ?string $scope,
    ) {}
}
