<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Enums;

enum Effect: string
{
    case Allow = 'Allow';
    case Deny = 'Deny';
}
