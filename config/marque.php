<?php

declare(strict_types=1);

use DynamikDev\Marque\Resolvers\BoundaryPolicyResolver;
use DynamikDev\Marque\Resolvers\IdentityPolicyResolver;
use DynamikDev\Marque\Resolvers\ResourcePolicyResolver;
use DynamikDev\Marque\Resolvers\SanctumPolicyResolver;

return [
    'cache' => [
        'enabled' => true,
        'store' => env('MARQUE_CACHE_STORE', 'default'),
        'ttl' => 60 * 5,
    ],
    'protect_system_roles' => true,
    'log_denials' => true,
    'trace' => env('MARQUE_TRACE', false),
    'deny_unbounded_scopes' => false,
    'enforce_boundaries_on_global' => false,
    'table_prefix' => '',

    /*
    |--------------------------------------------------------------------------
    | Seeder Class
    |--------------------------------------------------------------------------
    |
    | Fully qualified seeder class name re-run by `marque:sync`. Must include
    | the namespace (e.g. \Database\Seeders\PermissionSeeder), since
    | `db:seed --class` expects a resolvable class. Leave null to disable
    | the sync command; calling it without configuring this value will fail
    | with an actionable error rather than a class-not-found exception.
    |
    */
    'seeder_class' => null,

    /*
    |--------------------------------------------------------------------------
    | Document Path
    |--------------------------------------------------------------------------
    |
    | Absolute directory used as the boundary for policy document import/export
    | filesystem operations. Must be set before calling Marque::import() or
    | Marque::exportToFile() with a path argument; otherwise PathValidator
    | fails closed with a RuntimeException to prevent reads/writes outside the
    | intended directory.
    |
    | Example: storage_path('app/marque')
    |
    */
    'document_path' => null,
    'gate_passthrough' => [],
    'import_subject_types' => [],

    'resolvers' => [
        IdentityPolicyResolver::class,
        BoundaryPolicyResolver::class,
        ResourcePolicyResolver::class,
        SanctumPolicyResolver::class,
    ],
];
