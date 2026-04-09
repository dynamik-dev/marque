<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\EvaluationResult;

interface Evaluator
{
    public function evaluate(EvaluationRequest $request): EvaluationResult;
}
