<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Events;

use DynamikDev\PolicyEngine\DTOs\ImportResult;

readonly class DocumentImported
{
    public function __construct(
        public readonly ImportResult $result,
    ) {}
}
