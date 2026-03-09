<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\Events\DocumentImported;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Boundary;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

mutates(DefaultDocumentImporter::class);

beforeEach(function (): void {
    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
    $this->assignmentStore = new EloquentAssignmentStore;
    $this->boundaryStore = new EloquentBoundaryStore;

    $this->importer = new DefaultDocumentImporter(
        permissionStore: $this->permissionStore,
        roleStore: $this->roleStore,
        assignmentStore: $this->assignmentStore,
        boundaryStore: $this->boundaryStore,
    );
});

function fullDocument(): PolicyDocument
{
    return new PolicyDocument(
        version: '1.0',
        permissions: ['posts.create', 'posts.delete', 'posts.update'],
        roles: [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.update']],
            ['id' => 'admin', 'name' => 'Admin', 'permissions' => ['posts.create', 'posts.delete', 'posts.update'], 'system' => true],
        ],
        assignments: [
            ['subject' => 'App\Models\User::1', 'role' => 'editor'],
            ['subject' => 'App\Models\User::2', 'role' => 'admin', 'scope' => 'team::5'],
        ],
        boundaries: [
            ['scope' => 'team::5', 'max_permissions' => ['posts.create', 'posts.update']],
        ],
    );
}

// --- full import ---

it('imports a complete document', function (): void {
    $result = $this->importer->import(fullDocument(), new ImportOptions);

    expect($result->permissionsCreated)->toBe(['posts.create', 'posts.delete', 'posts.update'])
        ->and($result->rolesCreated)->toBe(['editor', 'admin'])
        ->and($result->rolesUpdated)->toBe([])
        ->and($result->assignmentsCreated)->toBe(2)
        ->and($result->warnings)->toBe([]);

    expect(Permission::query()->count())->toBe(3);
    expect(Role::query()->count())->toBe(2);
    expect(Assignment::query()->count())->toBe(2);
    expect(Boundary::query()->count())->toBe(1);
});

it('registers system roles correctly', function (): void {
    $this->importer->import(fullDocument(), new ImportOptions);

    $admin = Role::query()->find('admin');
    expect($admin->is_system)->toBeTrue();

    $editor = Role::query()->find('editor');
    expect($editor->is_system)->toBeFalse();
});

it('creates role permission associations', function (): void {
    $this->importer->import(fullDocument(), new ImportOptions);

    $editorPerms = $this->roleStore->permissionsFor('editor');
    expect($editorPerms)->toBe(['posts.create', 'posts.update']);

    $adminPerms = $this->roleStore->permissionsFor('admin');
    expect($adminPerms)->toHaveCount(3);
});

it('sets boundaries correctly', function (): void {
    $this->importer->import(fullDocument(), new ImportOptions);

    $boundary = $this->boundaryStore->find('team::5');
    expect($boundary)->not->toBeNull()
        ->and($boundary->max_permissions)->toBe(['posts.create', 'posts.update']);
});

it('creates assignments with correct subject parsing', function (): void {
    $this->importer->import(fullDocument(), new ImportOptions);

    $assignments = Assignment::query()->get();
    $first = $assignments->firstWhere('role_id', 'editor');

    expect($first->subject_type)->toBe('App\Models\User')
        ->and((string) $first->subject_id)->toBe('1')
        ->and($first->scope)->toBeNull();

    $second = $assignments->firstWhere('role_id', 'admin');
    expect($second->subject_type)->toBe('App\Models\User')
        ->and((string) $second->subject_id)->toBe('2')
        ->and($second->scope)->toBe('team::5');
});

it('dispatches DocumentImported event', function (): void {
    Event::fake([DocumentImported::class]);

    $this->importer->import(fullDocument(), new ImportOptions);

    Event::assertDispatched(DocumentImported::class, function (DocumentImported $event): bool {
        return count($event->result->permissionsCreated) === 3
            && count($event->result->rolesCreated) === 2
            && $event->result->assignmentsCreated === 2;
    });
});

// --- merge mode ---

it('merges with existing data in merge mode', function (): void {
    $this->permissionStore->register('posts.create');
    $this->roleStore->save('editor', 'Old Editor', ['posts.create']);

    $result = $this->importer->import(fullDocument(), new ImportOptions(merge: true));

    expect($result->permissionsCreated)->toBe(['posts.delete', 'posts.update'])
        ->and($result->rolesCreated)->toBe(['admin'])
        ->and($result->rolesUpdated)->toBe(['editor']);

    expect(Permission::query()->count())->toBe(3);
    expect(Role::query()->count())->toBe(2);
});

