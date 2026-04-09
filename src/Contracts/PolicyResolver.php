<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
use Illuminate\Support\Collection;

interface PolicyResolver
{
    /**
     * @return Collection<int, PolicyStatement>
     */
    public function resolve(EvaluationRequest $request): Collection;
}
