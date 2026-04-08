<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\Facades\PolicyEngine;
use DynamikDev\PolicyEngine\Support\RoleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->boundaryStore = app(BoundaryStore::class);
});

// --- permissions ---

it('registers permissions through the facade', function (): void {
    PolicyEngine::permissions(['posts.create', 'posts.read', 'posts.delete']);

    expect($this->permissionStore->exists('posts.create'))->toBeTrue()
        ->and($this->permissionStore->exists('posts.read'))->toBeTrue()
        ->and($this->permissionStore->exists('posts.delete'))->toBeTrue();
});

// --- role ---

it('creates a role and returns a RoleBuilder', function (): void {
    $builder = PolicyEngine::role('editor', 'Editor');

    expect($builder)->toBeInstanceOf(RoleBuilder::class)
        ->and($this->roleStore->find('editor'))->not->toBeNull()
        ->and($this->roleStore->find('editor')->name)->toBe('Editor');
});

it('creates a role with grant chaining', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);

    PolicyEngine::role('editor', 'Editor')
        ->grant(['posts.create', 'posts.read'])
        ->grant(['posts.delete']);

    expect($this->roleStore->permissionsFor('editor'))
        ->toContain('posts.create', 'posts.read', 'posts.delete')
        ->toHaveCount(3);
});

it('creates a role with grant and ungrant chaining', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);

    PolicyEngine::role('editor', 'Editor')
        ->grant(['posts.create', 'posts.read', 'posts.delete'])
        ->ungrant(['posts.delete']);

    $permissions = $this->roleStore->permissionsFor('editor');

    expect($permissions)
        ->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.delete');
});

it('creates a system role', function (): void {
    PolicyEngine::role('super-admin', 'Super Admin', system: true);

    $role = $this->roleStore->find('super-admin');

    expect($role->is_system)->toBeTrue();
});

it('removes a role via the builder', function (): void {
    PolicyEngine::role('editor', 'Editor');

    expect($this->roleStore->find('editor'))->not->toBeNull();

    PolicyEngine::role('editor', 'Editor')->remove();

    expect($this->roleStore->find('editor'))->toBeNull();
});

// --- boundary ---

it('sets a boundary through the facade', function (): void {
    PolicyEngine::boundary('team::5', ['posts.create', 'posts.read']);

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

    $result = PolicyEngine::import($json);

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

    $result = PolicyEngine::import($path);

    expect($result->permissionsCreated)->toBe(['comments.create']);

    unlink($path);
});

it('imports with custom options', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create'],
    ]);

    $options = new ImportOptions(validate: false, dryRun: true);
    $result = PolicyEngine::import($json, $options);

    expect($result)->toBeInstanceOf(ImportResult::class);
});

// --- export ---

it('exports the current configuration as a string', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);

    $output = PolicyEngine::export();
    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['version'])->toBe('1.0')
        ->and($decoded['permissions'])->toBe(['posts.create', 'posts.read'])
        ->and($decoded['roles'][0]['id'])->toBe('editor');
});

it('exports the current configuration to a file', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);

    $path = tempnam(sys_get_temp_dir(), 'policy_export_');

    PolicyEngine::exportToFile($path);

    $decoded = json_decode(file_get_contents($path), true);

    expect($decoded)->toBeArray()
        ->and($decoded['version'])->toBe('1.0')
        ->and($decoded['permissions'])->toBe(['posts.create', 'posts.read'])
        ->and($decoded['roles'][0]['id'])->toBe('editor');

    unlink($path);
});
