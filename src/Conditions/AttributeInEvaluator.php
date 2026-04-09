<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Conditions;

use DynamikDev\PolicyEngine\Contracts\ConditionEvaluator;
use DynamikDev\PolicyEngine\DTOs\Condition;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;

class AttributeInEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        $source = $condition->parameters['source'] ?? null;
        $key = $condition->parameters['key'] ?? null;
        $values = $condition->parameters['values'] ?? null;

        if (! is_string($source) || ! is_string($key) || ! is_array($values)) {
            return false;
        }

        $actual = match ($source) {
            'principal' => $request->principal->attributes[$key] ?? null,
            'resource' => $request->resource?->attributes[$key] ?? null,
            'environment' => $request->context->environment[$key] ?? null,
            default => null,
        };

        if ($actual === null && ! array_key_exists($key, $this->resolveSourceAttributes($source, $request))) {
            return false;
        }

        return in_array($actual, $values, strict: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSourceAttributes(string $source, EvaluationRequest $request): array
    {
        return match ($source) {
            'principal' => $request->principal->attributes,
            'resource' => $request->resource->attributes ?? [],
            'environment' => $request->context->environment,
            default => [],
        };
    }
}