// --- replace mode ---

it('replaces all existing data in replace mode', function (): void {
    $this->permissionStore->register(['existing.perm', 'old.perm']);
    $this->roleStore->save('oldrole', 'Old Role', ['existing.perm']);

    $result = $this->importer->import(fullDocument(), new ImportOptions(merge: false));

    expect($result->permissionsCreated)->toBe(['posts.create', 'posts.delete', 'posts.update'])
        ->and($result->rolesCreated)->toBe(['editor', 'admin'])
        ->and($result->rolesUpdated)->toBe([]);

    expect(Permission::query()->count())->toBe(3);
    expect(Permission::query()->where('id', 'existing.perm')->exists())->toBeFalse();
    expect(Role::query()->where('id', 'oldrole')->exists())->toBeFalse();
});

// --- dry run ---

it('returns correct result in dry run without modifying database', function (): void {
    $result = $this->importer->import(fullDocument(), new ImportOptions(dryRun: true));

    expect($result->permissionsCreated)->toBe(['posts.create', 'posts.delete', 'posts.update'])
        ->and($result->rolesCreated)->toBe(['editor', 'admin'])
        ->and($result->assignmentsCreated)->toBe(2);

    expect(Permission::query()->count())->toBe(0);
    expect(Role::query()->count())->toBe(0);
    expect(Assignment::query()->count())->toBe(0);
    expect(Boundary::query()->count())->toBe(0);
});

it('does not dispatch DocumentImported event during dry run', function (): void {
    Event::fake([DocumentImported::class]);

    $this->importer->import(fullDocument(), new ImportOptions(dryRun: true));

    Event::assertNotDispatched(DocumentImported::class);
});

it('correctly identifies existing items in dry run merge mode', function (): void {
    $this->permissionStore->register('posts.create');
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $result = $this->importer->import(fullDocument(), new ImportOptions(dryRun: true, merge: true));

    expect($result->permissionsCreated)->toBe(['posts.delete', 'posts.update'])
        ->and($result->rolesCreated)->toBe(['admin'])
        ->and($result->rolesUpdated)->toBe(['editor']);

    // existing data should remain untouched
    expect(Permission::query()->count())->toBe(1);
    expect(Role::query()->count())->toBe(1);
});

// --- skip assignments ---

it('skips assignments when skipAssignments is true', function (): void {
    $result = $this->importer->import(fullDocument(), new ImportOptions(skipAssignments: true));

    expect($result->assignmentsCreated)->toBe(0);
    expect(Assignment::query()->count())->toBe(0);

    // other items still imported
    expect(Permission::query()->count())->toBe(3);
    expect(Role::query()->count())->toBe(2);
});

// --- validation warnings ---

it('warns about unregistered permissions in role definitions', function (): void {
    $document = new PolicyDocument(
        version: '1.0',
        permissions: ['posts.create'],
        roles: [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.delete', 'unknown.perm']],
        ],
    );

    $result = $this->importer->import($document, new ImportOptions);

    expect($result->warnings)->toHaveCount(2)
        ->and($result->warnings[0])->toContain('posts.delete')
        ->and($result->warnings[1])->toContain('unknown.perm');
});

it('skips validation when validate option is false', function (): void {
    $document = new PolicyDocument(
        version: '1.0',
        permissions: [],
        roles: [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['missing.perm']],
        ],
    );

    $result = $this->importer->import($document, new ImportOptions(validate: false));

    expect($result->warnings)->toBe([]);
});

it('does not warn about permissions already registered in the store', function (): void {
    $this->permissionStore->register('existing.perm');

    $document = new PolicyDocument(
        version: '1.0',
        permissions: ['posts.create'],
        roles: [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'existing.perm']],
        ],
    );

    $result = $this->importer->import($document, new ImportOptions);

    expect($result->warnings)->toBe([]);
});

// --- empty document ---

it('handles an empty document gracefully', function (): void {
    $result = $this->importer->import(new PolicyDocument, new ImportOptions);

    expect($result->permissionsCreated)->toBe([])
        ->and($result->rolesCreated)->toBe([])
        ->and($result->rolesUpdated)->toBe([])
        ->and($result->assignmentsCreated)->toBe(0)
        ->and($result->warnings)->toBe([]);
});
