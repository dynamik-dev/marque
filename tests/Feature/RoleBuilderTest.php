<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use DynamikDev\PolicyEngine\Support\RoleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
});

it('throws RuntimeException when granting permissions to a non-existent role', function (): void {
    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'non-existent',
    );

    $builder->grant(['posts.create']);
})->throws(\RuntimeException::class, 'Role [non-existent] not found.');

it('throws RuntimeException when ungranting permissions from a non-existent role', function (): void {
    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'non-existent',
    );

    $builder->ungrant(['posts.create']);
})->throws(\RuntimeException::class, 'Role [non-existent] not found.');
