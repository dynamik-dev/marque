<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Conditions;

use DynamikDev\PolicyEngine\Contracts\ConditionEvaluator;
use DynamikDev\PolicyEngine\DTOs\Condition;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;

class AttributeEqualsEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        if ($request->resource === null) {
            return false;
        }

        $subjectKey = $condition->parameters['subject_key'] ?? null;
        $resourceKey = $condition->parameters['resource_key'] ?? null;

        if ($subjectKey === null || $resourceKey === null) {
            return false;
        }

        if (! array_key_exists($subjectKey, $request->principal->attributes)) {
            return false;
        }

        if (! array_key_exists($resourceKey, $request->resource->attributes)) {
            return false;
        }

        return $request->principal->attributes[$subjectKey] === $request->resource->attributes[$resourceKey];
    }
}
