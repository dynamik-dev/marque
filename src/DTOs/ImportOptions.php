<?php

declare(strict_types=1);

namespace DynamikDev\Marque\DTOs;

readonly class ImportOptions
{
    public function __construct(
        public bool $validate = true,
        public bool $merge = true,
        public bool $dryRun = false,
        public bool $skipAssignments = false,
    ) {}
}
