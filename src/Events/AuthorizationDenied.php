<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

readonly class AuthorizationDenied
{
    public function __construct(
        public readonly string $subject,
        public readonly string $permission,
        public readonly ?string $scope,
    ) {}
}
