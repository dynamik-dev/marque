<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
});

// --- PermissionStore::find ---

it('finds a permission by id through the store', function (): void {
    $this->permissionStore->register('posts.update');

    $permission = $this->permissionStore->find('posts.update');

    expect($permission)
        ->toBeInstanceOf(Permission::class)
        ->and($permission->id)->toBe('posts.update');
});

it('returns null from store find when permission does not exist', function (): void {
    expect($this->permissionStore->find('nonexistent'))->toBeNull();
});

// --- Marque::getPermission ---

it('retrieves a permission through the facade', function (): void {
    Marque::permissions(['posts.update']);

    $permission = Marque::getPermission('posts.update');

    expect($permission)
        ->toBeInstanceOf(Permission::class)
        ->and($permission->id)->toBe('posts.update');
});

it('returns null from facade when permission does not exist', function (): void {
    expect(Marque::getPermission('nonexistent'))->toBeNull();
});
