<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\DTOs;

readonly class PolicyDocument
{
    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, array{id: string, name: string, permissions: array<int, string>, system?: bool}>|array<string, array{permissions: array<int, string>, conditions?: array<string, array<int, array<string, mixed>>>}>  $roles
     * @param  array<int, array{subject: string, role: string, scope?: string}>  $assignments
     * @param  array<int, array{scope: string, max_permissions: array<int, string>}>|array<string, array{max_permissions: array<int, string>}>  $boundaries
     * @param  array<int, array{resource_type: string, resource_id: string|null, effect: string, action: string, principal_pattern: string|null, conditions: array<int, mixed>}>  $resourcePolicies
     */
    public function __construct(
        public string $version = '1.0',
        public array $permissions = [],
        public array $roles = [],
        public array $assignments = [],
        public array $boundaries = [],
        public array $resourcePolicies = [],
    ) {}
}
