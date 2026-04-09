<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\ResourcePolicyStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\Enums\Effect;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Boundary;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\ResourcePolicy;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
    $this->boundaryStore = app(BoundaryStore::class);
    $this->resourcePolicyStore = app(ResourcePolicyStore::class);
    $this->exporter = app(DocumentExporter::class);
    $this->importer = app(DocumentImporter::class);
    $this->parser = app(DocumentParser::class);
});

/**
 * Clear all policy engine tables in FK-safe order.
 */
function clearDatabase(): void
{
    ResourcePolicy::query()->delete();
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

    // Exporter now outputs v2 format.
    expect($originalExport->version)->toBe('2.0');
    expect($originalExport->roles)->toHaveKey('editor');
    expect($originalExport->roles)->toHaveKey('admin');
    expect($originalExport->boundaries)->toHaveKey('org::acme');

    // Serialize to JSON and parse back.
    $json = $this->parser->serialize($originalExport);
    $parsed = $this->parser->parse($json);

    // After parse, roles and boundaries are normalized to v1-compatible indexed format.
    expect($parsed->version)->toBe('2.0');
    expect($parsed->permissions)->toBe($originalExport->permissions);
    expect($parsed->assignments)->toBe($originalExport->assignments);

    // Verify role count
    expect($parsed->roles)->toHaveCount(2);

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

    expect($reExported->version)->toBe('2.0');
    expect($reExported->permissions)->toBe($originalExport->permissions);

    // Boundaries keyed by scope — both should have the same keys
    expect(array_keys($reExported->boundaries))->toEqualCanonicalizing(array_keys($originalExport->boundaries));

    // Verify roles match (order-independent via keys in v2 format).
    expect($reExported->roles)->toHaveCount(count($originalExport->roles));

    foreach (array_keys($originalExport->roles) as $roleId) {
        expect($reExported->roles)->toHaveKey($roleId);
        expect($reExported->roles[$roleId]['permissions'])->toBe($originalExport->roles[$roleId]['permissions']);
    }

    // Verify system flag preserved on admin
    expect($reExported->roles['admin']['system'])->toBeTrue();

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
    expect($scopedExport->permissions)->toEqualCanonicalizing(['posts.create', 'posts.delete', 'comments.create'])
        ->and($scopedExport->assignments)->toHaveCount(2)
        ->and($scopedExport->boundaries)->toHaveCount(1)
        ->and($scopedExport->boundaries)->toHaveKey('team::5');

    // Only the moderator role should be present (only role assigned in team::5).
    expect($scopedExport->roles)->toHaveCount(1);
    expect($scopedExport->roles)->toHaveKey('moderator');

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

// --- v2 round-trip with resource policies ---

it('round-trips resource policies through export, serialize, parse, and import', function (): void {
    $this->permissionStore->register(['posts.read']);

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.read',
        principalPattern: '*',
    );
    $this->resourcePolicyStore->attach('post', null, $statement);

    // Export → serialize → parse → clear → import
    $exported = $this->exporter->export();
    expect($exported->resourcePolicies)->toHaveCount(1);

    $json = $this->parser->serialize($exported);
    clearDatabase();

    $parsed = $this->parser->parse($json);
    expect($parsed->resourcePolicies)->toHaveCount(1);

    $this->importer->import($parsed, new ImportOptions(merge: false));

    expect(ResourcePolicy::query()->count())->toBe(1);

    $rp = ResourcePolicy::query()->first();
    expect($rp->resource_type)->toBe('post')
        ->and($rp->resource_id)->toBeNull()
        ->and($rp->effect)->toBe('Allow')
        ->and($rp->action)->toBe('posts.read')
        ->and($rp->principal_pattern)->toBe('*');
});

// --- v2 document parsed from JSON string round-trip ---

it('parses a v2 JSON document and imports it correctly', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'permissions' => ['posts.create', 'posts.read'],
        'roles' => [
            'editor' => ['permissions' => ['posts.create', 'posts.read']],
        ],
        'assignments' => [
            ['subject' => 'App\Models\User::1', 'role' => 'editor'],
        ],
        'boundaries' => [
            'free-tier' => ['max_permissions' => ['posts.read']],
        ],
        'resource_policies' => [
            [
                'resource_type' => 'post',
                'resource_id' => null,
                'effect' => 'Allow',
                'action' => 'posts.read',
                'principal_pattern' => '*',
                'conditions' => [],
            ],
        ],
    ]);

    $parser = app(DocumentParser::class);
    $document = $parser->parse($json);

    $this->importer->import($document, new ImportOptions(merge: false));

    expect(Permission::query()->count())->toBe(2);
    expect(Role::query()->count())->toBe(1);
    expect(Role::query()->find('editor'))->not->toBeNull();
    expect(Assignment::query()->count())->toBe(1);
    expect(Boundary::query()->count())->toBe(1);
    expect(ResourcePolicy::query()->count())->toBe(1);

    expect($this->roleStore->permissionsFor('editor'))->toBe(['posts.create', 'posts.read']);
    expect($this->boundaryStore->find('free-tier'))->not->toBeNull();
});
