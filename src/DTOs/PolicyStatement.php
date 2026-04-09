<?php

declare(strict_types=1);

namespace DynamikDev\Marque\DTOs;

use DynamikDev\Marque\Enums\Effect;

readonly class PolicyStatement
{
    /**
     * @param  array<int, Condition>  $conditions
     */
    public function __construct(
        public Effect $effect,
        public string $action,
        public ?string $principalPattern = null,
        public ?string $resourcePattern = null,
        public array $conditions = [],
        public string $source = '',
    ) {}
}
