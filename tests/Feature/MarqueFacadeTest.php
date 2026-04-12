<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\ImportResult;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Support\RoleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->boundaryStore = app(BoundaryStore::class);
});

// --- permissions ---

it('registers permissions through the facade', function (): void {
    Marque::permissions(['posts.create', 'posts.read', 'posts.delete']);

    expect($this->permissionStore->exists('posts.create'))->toBeTrue()
        ->and($this->permissionStore->exists('posts.read'))->toBeTrue()
        ->and($this->permissionStore->exists('posts.delete'))->toBeTrue();
});

// --- role ---

it('creates a role and returns a RoleBuilder', function (): void {
    $builder = Marque::role('editor', 'Editor');

    expect($builder)->toBeInstanceOf(RoleBuilder::class)
        ->and($this->roleStore->find('editor'))->not->toBeNull()
        ->and($this->roleStore->find('editor')->name)->toBe('Editor');
});

it('creates a role with grant chaining', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);

    Marque::role('editor', 'Editor')
        ->grant(['posts.create', 'posts.read'])
        ->grant(['posts.delete']);

    expect($this->roleStore->permissionsFor('editor'))
        ->toContain('posts.create', 'posts.read', 'posts.delete')
        ->toHaveCount(3);
});

it('creates a role with grant and ungrant chaining', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);

    Marque::role('editor', 'Editor')
        ->grant(['posts.create', 'posts.read', 'posts.delete'])
        ->ungrant(['posts.delete']);

    $permissions = $this->roleStore->permissionsFor('editor');

    expect($permissions)
        ->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.delete');
});

it('creates a system role', function (): void {
    Marque::role('super-admin', 'Super Admin', system: true);

    $role = $this->roleStore->find('super-admin');

    expect($role->is_system)->toBeTrue();
});

it('creates a system role with grant chaining on initial run', function (): void {
    config()->set('marque.protect_system_roles', true);
    $this->permissionStore->register(['posts.read', 'comments.read']);

    Marque::role('viewer', 'Viewer', system: true)
        ->grant(['posts.read', 'comments.read']);

    expect($this->roleStore->find('viewer')->is_system)->toBeTrue()
        ->and($this->roleStore->permissionsFor('viewer'))
        ->toContain('posts.read', 'comments.read');
});

it('still protects a system role from permission changes after initial setup', function (): void {
    config()->set('marque.protect_system_roles', true);
    $this->permissionStore->register(['posts.read', 'comments.read', 'posts.delete']);

    // Initial creation with permissions — this should work
    Marque::role('viewer', 'Viewer', system: true)
        ->grant(['posts.read', 'comments.read']);

    // Subsequent attempt to change permissions — this should throw
    expect(fn () => Marque::role('viewer', 'Viewer', system: true)
        ->grant(['posts.delete'])
    )->toThrow(RuntimeException::class);
});

it('removes a role via the builder', function (): void {
    Marque::role('editor', 'Editor');

    expect($this->roleStore->find('editor'))->not->toBeNull();

    Marque::role('editor', 'Editor')->remove();

    expect($this->roleStore->find('editor'))->toBeNull();
});

// --- boundary ---

it('sets a boundary through the facade', function (): void {
    Marque::boundary('team::5', ['posts.create', 'posts.read']);

    $boundary = $this->boundaryStore->find('team::5');

    expect($boundary)->not->toBeNull()
        ->and($boundary->max_permissions)->toBe(['posts.create', 'posts.read']);
});

// --- import ---

it('imports a policy document from a raw string', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create', 'posts.read'],
        'roles' => [['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create']]],
    ]);

    $result = Marque::import($json);

    expect($result)->toBeInstanceOf(ImportResult::class)
        ->and($result->permissionsCreated)->toBe(['posts.create', 'posts.read'])
        ->and($result->rolesCreated)->toBe(['editor']);
});

it('imports a policy document from a file path', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'policy_');
    file_put_contents($path, json_encode([
        'version' => '1.0',
        'permissions' => ['comments.create'],
        'roles' => [],
    ]));

    $result = Marque::import($path);

    expect($result->permissionsCreated)->toBe(['comments.create']);

    unlink($path);
});

it('imports with custom options', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create'],
    ]);

    $options = new ImportOptions(validate: false, dryRun: true);
    $result = Marque::import($json, $options);

    expect($result)->toBeInstanceOf(ImportResult::class);
});

// --- export ---

it('exports the current configuration as a string', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);

    $output = Marque::export();
    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['version'])->toBe('2.0')
        ->and($decoded['permissions'])->toBe(['posts.create', 'posts.read'])
        ->and($decoded['roles'])->toHaveKey('editor');
});

it('exports the current configuration to a file', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);

    $path = tempnam(sys_get_temp_dir(), 'policy_export_');

    Marque::exportToFile($path);

    $decoded = json_decode(file_get_contents($path), true);

    expect($decoded)->toBeArray()
        ->and($decoded['version'])->toBe('2.0')
        ->and($decoded['permissions'])->toBe(['posts.create', 'posts.read'])
        ->and($decoded['roles'])->toHaveKey('editor');

    unlink($path);
});
