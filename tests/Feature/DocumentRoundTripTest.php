<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Documents\DefaultDocumentExporter;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter;
use DynamikDev\PolicyEngine\Documents\JsonDocumentParser;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Boundary;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Models\RolePermission;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
    $this->assignmentStore = new EloquentAssignmentStore;
    $this->boundaryStore = new EloquentBoundaryStore;

    $this->exporter = new DefaultDocumentExporter(
        permissionStore: $this->permissionStore,
        roleStore: $this->roleStore,
        assignmentStore: $this->assignmentStore,
        boundaryStore: $this->boundaryStore,
    );

    $this->importer = new DefaultDocumentImporter(
        permissionStore: $this->permissionStore,
        roleStore: $this->roleStore,
        assignmentStore: $this->assignmentStore,
        boundaryStore: $this->boundaryStore,
    );

    $this->parser = new JsonDocumentParser;
});

/**
 * Clear all policy engine tables in FK-safe order.
 */
function clearDatabase(): void
{
    Assignment::query()->delete();
    RolePermission::query()->delete();
    Boundary::query()->delete();
    Role::query()->delete();
    Permission::query()->delete();
}

// --- Full round-trip: seed → export → serialize → clear → parse → import → re-export → verify ---

it('round-trips a full authorization state through export, serialize, clear, parse, and import', function (): void {
    // Seed permissions, roles (including a system role), assignments (global and scoped), and boundaries.
    $this->permissionStore->register(['posts.create', 'posts.update', 'posts.delete', 'comments.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.update']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'posts.update', 'posts.delete', 'comments.create'], system: true);
    $this->assignmentStore->assign('App\Models\User', '1', 'editor');
    $this->assignmentStore->assign('App\Models\User', '2', 'admin', 'org::acme');
    $this->assignmentStore->assign('App\Models\User', '1', 'admin');
    $this->boundaryStore->set('org::acme', ['posts.create', 'posts.update', 'comments.create']);

    // Export the full state.
    $originalExport = $this->exporter->export();

    // Serialize to JSON and parse back.
    $json = $this->parser->serialize($originalExport);
    $parsed = $this->parser->parse($json);

    // Verify the parsed document matches the original export.
    expect($parsed->version)->toBe($originalExport->version)
        ->and($parsed->permissions)->toBe($originalExport->permissions)
        ->and($parsed->roles)->toBe($originalExport->roles)
        ->and($parsed->assignments)->toBe($originalExport->assignments)
        ->and($parsed->boundaries)->toBe($originalExport->boundaries);

    // Clear the database completely.
    clearDatabase();

    // Verify the database is empty.
    expect(Permission::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(0)
        ->and(Assignment::query()->count())->toBe(0)
        ->and(Boundary::query()->count())->toBe(0);

    // Import the parsed document in replace mode.
    $this->importer->import($parsed, new ImportOptions(merge: false));

    // Re-export and verify all sections match the original.
    $reExported = $this->exporter->export();

    expect($reExported->version)->toBe($originalExport->version)
        ->and($reExported->permissions)->toBe($originalExport->permissions)
        ->and($reExported->boundaries)->toBe($originalExport->boundaries);

    // Verify roles match (order-independent).
    expect($reExported->roles)->toHaveCount(count($originalExport->roles));

    foreach ($originalExport->roles as $originalRole) {
        $reExportedRole = collect($reExported->roles)->firstWhere('id', $originalRole['id']);
        expect($reExportedRole)->not->toBeNull()
            ->and($reExportedRole['name'])->toBe($originalRole['name'])
            ->and($reExportedRole['permissions'])->toBe($originalRole['permissions']);

        if (isset($originalRole['system'])) {
            expect($reExportedRole['system'])->toBe($originalRole['system']);
        }
    }

    // Verify assignments match (order-independent).
    expect($reExported->assignments)->toHaveCount(count($originalExport->assignments));

    foreach ($originalExport->assignments as $originalAssignment) {
        $match = collect($reExported->assignments)->first(function (array $a) use ($originalAssignment): bool {
            return $a['subject'] === $originalAssignment['subject']
                && $a['role'] === $originalAssignment['role']
                && ($a['scope'] ?? null) === ($originalAssignment['scope'] ?? null);
        });
        expect($match)->not->toBeNull();
    }
});

