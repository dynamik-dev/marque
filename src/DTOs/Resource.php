<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class Resource
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $type,
        public string|int|null $id = null,
        public array $attributes = [],
    ) {}
}
