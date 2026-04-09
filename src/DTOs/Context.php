<?php

declare(strict_types=1);

namespace DynamikDev\Marque\DTOs;

readonly class Context
{
    /**
     * @param  array<string, mixed>  $environment
     */
    public function __construct(
        public ?string $scope = null,
        public array $environment = [],
    ) {}
}
