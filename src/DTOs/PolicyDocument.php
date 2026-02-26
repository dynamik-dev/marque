<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class PolicyDocument
{
    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, array{id: string, name: string, permissions: array<int, string>, system?: bool}>  $roles
     * @param  array<int, array{subject: string, role: string, scope?: string}>  $assignments
     * @param  array<int, array{scope: string, max_permissions: array<int, string>}>  $boundaries
     */
    public function __construct(
        public string $version = '1.0',
        public array $permissions = [],
        public array $roles = [],
        public array $assignments = [],
        public array $boundaries = [],
    ) {}
}
