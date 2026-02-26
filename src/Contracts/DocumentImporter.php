<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;

interface DocumentImporter
{
    /**
     * Import a PolicyDocument into the system.
     */
    public function import(PolicyDocument $document, ImportOptions $options): ImportResult;
}
