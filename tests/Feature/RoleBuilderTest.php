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

it('adds deny rules via deny()', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'editor',
    );

    $builder->deny(['posts.delete']);

    expect($this->roleStore->permissionsFor('editor'))
        ->toContain('posts.create', '!posts.delete');
});

it('does not double-prefix permissions already prefixed with !', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'editor',
    );

    $builder->deny(['!posts.delete']);

    $permissions = $this->roleStore->permissionsFor('editor');
    expect($permissions)->toContain('!posts.delete')
        ->not->toContain('!!posts.delete');
});

it('chains deny() with grant()', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete', 'comments.create']);
    $this->roleStore->save('editor', 'Editor', []);

    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'editor',
    );

    $builder
        ->grant(['posts.*', 'comments.*'])
        ->deny(['posts.delete']);

    expect($this->roleStore->permissionsFor('editor'))
        ->toContain('posts.*', 'comments.*', '!posts.delete');
});

it('throws RuntimeException when denying on a non-existent role', function (): void {
    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'non-existent',
    );

    $builder->deny(['posts.create']);
})->throws(RuntimeException::class, 'Role [non-existent] not found.');

it('throws RuntimeException when ungranting permissions from a non-existent role', function (): void {
    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'non-existent',
    );

    $builder->ungrant(['posts.create']);
})->throws(RuntimeException::class, 'Role [non-existent] not found.');
