<?php

declare(strict_types=1);

return [
    'cache' => [
        'enabled' => true,
        'store' => env('POLICY_ENGINE_CACHE_STORE', 'default'),
        'ttl' => 60 * 60,
    ],
    'protect_system_roles' => true,
    'log_denials' => true,
    'explain' => env('POLICY_ENGINE_EXPLAIN', false),
    'deny_unbounded_scopes' => false,
    'document_path' => null,
    'document_format' => 'json',
    'gate_passthrough' => [],
];
