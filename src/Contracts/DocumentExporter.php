<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

use DynamikDev\PolicyEngine\DTOs\PolicyDocument;

interface DocumentExporter
{
    /**
     * Export the current authorization configuration as a PolicyDocument.
     *
     * When a scope is provided, only configuration relevant to that scope is exported.
     */
    public function export(?string $scope = null): PolicyDocument;
}
