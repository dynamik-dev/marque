<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Events\PermissionCreated;
use DynamikDev\PolicyEngine\Events\PermissionDeleted;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Models\RolePermission;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = new EloquentPermissionStore;
});

// --- register ---

it('creates a single permission', function (): void {
    $this->store->register('posts.create');

    expect(Permission::query()->where('id', 'posts.create')->exists())->toBeTrue();
});

it('creates multiple permissions from an array', function (): void {
    $this->store->register(['posts.create', 'posts.delete', 'posts.update']);

    expect(Permission::query()->count())->toBe(3);
});

it('is idempotent when registering the same permission twice', function (): void {
    $this->store->register('posts.create');
    $this->store->register('posts.create');

    expect(Permission::query()->where('id', 'posts.create')->count())->toBe(1);
});

it('dispatches PermissionCreated event for new permissions', function (): void {
    Event::fake([PermissionCreated::class]);

    $this->store->register(['posts.create', 'posts.delete']);

    Event::assertDispatched(PermissionCreated::class, function (PermissionCreated $event): bool {
        return $event->permissionId === 'posts.create';
    });
    Event::assertDispatched(PermissionCreated::class, function (PermissionCreated $event): bool {
        return $event->permissionId === 'posts.delete';
    });
    Event::assertDispatched(PermissionCreated::class, 2);
});

it('does not dispatch PermissionCreated event for already existing permissions', function (): void {
    $this->store->register('posts.create');

    Event::fake([PermissionCreated::class]);

    $this->store->register('posts.create');

    Event::assertNotDispatched(PermissionCreated::class);
});

// --- remove ---

it('deletes a permission', function (): void {
    $this->store->register('posts.create');

    $this->store->remove('posts.create');

    expect(Permission::query()->where('id', 'posts.create')->exists())->toBeFalse();
});

it('also deletes related role_permissions entries', function (): void {
    Role::query()->create(['id' => 'editor', 'name' => 'Editor']);
    $this->store->register('posts.create');
    RolePermission::query()->create(['role_id' => 'editor', 'permission_id' => 'posts.create']);

    $this->store->remove('posts.create');

    expect(RolePermission::query()->where('permission_id', 'posts.create')->exists())->toBeFalse();
});

it('dispatches PermissionDeleted event', function (): void {
    $this->store->register('posts.create');

    Event::fake([PermissionDeleted::class]);

    $this->store->remove('posts.create');

    Event::assertDispatched(PermissionDeleted::class, function (PermissionDeleted $event): bool {
        return $event->permissionId === 'posts.create';
    });
});

// --- all ---

it('returns all permissions', function (): void {
    $this->store->register(['posts.create', 'posts.delete', 'comments.create']);

    $all = $this->store->all();

    expect($all)->toHaveCount(3);
});

it('filters permissions by prefix', function (): void {
    $this->store->register(['posts.create', 'posts.delete', 'comments.create']);

    $filtered = $this->store->all('posts');

    expect($filtered)->toHaveCount(2)
        ->and($filtered->pluck('id')->all())->each->toStartWith('posts.');
});

it('returns empty collection when prefix matches nothing', function (): void {
    $this->store->register(['posts.create', 'posts.delete']);

    $filtered = $this->store->all('comments');

    expect($filtered)->toBeEmpty();
});

// --- exists ---

it('returns true for existing permission', function (): void {
    $this->store->register('posts.create');

    expect($this->store->exists('posts.create'))->toBeTrue();
});

it('returns false for non-existing permission', function (): void {
    expect($this->store->exists('posts.create'))->toBeFalse();
});
