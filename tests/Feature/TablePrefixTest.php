<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Boundary;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Models\RolePermission;

it('models use default table names when prefix is empty', function (): void {
    config()->set('policy-engine.table_prefix', '');

    expect((new Permission)->getTable())->toBe('permissions')
        ->and((new Role)->getTable())->toBe('roles')
        ->and((new Assignment)->getTable())->toBe('assignments')
        ->and((new Boundary)->getTable())->toBe('boundaries')
        ->and((new RolePermission)->getTable())->toBe('role_permissions');
});

it('models use prefixed table names when prefix is set', function (): void {
    config()->set('policy-engine.table_prefix', 'pe_');

    expect((new Permission)->getTable())->toBe('pe_permissions')
        ->and((new Role)->getTable())->toBe('pe_roles')
        ->and((new Assignment)->getTable())->toBe('pe_assignments')
        ->and((new Boundary)->getTable())->toBe('pe_boundaries')
        ->and((new RolePermission)->getTable())->toBe('pe_role_permissions');
});

it('role permissions relationship uses prefixed pivot table', function (): void {
    config()->set('policy-engine.table_prefix', 'pe_');

    $relation = (new Role)->permissions();

    expect($relation->getTable())->toBe('pe_role_permissions');
});
