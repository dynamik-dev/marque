<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Resolvers\BoundaryPolicyResolver;
use DynamikDev\PolicyEngine\Resolvers\IdentityPolicyResolver;
use DynamikDev\PolicyEngine\Resolvers\ResourcePolicyResolver;

return [
    'cache' => [
        'enabled' => true,
        'store' => env('POLICY_ENGINE_CACHE_STORE', 'default'),
        'ttl' => 60 * 5,
    ],
    'protect_system_roles' => true,
    'log_denials' => true,
    'trace' => env('POLICY_ENGINE_TRACE', false),
    'deny_unbounded_scopes' => false,
    'enforce_boundaries_on_global' => false,
    'table_prefix' => '',
    'seeder_class' => 'PermissionSeeder',
    'document_path' => null,
    'gate_passthrough' => [],
    'import_subject_types' => [],

    'resolvers' => [
        IdentityPolicyResolver::class,
        BoundaryPolicyResolver::class,
        ResourcePolicyResolver::class,
    ],
];
