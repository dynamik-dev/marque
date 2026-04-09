<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class Principal
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $type,
        public string|int $id,
        public array $attributes = [],
    ) {}
}
