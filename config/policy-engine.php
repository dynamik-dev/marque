<?php

declare(strict_types=1);

return [
    'cache' => [
        'enabled' => true,

        // WARNING: If your cache driver does not support tags (file, database),
        // policy-engine uses generation counters for invalidation. Entries expire
        // naturally via TTL rather than being deleted immediately. For instant
        // invalidation, use a tagged driver (Redis, Memcached).
        'store' => env('POLICY_ENGINE_CACHE_STORE', 'default'),

        // TTL in seconds.
        // Lower values reduce the window for stale cached permissions after revocation.
        'ttl' => 60 * 5,
    ],
    'protect_system_roles' => true,
    'log_denials' => true,
    'explain' => env('POLICY_ENGINE_EXPLAIN', false),
    'deny_unbounded_scopes' => false,

    // When true, boundary checks are applied even for unscoped (global)
    // evaluations. By default, global assignments are inherently unbounded —
    // boundaries only restrict scoped checks. Enable this if you need to
    // enforce boundary ceilings on global permission checks. Be aware that
    // this requires boundaries to exist for every scope the subject might
    // access, which may cause unexpected denials.
    'enforce_boundaries_on_global' => false,

    // Prefix prepended to all policy-engine table names. Useful when another
    // package (e.g. Spatie Permission, Bouncer) already uses generic names
    // like "permissions" or "roles". Set to 'pe_' for new installs alongside
    // other permission packages. Empty string preserves backwards compatibility.
    'table_prefix' => '',

    // The seeder class used by the policy-engine:sync command.
    // Must be resolvable by db:seed --class (typically in Database\Seeders namespace).
    'seeder_class' => 'PermissionSeeder',

    'document_path' => null,
    'gate_passthrough' => [],
    'import_subject_types' => [],
];
