<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class EvaluationRequest
{
    public function __construct(
        public Principal $principal,
        public string $action,
        public ?Resource $resource = null,
        public Context $context = new Context,
    ) {}
}
