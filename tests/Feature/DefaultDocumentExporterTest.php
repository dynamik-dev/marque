<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
    $this->boundaryStore = app(BoundaryStore::class);
    $this->exporter = app(DocumentExporter::class);
});

function seedFullState($context): void
{
    $context->permissionStore->register(['posts.create', 'posts.delete', 'posts.update']);
    $context->roleStore->save('editor', 'Editor', ['posts.create', 'posts.update']);
    $context->roleStore->save('admin', 'Admin', ['posts.create', 'posts.delete', 'posts.update'], system: true);
    $context->assignmentStore->assign('App\Models\User', '1', 'editor');
    $context->assignmentStore->assign('App\Models\User', '2', 'admin', 'team::5');
    $context->boundaryStore->set('team::5', ['posts.create', 'posts.update']);
}

// --- export all ---

it('exports all permissions', function (): void {
    seedFullState($this);

    $document = $this->exporter->export();

    expect($document->permissions)->toBe(['posts.create', 'posts.delete', 'posts.update']);
});

it('exports all roles with their permissions', function (): void {
    seedFullState($this);

    $document = $this->exporter->export();

    expect($document->roles)->toHaveCount(2);

    $editor = collect($document->roles)->firstWhere('id', 'editor');
    expect($editor['name'])->toBe('Editor')
        ->and($editor['permissions'])->toBe(['posts.create', 'posts.update'])
        ->and($editor)->not->toHaveKey('system');

    $admin = collect($document->roles)->firstWhere('id', 'admin');
    expect($admin['name'])->toBe('Admin')
        ->and($admin['permissions'])->toHaveCount(3)
        ->and($admin['system'])->toBeTrue();
});

it('exports all assignments with correct serialization', function (): void {
    seedFullState($this);

    $document = $this->exporter->export();

    expect($document->assignments)->toHaveCount(2);

    $unscopedAssignment = collect($document->assignments)->firstWhere('role', 'editor');
    expect($unscopedAssignment['subject'])->toBe('App\Models\User::1')
        ->and($unscopedAssignment)->not->toHaveKey('scope');

    $scopedAssignment = collect($document->assignments)->firstWhere('role', 'admin');
    expect($scopedAssignment['subject'])->toBe('App\Models\User::2')
        ->and($scopedAssignment['scope'])->toBe('team::5');
});

it('exports all boundaries', function (): void {
    seedFullState($this);

    $document = $this->exporter->export();

    expect($document->boundaries)->toHaveCount(1)
        ->and($document->boundaries[0]['scope'])->toBe('team::5')
        ->and($document->boundaries[0]['max_permissions'])->toBe(['posts.create', 'posts.update']);
});

it('returns version 1.0', function (): void {
    $document = $this->exporter->export();

    expect($document->version)->toBe('1.0');
});

// --- export scoped ---

it('exports only assignments in the specified scope', function (): void {
    seedFullState($this);

    $document = $this->exporter->export('team::5');

    expect($document->assignments)->toHaveCount(1)
        ->and($document->assignments[0]['subject'])->toBe('App\Models\User::2')
        ->and($document->assignments[0]['role'])->toBe('admin')
        ->and($document->assignments[0]['scope'])->toBe('team::5');
});

it('exports all permissions even when scoped', function (): void {
    seedFullState($this);

    $document = $this->exporter->export('team::5');

    expect($document->permissions)->toBe(['posts.create', 'posts.delete', 'posts.update']);
});

it('exports only roles that have assignments in the scope', function (): void {
    seedFullState($this);

    $document = $this->exporter->export('team::5');

    expect($document->roles)->toHaveCount(1)
        ->and($document->roles[0]['id'])->toBe('admin');
});

it('exports only the boundary for the specified scope', function (): void {
    seedFullState($this);
    $this->boundaryStore->set('other::1', ['posts.create']);

    $document = $this->exporter->export('team::5');

    expect($document->boundaries)->toHaveCount(1)
        ->and($document->boundaries[0]['scope'])->toBe('team::5');
});

it('returns empty boundaries when scope has no boundary', function (): void {
    seedFullState($this);

    $document = $this->exporter->export('team::999');

    expect($document->boundaries)->toBe([]);
});

// --- export empty state ---

it('exports empty document when no data exists', function (): void {
    $document = $this->exporter->export();

    expect($document->version)->toBe('1.0')
        ->and($document->permissions)->toBe([])
        ->and($document->roles)->toBe([])
        ->and($document->assignments)->toBe([])
        ->and($document->boundaries)->toBe([]);
});

it('exports empty scoped document when scope has no data', function (): void {
    seedFullState($this);

    $document = $this->exporter->export('nonexistent::scope');

    expect($document->permissions)->toBe(['posts.create', 'posts.delete', 'posts.update'])
        ->and($document->roles)->toBe([])
        ->and($document->assignments)->toBe([])
        ->and($document->boundaries)->toBe([]);
});

// --- round-trip with importer ---

it('produces a document that can be reimported identically', function (): void {
    seedFullState($this);

    $exported = $this->exporter->export();

    // Serialize and parse through JSON to simulate a full round-trip
    $parser = app(DocumentParser::class);
    $json = $parser->serialize($exported);
    $parsed = $parser->parse($json);

    // Clear all data and reimport
    $importer = app(DocumentImporter::class);

    $result = $importer->import($parsed, new ImportOptions(merge: false));

    expect($result->permissionsCreated)->toHaveCount(3)
        ->and($result->rolesCreated)->toHaveCount(2)
        ->and($result->assignmentsCreated)->toBe(2);

    // Re-export and compare
    $reExported = $this->exporter->export();

    expect($reExported->permissions)->toBe($exported->permissions)
        ->and($reExported->boundaries)->toBe($exported->boundaries)
        ->and(count($reExported->roles))->toBe(count($exported->roles))
        ->and(count($reExported->assignments))->toBe(count($exported->assignments));
});
