<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\DTOs\ValidationResult;
use DynamikDev\PolicyEngine\Facades\Primitives;
use DynamikDev\PolicyEngine\PrimitivesManager;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

mutates(PrimitivesManager::class);

beforeEach(function (): void {
    $permissionStore = new EloquentPermissionStore;
    $roleStore = new EloquentRoleStore;
    $boundaryStore = new EloquentBoundaryStore;

    app()->instance(PermissionStore::class, $permissionStore);
    app()->instance(RoleStore::class, $roleStore);
    app()->instance(BoundaryStore::class, $boundaryStore);

    // Anonymous class mocks for document contracts (implementations don't exist yet).
    $documentParser = new class implements DocumentParser
    {
        public function parse(string $content): PolicyDocument
        {
            $data = json_decode($content, true);

            return new PolicyDocument(
                version: $data['version'] ?? '1.0',
                permissions: $data['permissions'] ?? [],
                roles: $data['roles'] ?? [],
                assignments: $data['assignments'] ?? [],
                boundaries: $data['boundaries'] ?? [],
            );
        }

        public function serialize(PolicyDocument $document): string
        {
            return json_encode([
                'version' => $document->version,
                'permissions' => $document->permissions,
                'roles' => $document->roles,
                'assignments' => $document->assignments,
                'boundaries' => $document->boundaries,
            ], JSON_PRETTY_PRINT);
        }

        public function validate(string $content): ValidationResult
        {
            return new ValidationResult(valid: true);
        }
    };

    $documentImporter = new class implements DocumentImporter
    {
        public function import(PolicyDocument $document, ImportOptions $options): ImportResult
        {
            return new ImportResult(
                permissionsCreated: $document->permissions,
                rolesCreated: array_column($document->roles, 'id'),
                rolesUpdated: [],
                assignmentsCreated: 0,
                warnings: [],
            );
        }
    };

    $documentExporter = new class implements DocumentExporter
    {
        public function export(?string $scope = null): PolicyDocument
        {
            return new PolicyDocument(
                version: '1.0',
                permissions: ['posts.create', 'posts.read'],
                roles: [['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.read']]],
            );
        }
    };

    app()->instance(DocumentParser::class, $documentParser);
    app()->instance(DocumentImporter::class, $documentImporter);
    app()->instance(DocumentExporter::class, $documentExporter);

    $manager = new PrimitivesManager(
        permissions: $permissionStore,
        roles: $roleStore,
        boundaries: $boundaryStore,
        parser: $documentParser,
        importer: $documentImporter,
        exporter: $documentExporter,
    );

    app()->instance(PrimitivesManager::class, $manager);

    $this->permissionStore = $permissionStore;
    $this->roleStore = $roleStore;
    $this->boundaryStore = $boundaryStore;
});

// --- permissions ---

it('registers permissions through the facade', function (): void {
    Primitives::permissions(['posts.create', 'posts.read', 'posts.delete']);

    expect($this->permissionStore->exists('posts.create'))->toBeTrue()
        ->and($this->permissionStore->exists('posts.read'))->toBeTrue()
        ->and($this->permissionStore->exists('posts.delete'))->toBeTrue();
});

// --- role ---

it('creates a role and returns a RoleBuilder', function (): void {
    $builder = Primitives::role('editor', 'Editor');

    expect($builder)->toBeInstanceOf(\DynamikDev\PolicyEngine\Support\RoleBuilder::class)
        ->and($this->roleStore->find('editor'))->not->toBeNull()
        ->and($this->roleStore->find('editor')->name)->toBe('Editor');
});

it('creates a role with grant chaining', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);

    Primitives::role('editor', 'Editor')
        ->grant(['posts.create', 'posts.read'])
        ->grant(['posts.delete']);

    expect($this->roleStore->permissionsFor('editor'))
        ->toContain('posts.create', 'posts.read', 'posts.delete')
        ->toHaveCount(3);
});

it('creates a role with grant and ungrant chaining', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);

    Primitives::role('editor', 'Editor')
        ->grant(['posts.create', 'posts.read', 'posts.delete'])
        ->ungrant(['posts.delete']);

    $permissions = $this->roleStore->permissionsFor('editor');

    expect($permissions)
        ->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.delete');
});

it('creates a system role', function (): void {
    Primitives::role('super-admin', 'Super Admin', system: true);

    $role = $this->roleStore->find('super-admin');

    expect($role->is_system)->toBeTrue();
});

it('removes a role via the builder', function (): void {
    Primitives::role('editor', 'Editor');

    expect($this->roleStore->find('editor'))->not->toBeNull();

    Primitives::role('editor', 'Editor')->remove();

    expect($this->roleStore->find('editor'))->toBeNull();
});

// --- boundary ---

it('sets a boundary through the facade', function (): void {
    Primitives::boundary('team::5', ['posts.create', 'posts.read']);

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

    $result = Primitives::import($json);

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

    $result = Primitives::import($path);

    expect($result->permissionsCreated)->toBe(['comments.create']);

    unlink($path);
});

it('imports with custom options', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create'],
    ]);

    $options = new ImportOptions(validate: false, dryRun: true);
    $result = Primitives::import($json, $options);

    expect($result)->toBeInstanceOf(ImportResult::class);
});

// --- export ---

it('exports the current configuration as a string', function (): void {
    $output = Primitives::export();
    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['version'])->toBe('1.0')
        ->and($decoded['permissions'])->toBe(['posts.create', 'posts.read']);
});

it('exports the current configuration to a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'policy_export_');

    Primitives::exportToFile($path);

    $decoded = json_decode(file_get_contents($path), true);

    expect($decoded)->toBeArray()
        ->and($decoded['version'])->toBe('1.0')
        ->and($decoded['permissions'])->toBe(['posts.create', 'posts.read']);

    unlink($path);
});
