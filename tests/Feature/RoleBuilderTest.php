<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Support\RoleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
});

it('throws RuntimeException when granting permissions to a non-existent role', function (): void {
    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'non-existent',
    );

    $builder->grant(['posts.create']);
})->throws(RuntimeException::class, 'Role [non-existent] not found.');

it('throws RuntimeException when ungranting permissions from a non-existent role', function (): void {
    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'non-existent',
    );

    $builder->ungrant(['posts.create']);
})->throws(RuntimeException::class, 'Role [non-existent] not found.');
