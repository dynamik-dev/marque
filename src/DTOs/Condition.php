<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class Condition
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $type,
        public array $parameters,
    ) {}
}
