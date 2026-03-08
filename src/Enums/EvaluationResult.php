<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Enums;

enum EvaluationResult: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
