<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\EvaluationRequest;

interface ConditionEvaluator
{
    public function passes(Condition $condition, EvaluationRequest $request): bool;
}
