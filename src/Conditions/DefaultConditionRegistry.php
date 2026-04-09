<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Conditions;

use DynamikDev\Marque\Contracts\ConditionEvaluator;
use DynamikDev\Marque\Contracts\ConditionRegistry;
use InvalidArgumentException;

class DefaultConditionRegistry implements ConditionRegistry
{
    /** @var array<string, string> */
    private array $map = [];

    public function register(string $type, string $evaluatorClass): void
    {
        $this->map[$type] = $evaluatorClass;
    }

    public function evaluatorFor(string $type): ConditionEvaluator
    {
        if (! isset($this->map[$type])) {
            throw new InvalidArgumentException("No condition evaluator registered for type [{$type}].");
        }

        return app($this->map[$type]);
    }
}
