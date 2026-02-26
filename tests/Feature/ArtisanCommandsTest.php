<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentExporter;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter;
use DynamikDev\PolicyEngine\Documents\JsonDocumentParser;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\PrimitivesManager;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PermissionStore::class, new EloquentPermissionStore);
    app()->instance(RoleStore::class, new EloquentRoleStore);
    app()->instance(AssignmentStore::class, new EloquentAssignmentStore);
    app()->instance(BoundaryStore::class, new EloquentBoundaryStore);
    app()->instance(Matcher::class, new WildcardMatcher);
    app()->instance(Evaluator::class, new DefaultEvaluator(
        app(AssignmentStore::class),
        app(RoleStore::class),
        app(BoundaryStore::class),
        app(Matcher::class),
    ));
    app()->instance(DocumentParser::class, new JsonDocumentParser);
    app()->instance(DocumentImporter::class, new DefaultDocumentImporter(
        app(PermissionStore::class),
        app(RoleStore::class),
        app(AssignmentStore::class),
        app(BoundaryStore::class),
    ));
    app()->instance(DocumentExporter::class, new DefaultDocumentExporter(
        app(PermissionStore::class),
        app(RoleStore::class),
        app(AssignmentStore::class),
        app(BoundaryStore::class),
    ));
    app()->instance(PrimitivesManager::class, new PrimitivesManager(
        app(PermissionStore::class),
        app(RoleStore::class),
        app(BoundaryStore::class),
        app(DocumentParser::class),
        app(DocumentImporter::class),
        app(DocumentExporter::class),
    ));
});

// --- primitives:permissions ---

it('lists permissions in a table', function (): void {
    $store = app(PermissionStore::class);
    $store->register(['posts.create', 'posts.delete']);

    $this->artisan('primitives:permissions')
        ->expectsTable(['ID', 'Description'], [
            ['posts.create', ''],
            ['posts.delete', ''],
        ])
        ->assertSuccessful();
});

it('shows info message when no permissions exist', function (): void {
    $this->artisan('primitives:permissions')
        ->expectsOutput('No permissions registered.')
        ->assertSuccessful();
});

// --- primitives:roles ---

it('lists roles with their permissions', function (): void {
    $store = app(RoleStore::class);
    $store->save('editor', 'Editor', ['posts.create', 'posts.update']);

    $this->artisan('primitives:roles')
        ->expectsTable(['ID', 'Name', 'System', 'Permissions'], [
            ['editor', 'Editor', 'No', 2],
        ])
        ->expectsOutputToContain('posts.create')
        ->expectsOutputToContain('posts.update')
        ->assertSuccessful();
});

it('shows system flag for system roles', function (): void {
    $store = app(RoleStore::class);
    $store->save('admin', 'Administrator', ['*.*'], system: true);

    $this->artisan('primitives:roles')
        ->expectsTable(['ID', 'Name', 'System', 'Permissions'], [
            ['admin', 'Administrator', 'Yes', 1],
        ])
        ->assertSuccessful();
});

it('shows info message when no roles exist', function (): void {
    $this->artisan('primitives:roles')
        ->expectsOutput('No roles registered.')
        ->assertSuccessful();
});

// --- primitives:assignments ---

it('lists assignments for a subject', function (): void {
    $store = app(AssignmentStore::class);
    $store->assign('user', '42', 'editor');

    $this->artisan('primitives:assignments', ['subject' => 'user::42'])
        ->expectsTable(['Subject Type', 'Subject ID', 'Role', 'Scope'], [
            ['user', '42', 'editor', '(global)'],
        ])
        ->assertSuccessful();
});

it('lists scoped assignments for a subject', function (): void {
    $store = app(AssignmentStore::class);
    $store->assign('user', '42', 'editor', 'group::5');
    $store->assign('user', '42', 'viewer');

    $this->artisan('primitives:assignments', ['subject' => 'user::42', '--scope' => 'group::5'])
        ->expectsTable(['Subject Type', 'Subject ID', 'Role', 'Scope'], [
            ['user', '42', 'editor', 'group::5'],
        ])
        ->assertSuccessful();
});

