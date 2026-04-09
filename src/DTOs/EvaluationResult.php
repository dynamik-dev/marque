<?php

declare(strict_types=1);

namespace DynamikDev\Marque\DTOs;

use DynamikDev\Marque\Enums\Decision;

readonly class EvaluationResult
{
    /**
     * @param  array<int, PolicyStatement>  $matchedStatements
     * @param  array<int, string>  $trace
     */
    public function __construct(
        public Decision $decision,
        public string $decidedBy,
        public array $matchedStatements = [],
        public array $trace = [],
    ) {}
}
