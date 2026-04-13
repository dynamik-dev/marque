<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Support\RoleBuilder;
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

it('auto-registers literal permissions when passed to grant through the facade', function (): void {
    Marque::createRole('editor', 'Editor')->grant(['posts.create', 'posts.read']);

    expect($this->permissionStore->exists('posts.create'))->toBeTrue()
        ->and($this->permissionStore->exists('posts.read'))->toBeTrue();
});

it('does not register wildcard patterns passed to grant', function (): void {
    Marque::createRole('admin', 'Admin')->grant(['posts.*', '*.*']);

    expect($this->permissionStore->exists('posts.*'))->toBeFalse()
        ->and($this->permissionStore->exists('*.*'))->toBeFalse();
});

it('strips the deny prefix and registers the base permission', function (): void {
    Marque::createRole('moderator', 'Moderator')->deny(['posts.delete']);

    expect($this->permissionStore->exists('posts.delete'))->toBeTrue();
});

it('does not re-register permissions that are already present', function (): void {
    $this->permissionStore->register(['posts.create']);

    Marque::createRole('author', 'Author')->grant(['posts.create', 'posts.read']);

    $all = $this->permissionStore->all()->pluck('id')->all();

    expect($all)->toContain('posts.create', 'posts.read');
    expect(array_count_values($all)['posts.create'] ?? 0)->toBe(1);
});

it('does not auto-register when the PermissionStore is not provided', function (): void {
    $builder = new RoleBuilder(
        roleStore: $this->roleStore,
        roleId: 'standalone',
    );

    $this->roleStore->save('standalone', 'Standalone', []);

    $builder->grant(['posts.create']);

    expect($this->permissionStore->exists('posts.create'))->toBeFalse();
    expect($this->roleStore->permissionsFor('standalone'))->toContain('posts.create');
});
