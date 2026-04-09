<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

use DynamikDev\PolicyEngine\DTOs\Condition;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;

interface ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool;
}
