<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Conditions;

use DynamikDev\PolicyEngine\Contracts\ConditionEvaluator;
use DynamikDev\PolicyEngine\Contracts\ConditionRegistry;
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
