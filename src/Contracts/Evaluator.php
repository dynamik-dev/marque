<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;

interface Evaluator
{
    public function evaluate(EvaluationRequest $request): EvaluationResult;
}
