<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\ImportResult;
use DynamikDev\Marque\DTOs\PolicyDocument;

interface DocumentImporter
{
    /**
     * Import a PolicyDocument into the system.
     */
    public function import(PolicyDocument $document, ImportOptions $options): ImportResult;
}
