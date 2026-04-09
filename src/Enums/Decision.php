<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Enums;

enum Decision: string
{
    case Allow = 'Allow';
    case Deny = 'Deny';
}
