<?php

declare(strict_types=1);

namespace DynamikDev\Marque\DTOs;

readonly class ImportResult
{
    /**
     * @param  array<int, string>  $permissionsCreated
     * @param  array<int, string>  $rolesCreated
     * @param  array<int, string>  $rolesUpdated
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public array $permissionsCreated,
        public array $rolesCreated,
        public array $rolesUpdated,
        public int $assignmentsCreated,
        public array $warnings,
    ) {}
}
