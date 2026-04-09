<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\DTOs\PolicyDocument;
use DynamikDev\Marque\DTOs\ValidationResult;

interface DocumentParser
{
    /**
     * Parse raw content into a PolicyDocument.
     */
    public function parse(string $content): PolicyDocument;

    /**
     * Serialize a PolicyDocument back to its string representation.
     */
    public function serialize(PolicyDocument $document): string;

    /**
     * Validate raw content without fully parsing it.
     */
    public function validate(string $content): ValidationResult;
}
