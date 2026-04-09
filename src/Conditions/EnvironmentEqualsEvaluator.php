<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Conditions;

use DynamikDev\PolicyEngine\Contracts\ConditionEvaluator;
use DynamikDev\PolicyEngine\DTOs\Condition;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;

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
