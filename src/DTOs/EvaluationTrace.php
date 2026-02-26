<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class EvaluationTrace
{
    /**
     * @param  array<int, array{role: string, scope: ?string, permissions_checked: array<int, string>}>  $assignments
     */
    public function __construct(
        public string $subject,
        public string $required,
        public string $result,
        public array $assignments,
        public ?string $boundary,
        public bool $cacheHit,
    ) {}
}
