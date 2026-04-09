<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

interface ConditionRegistry
{
    public function register(string $type, string $evaluatorClass): void;

    public function evaluatorFor(string $type): ConditionEvaluator;
}