// --- Scoped export/import: only the targeted scope subset is transferred ---

it('exports and imports only data for a specific scope', function (): void {
    // Seed data across multiple scopes.
    $this->permissionStore->register(['posts.create', 'posts.delete', 'comments.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('moderator', 'Moderator', ['posts.delete', 'comments.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.create']);

    $this->assignmentStore->assign('App\Models\User', '1', 'editor');
    $this->assignmentStore->assign('App\Models\User', '2', 'moderator', 'team::5');
    $this->assignmentStore->assign('App\Models\User', '3', 'viewer', 'team::10');
    $this->assignmentStore->assign('App\Models\User', '4', 'moderator', 'team::5');

    $this->boundaryStore->set('team::5', ['posts.create', 'posts.delete', 'comments.create']);
    $this->boundaryStore->set('team::10', ['posts.create']);

    // Export only scope team::5.
    $scopedExport = $this->exporter->export('team::5');

    // Verify the scoped export contains the right subset.
    expect($scopedExport->permissions)->toBe(['posts.create', 'posts.delete', 'comments.create'])
        ->and($scopedExport->assignments)->toHaveCount(2)
        ->and($scopedExport->boundaries)->toHaveCount(1)
        ->and($scopedExport->boundaries[0]['scope'])->toBe('team::5');

    // Only the moderator role should be present (only role assigned in team::5).
    expect($scopedExport->roles)->toHaveCount(1)
        ->and($scopedExport->roles[0]['id'])->toBe('moderator');

    // All scoped assignments reference team::5.
    foreach ($scopedExport->assignments as $assignment) {
        expect($assignment['scope'])->toBe('team::5')
            ->and($assignment['role'])->toBe('moderator');
    }

    // Serialize, clear, parse, and import into clean DB.
    $json = $this->parser->serialize($scopedExport);

    clearDatabase();

    $parsed = $this->parser->parse($json);
    $this->importer->import($parsed, new ImportOptions(merge: false));

    // Verify only the scoped subset was imported.
    expect(Permission::query()->count())->toBe(3)
        ->and(Role::query()->count())->toBe(1)
        ->and(Role::query()->first()->id)->toBe('moderator')
        ->and(Assignment::query()->count())->toBe(2)
        ->and(Boundary::query()->count())->toBe(1)
        ->and(Boundary::query()->first()->scope)->toBe('team::5');

    // Verify no data from team::10 or the global assignment leaked in.
    expect(Assignment::query()->where('scope', 'team::10')->exists())->toBeFalse();
});

// --- Partial document import: roles and permissions only ---

it('imports a partial document with only roles and permissions', function (): void {
    $document = new PolicyDocument(
        version: '1.0',
        permissions: ['posts.create', 'posts.update', 'posts.delete'],
        roles: [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.update']],
            ['id' => 'admin', 'name' => 'Admin', 'permissions' => ['posts.create', 'posts.update', 'posts.delete'], 'system' => true],
        ],
    );

    $this->importer->import($document, new ImportOptions(merge: false));

    // Permissions and roles exist.
    expect(Permission::query()->count())->toBe(3)
        ->and(Role::query()->count())->toBe(2);

    // Role permissions are correctly wired.
    expect($this->roleStore->permissionsFor('editor'))->toBe(['posts.create', 'posts.update'])
        ->and($this->roleStore->permissionsFor('admin'))->toHaveCount(3);

    // System flag is set correctly.
    expect(Role::query()->find('admin')->is_system)->toBeTrue()
        ->and(Role::query()->find('editor')->is_system)->toBeFalse();

    // Assignments and boundaries are empty.
    expect(Assignment::query()->count())->toBe(0)
        ->and(Boundary::query()->count())->toBe(0);
});
