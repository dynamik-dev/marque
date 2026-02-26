<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

class AuthorizationDenied
{
    public function __construct(
        public readonly string $subject,
        public readonly string $permission,
        public readonly ?string $scope,
    ) {}
}
