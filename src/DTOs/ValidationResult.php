<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class ValidationResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
    ) {}
}
