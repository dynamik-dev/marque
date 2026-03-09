<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Events\RoleCreated;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Models\RolePermission;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

mutates(EloquentRoleStore::class);

beforeEach(function (): void {
    $this->store = new EloquentRoleStore;
});

// --- save ---

it('creates a new role', function (): void {
    $this->store->save('editor', 'Editor', []);

    expect(Role::query()->where('id', 'editor')->exists())->toBeTrue();
});

it('creates a role with permissions', function (): void {
    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.update']);

    $this->store->save('editor', 'Editor', ['posts.create', 'posts.update']);

    expect(RolePermission::query()->where('role_id', 'editor')->count())->toBe(2);
});

it('updates an existing role name', function (): void {
    $this->store->save('editor', 'Editor', []);

    $this->store->save('editor', 'Senior Editor', []);

    expect(Role::query()->find('editor')->name)->toBe('Senior Editor');
});

it('syncs permissions on update', function (): void {
    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.update']);
    Permission::query()->create(['id' => 'posts.delete']);

    $this->store->save('editor', 'Editor', ['posts.create', 'posts.update']);
    $this->store->save('editor', 'Editor', ['posts.update', 'posts.delete']);

    $permissionIds = RolePermission::query()
        ->where('role_id', 'editor')
        ->pluck('permission_id')
        ->sort()
        ->values()
        ->all();

    expect($permissionIds)->toBe(['posts.delete', 'posts.update']);
});

it('dispatches RoleCreated for new roles', function (): void {
    Event::fake([RoleCreated::class]);

    $this->store->save('editor', 'Editor', []);

    Event::assertDispatched(RoleCreated::class, function (RoleCreated $event): bool {
        return $event->role->id === 'editor';
    });
});

it('dispatches RoleUpdated for existing roles', function (): void {
    $this->store->save('editor', 'Editor', []);

    Event::fake([RoleUpdated::class]);

    $this->store->save('editor', 'Senior Editor', []);

    Event::assertDispatched(RoleUpdated::class, function (RoleUpdated $event): bool {
        return $event->role->id === 'editor';
    });
});

// --- remove ---

it('deletes a role', function (): void {
    $this->store->save('editor', 'Editor', []);

    $this->store->remove('editor');

    expect(Role::query()->where('id', 'editor')->exists())->toBeFalse();
});

it('throws RuntimeException when removing a system-protected role', function (): void {
    config()->set('policy-engine.protect_system_roles', true);

    $this->store->save('admin', 'Admin', [], system: true);

    $this->store->remove('admin');
})->throws(RuntimeException::class);

it('allows removing a system role when protection is disabled', function (): void {
    config()->set('policy-engine.protect_system_roles', false);

    $this->store->save('admin', 'Admin', [], system: true);

    $this->store->remove('admin');

    expect(Role::query()->where('id', 'admin')->exists())->toBeFalse();
});

it('dispatches RoleDeleted on removal', function (): void {
    $this->store->save('editor', 'Editor', []);

    Event::fake([RoleDeleted::class]);

    $this->store->remove('editor');

    Event::assertDispatched(RoleDeleted::class, function (RoleDeleted $event): bool {
        return $event->role->id === 'editor';
    });
});

// --- find ---

it('returns a role by id', function (): void {
    $this->store->save('editor', 'Editor', []);

    $role = $this->store->find('editor');

    expect($role)
        ->not->toBeNull()
        ->id->toBe('editor')
        ->name->toBe('Editor');
});

it('returns null for non-existing role', function (): void {
    expect($this->store->find('nonexistent'))->toBeNull();
});

// --- all ---

it('returns all roles', function (): void {
    $this->store->save('editor', 'Editor', []);
    $this->store->save('admin', 'Admin', []);
    $this->store->save('viewer', 'Viewer', []);

    expect($this->store->all())->toHaveCount(3);
});

// --- permissionsFor ---

it('returns permission ids for a role', function (): void {
    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.update']);

    $this->store->save('editor', 'Editor', ['posts.create', 'posts.update']);

    $permissions = $this->store->permissionsFor('editor');

    expect($permissions)->toHaveCount(2)
        ->and($permissions)->toContain('posts.create')
        ->and($permissions)->toContain('posts.update');
});

it('returns empty array when role has no permissions', function (): void {
    $this->store->save('editor', 'Editor', []);

    expect($this->store->permissionsFor('editor'))->toBe([]);
});

it('returns permission ids for multiple roles in one mapping', function (): void {
    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.update']);
    Permission::query()->create(['id' => 'comments.read']);

    $this->store->save('editor', 'Editor', ['posts.create', 'posts.update']);
    $this->store->save('viewer', 'Viewer', ['comments.read']);

    $permissionsByRole = $this->store->permissionsForRoles(['editor', 'viewer', 'missing']);

    expect($permissionsByRole)->toHaveKeys(['editor', 'viewer', 'missing']);
    expect($permissionsByRole['editor'])->toContain('posts.create', 'posts.update');
    expect($permissionsByRole['viewer'])->toContain('comments.read');
    expect($permissionsByRole['missing'])->toBe([]);
});