it('lists all assignments in a scope', function (): void {
    $store = app(AssignmentStore::class);
    $store->assign('user', '42', 'editor', 'group::5');
    $store->assign('user', '99', 'viewer', 'group::5');

    $this->artisan('primitives:assignments', ['--scope' => 'group::5'])
        ->expectsTable(['Subject Type', 'Subject ID', 'Role', 'Scope'], [
            ['user', '42', 'editor', 'group::5'],
            ['user', '99', 'viewer', 'group::5'],
        ])
        ->assertSuccessful();
});

it('shows usage help when no arguments provided', function (): void {
    $this->artisan('primitives:assignments')
        ->expectsOutputToContain('Usage:')
        ->assertSuccessful();
});

it('shows info message when no assignments found for subject', function (): void {
    $this->artisan('primitives:assignments', ['subject' => 'user::999'])
        ->expectsOutput('No assignments found.')
        ->assertSuccessful();
});

it('shows error for invalid subject format', function (): void {
    $this->artisan('primitives:assignments', ['subject' => 'invalid-format'])
        ->expectsOutputToContain('Invalid subject format')
        ->assertExitCode(1);
});

// --- primitives:explain ---

it('explains an allowed permission check', function (): void {
    config()->set('policy-engine.explain', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create', 'posts.update']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'editor');

    $this->artisan('primitives:explain', ['subject' => 'user::42', 'permission' => 'posts.create'])
        ->expectsOutputToContain('user:42')
        ->expectsOutputToContain('ALLOW')
        ->expectsOutputToContain('editor')
        ->assertSuccessful();
});

it('explains a denied permission check', function (): void {
    config()->set('policy-engine.explain', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('viewer', 'Viewer', ['posts.read']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'viewer');

    $this->artisan('primitives:explain', ['subject' => 'user::42', 'permission' => 'posts.delete'])
        ->expectsOutputToContain('user:42')
        ->expectsOutputToContain('posts.delete')
        ->expectsOutputToContain('DENY')
        ->expectsOutputToContain('viewer')
        ->assertSuccessful();
});

it('shows error when explain mode is disabled', function (): void {
    config()->set('policy-engine.explain', false);

    $this->artisan('primitives:explain', ['subject' => 'user::42', 'permission' => 'posts.create'])
        ->expectsOutputToContain('Explain mode is disabled')
        ->assertExitCode(1);
});

it('shows error for invalid subject format in explain', function (): void {
    config()->set('policy-engine.explain', true);

    $this->artisan('primitives:explain', ['subject' => 'bad-format', 'permission' => 'posts.create'])
        ->expectsOutputToContain('Invalid subject format')
        ->assertExitCode(1);
});

it('explains a scoped permission check', function (): void {
    config()->set('policy-engine.explain', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'editor', 'group::5');

    $this->artisan('primitives:explain', [
        'subject' => 'user::42',
        'permission' => 'posts.create',
        '--scope' => 'group::5',
    ])
        ->expectsOutputToContain('posts.create')
        ->expectsOutputToContain('ALLOW')
        ->expectsOutputToContain('group::5')
        ->assertSuccessful();
});

it('shows cache hit status in explain output', function (): void {
    config()->set('policy-engine.explain', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('viewer', 'Viewer', ['posts.read']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'viewer');

    $this->artisan('primitives:explain', ['subject' => 'user::42', 'permission' => 'posts.read'])
        ->expectsOutputToContain('Cache hit')
        ->assertSuccessful();
});

// --- primitives:import ---

it('imports a policy document from a file', function (): void {
    $document = [
        'version' => '1.0',
        'permissions' => ['posts.create', 'posts.delete'],
        'roles' => [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.delete']],
        ],
        'assignments' => [
            ['subject' => 'user::42', 'role' => 'editor'],
        ],
        'boundaries' => [],
    ];

    $path = tempnam(sys_get_temp_dir(), 'policy_');
    file_put_contents($path, json_encode($document));

    $this->artisan('primitives:import', ['path' => $path])
        ->expectsOutputToContain('Permissions created: 2')
        ->expectsOutputToContain('Roles created: 1')
        ->expectsOutputToContain('Assignments created: 1')
        ->assertSuccessful();

    expect(app(PermissionStore::class)->exists('posts.create'))->toBeTrue();
    expect(app(PermissionStore::class)->exists('posts.delete'))->toBeTrue();
    expect(app(RoleStore::class)->find('editor'))->not->toBeNull();

    unlink($path);
});

it('shows dry run output without applying changes', function (): void {
    $document = [
        'version' => '1.0',
        'permissions' => ['posts.create', 'posts.delete'],
        'roles' => [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create']],
        ],
        'assignments' => [],
        'boundaries' => [],
    ];

    $path = tempnam(sys_get_temp_dir(), 'policy_');
    file_put_contents($path, json_encode($document));

    $this->artisan('primitives:import', ['path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('Permissions created: 2')
        ->expectsOutputToContain('Roles created: 1')
        ->assertSuccessful();

    expect(app(PermissionStore::class)->exists('posts.create'))->toBeFalse();
    expect(app(RoleStore::class)->find('editor'))->toBeNull();

    unlink($path);
});

it('shows error when import file not found', function (): void {
    $this->artisan('primitives:import', ['path' => '/tmp/nonexistent-policy-file.json'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(1);
});

// --- primitives:export ---

it('exports authorization state to stdout', function (): void {
    $permissionStore = app(PermissionStore::class);
    $permissionStore->register(['posts.create', 'posts.delete']);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create', 'posts.delete']);

    $exitCode = Artisan::call('primitives:export');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"version"');
    expect($output)->toContain('posts.create');
    expect($output)->toContain('editor');
});

it('exports authorization state to a file', function (): void {
    $permissionStore = app(PermissionStore::class);
    $permissionStore->register(['posts.create']);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create']);

    $path = tempnam(sys_get_temp_dir(), 'policy_export_');

    $this->artisan('primitives:export', ['--path' => $path])
        ->expectsOutputToContain("Exported to {$path}")
        ->assertSuccessful();

    $contents = file_get_contents($path);
    $decoded = json_decode($contents, true);

    expect($decoded)->toHaveKey('version', '1.0');
    expect($decoded['permissions'])->toContain('posts.create');
    expect($decoded['roles'][0]['id'])->toBe('editor');

    unlink($path);
});

// --- primitives:validate ---

it('validates a valid policy document', function (): void {
    $document = [
        'version' => '1.0',
        'permissions' => ['posts.create', 'posts.delete'],
        'roles' => [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.delete']],
        ],
        'assignments' => [],
        'boundaries' => [],
    ];

    $path = tempnam(sys_get_temp_dir(), 'policy_');
    file_put_contents($path, json_encode($document));

    $this->artisan('primitives:validate', ['path' => $path])
        ->expectsOutput('Policy document is valid.')
        ->assertSuccessful();

    unlink($path);
});

it('validates an invalid policy document', function (): void {
    $document = [
        'permissions' => 'not-an-array',
    ];

    $path = tempnam(sys_get_temp_dir(), 'policy_');
    file_put_contents($path, json_encode($document));

    $this->artisan('primitives:validate', ['path' => $path])
        ->expectsOutputToContain('invalid')
        ->assertExitCode(1);

    unlink($path);
});

it('shows error when validate file not found', function (): void {
    $this->artisan('primitives:validate', ['path' => '/tmp/nonexistent-policy-file.json'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(1);
});

it('exports scoped authorization state', function (): void {
    $permissionStore = app(PermissionStore::class);
    $permissionStore->register(['posts.create', 'posts.delete']);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create']);
    $roleStore->save('admin', 'Admin', ['posts.delete']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'editor', 'group::5');
    $assignmentStore->assign('user', '99', 'admin');

    $this->artisan('primitives:export', ['--scope' => 'group::5'])
        ->expectsOutputToContain('editor')
        ->assertSuccessful();
});

// --- primitives:cache-clear ---

it('clears the policy engine cache', function (): void {
    config()->set('policy-engine.cache.store', 'array');

    $this->artisan('primitives:cache-clear')
        ->expectsOutputToContain('cache cleared')
        ->assertSuccessful();
});

// --- primitives:sync ---

it('runs the sync command and handles missing seeder gracefully', function (): void {
    $this->artisan('primitives:sync')
        ->expectsOutputToContain('Failed to sync')
        ->assertExitCode(1);
});

it('runs the sync command successfully with a valid seeder', function (): void {
    // Define the PermissionSeeder in the namespace db:seed expects
    if (! class_exists('Database\Seeders\PermissionSeeder', false)) {
        eval('namespace Database\Seeders; class PermissionSeeder extends \Illuminate\Database\Seeder { public function run(): void {} }');
    }

    $this->artisan('primitives:sync')
        ->expectsOutputToContain('sync completed')
        ->assertSuccessful();
});
