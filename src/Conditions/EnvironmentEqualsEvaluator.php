<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Conditions;

use DynamikDev\Marque\Contracts\ConditionEvaluator;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\EvaluationRequest;

class EnvironmentEqualsEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        $key = $condition->parameters['key'] ?? null;
        $expected = $condition->parameters['value'] ?? null;

        if (! is_string($key)) {
            return false;
        }

        if (! array_key_exists($key, $request->context->environment)) {
            return false;
        }

        return $request->context->environment[$key] === $expected;
    }
}
