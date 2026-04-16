<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Conditions;

use DynamikDev\Marque\Contracts\ConditionEvaluator;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\EvaluationRequest;

class AttributeEqualsEvaluator implements ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool
    {
        if ($request->resource === null) {
            return false;
        }

        $subjectKey = $condition->parameters['subject_key'] ?? null;
        $resourceKey = $condition->parameters['resource_key'] ?? null;

        if (! is_string($subjectKey) || ! is_string($resourceKey)) {
            return false;
        }

        if (! array_key_exists($subjectKey, $request->principal->attributes)) {
            return false;
        }

        if (! array_key_exists($resourceKey, $request->resource->attributes)) {
            return false;
        }

        $subjectValue = $request->principal->attributes[$subjectKey];
        $resourceValue = $request->resource->attributes[$resourceKey];

        // Equality semantics: stringified comparison.
        //
        // The most common use case (resource ownership: subject `user_id` vs
        // resource `owner_id`) silently breaks under type drift across DB
        // drivers, Eloquent casts, and JSON-decoded payloads -- e.g. int 5
        // from an Eloquent cast vs string "5" loaded raw. Strict `===` would
        // deny the owner; loose `==` invites the usual PHP coercion footguns
        // (0 == "abc" being true historically, etc.).
        //
        // Casting both sides to string matches how IDs typically appear over
        // the wire, sidesteps loose-equality surprises, and gives a single
        // predictable rule. Null on either side is treated as a non-match
        // (an absent value is not equal to anything, including another null).
        if ($subjectValue === null || $resourceValue === null) {
            return false;
        }

        // Non-scalar values (arrays, objects) cannot be safely stringified
        // for equality; reject them rather than relying on coercion.
        if (! is_scalar($subjectValue) || ! is_scalar($resourceValue)) {
            return false;
        }

        return (string) $subjectValue === (string) $resourceValue;
    }
}
