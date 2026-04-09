<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Events\RoleCreated;
use DynamikDev\Marque\Events\RoleDeleted;
use DynamikDev\Marque\Events\RoleUpdated;
use DynamikDev\Marque\Models\Permission;
use DynamikDev\Marque\Models\Role;
use DynamikDev\Marque\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = app(RoleStore::class);
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

// --- save validation ---

it('rejects empty role ID', function (): void {
    $this->store->save('', 'Empty', []);
})->throws(InvalidArgumentException::class, 'Invalid role ID');

it('rejects role ID containing a colon', function (): void {
    $this->store->save('admin:super', 'Admin Super', []);
})->throws(InvalidArgumentException::class, 'Invalid role ID');

it('rejects role ID starting with bang prefix', function (): void {
    $this->store->save('!admin', 'Not Admin', []);
})->throws(InvalidArgumentException::class, 'Invalid role ID');

it('rejects role ID containing whitespace', function (): void {
    $this->store->save('my role', 'My Role', []);
})->throws(InvalidArgumentException::class, 'Invalid role ID');

it('rejects role ID exceeding 255 characters', function (): void {
    $this->store->save(str_repeat('a', 256), 'Too Long', []);
})->throws(InvalidArgumentException::class, 'IDs must not exceed 255 characters');

it('rejects role ID with reserved __dp. prefix', function (): void {
    $this->store->save('__dp.posts.create', 'Direct: posts.create', []);
})->throws(InvalidArgumentException::class, "The '__dp.' prefix is reserved for internal use.");

it('accepts valid role IDs', function (): void {
    $role = $this->store->save('team-editor', 'Team Editor', []);

    expect($role->id)->toBe('team-editor');
});

// --- remove ---

it('deletes a role', function (): void {
    $this->store->save('editor', 'Editor', []);

    $this->store->remove('editor');

    expect(Role::query()->where('id', 'editor')->exists())->toBeFalse();
});

it('throws RuntimeException when removing a system-protected role', function (): void {
    config()->set('marque.protect_system_roles', true);

    $this->store->save('admin', 'Admin', [], system: true);

    $this->store->remove('admin');
})->throws(RuntimeException::class);

it('allows removing a system role when protection is disabled', function (): void {
    config()->set('marque.protect_system_roles', false);

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

it('allows saving a system role with the same permissions in different order', function (): void {
    config()->set('marque.protect_system_roles', true);

    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.update']);
    Permission::query()->create(['id' => 'posts.delete']);

    $this->store->save('admin', 'Admin', ['posts.create', 'posts.update', 'posts.delete'], system: true);

    $role = $this->store->save('admin', 'Admin', ['posts.delete', 'posts.create', 'posts.update'], system: true);

    expect($role->id)->toBe('admin');
});

it('throws when saving a system role with actually different permissions', function (): void {
    config()->set('marque.protect_system_roles', true);

    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.update']);
    Permission::query()->create(['id' => 'posts.delete']);

    $this->store->save('admin', 'Admin', ['posts.create', 'posts.update'], system: true);

    $this->store->save('admin', 'Admin', ['posts.create', 'posts.delete'], system: true);
})->throws(RuntimeException::class, 'Cannot modify permissions on protected system role [admin].');

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

// --- saveDirectPermissionRole ---

it('creates a synthetic role with __dp. prefix via saveDirectPermissionRole', function (): void {
    Permission::query()->create(['id' => 'posts.create']);

    $role = $this->store->saveDirectPermissionRole('posts.create', ['posts.create']);

    expect($role->id)->toBe('__dp.posts.create')
        ->and($role->name)->toBe('Direct: posts.create')
        ->and($role->is_system)->toBeFalse()
        ->and($this->store->permissionsFor('__dp.posts.create'))->toBe(['posts.create']);
});

it('updates an existing synthetic role via saveDirectPermissionRole', function (): void {
    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.update']);

    $this->store->saveDirectPermissionRole('posts.create', ['posts.create']);
    $role = $this->store->saveDirectPermissionRole('posts.create', ['posts.create', 'posts.update']);

    expect($role->id)->toBe('__dp.posts.create')
        ->and($this->store->permissionsFor('__dp.posts.create'))
        ->toContain('posts.create', 'posts.update')
        ->toHaveCount(2);
});

it('dispatches RoleCreated when saveDirectPermissionRole creates a new role', function (): void {
    Event::fake([RoleCreated::class]);

    $this->store->saveDirectPermissionRole('posts.create', ['posts.create']);

    Event::assertDispatched(RoleCreated::class, function (RoleCreated $event): bool {
        return $event->role->id === '__dp.posts.create';
    });
});
