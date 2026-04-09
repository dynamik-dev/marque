<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ScopeType
{
    public function __construct(
        public readonly string $type,
    ) {}
}
