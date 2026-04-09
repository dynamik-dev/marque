<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Events;

use DynamikDev\Marque\DTOs\ImportResult;

readonly class DocumentImported
{
    public function __construct(
        public readonly ImportResult $result,
    ) {}
}
