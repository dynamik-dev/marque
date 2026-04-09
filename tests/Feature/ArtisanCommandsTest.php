<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

// --- marque:permissions ---

it('lists permissions in a table', function (): void {
    $store = app(PermissionStore::class);
    $store->register(['posts.create', 'posts.delete']);

    $this->artisan('marque:permissions')
        ->expectsTable(['ID', 'Description'], [
            ['posts.create', ''],
            ['posts.delete', ''],
        ])
        ->assertSuccessful();
});

it('shows info message when no permissions exist', function (): void {
    $this->artisan('marque:permissions')
        ->expectsOutput('No permissions registered.')
        ->assertSuccessful();
});

// --- marque:roles ---

it('lists roles with their permissions', function (): void {
    $store = app(RoleStore::class);
    $store->save('editor', 'Editor', ['posts.create', 'posts.update']);

    $this->artisan('marque:roles')
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

    $this->artisan('marque:roles')
        ->expectsTable(['ID', 'Name', 'System', 'Permissions'], [
            ['admin', 'Administrator', 'Yes', 1],
        ])
        ->assertSuccessful();
});

it('shows info message when no roles exist', function (): void {
    $this->artisan('marque:roles')
        ->expectsOutput('No roles registered.')
        ->assertSuccessful();
});

// --- marque:assignments ---

it('lists assignments for a subject', function (): void {
    app(RoleStore::class)->save('editor', 'Editor', []);
    $store = app(AssignmentStore::class);
    $store->assign('user', '42', 'editor');

    $this->artisan('marque:assignments', ['subject' => 'user::42'])
        ->expectsTable(['Subject Type', 'Subject ID', 'Role', 'Scope'], [
            ['user', '42', 'editor', '(global)'],
        ])
        ->assertSuccessful();
});

it('lists scoped assignments for a subject', function (): void {
    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', []);
    $roleStore->save('viewer', 'Viewer', []);
    $store = app(AssignmentStore::class);
    $store->assign('user', '42', 'editor', 'group::5');
    $store->assign('user', '42', 'viewer');

    $this->artisan('marque:assignments', ['subject' => 'user::42', '--scope' => 'group::5'])
        ->expectsTable(['Subject Type', 'Subject ID', 'Role', 'Scope'], [
            ['user', '42', 'editor', 'group::5'],
        ])
        ->assertSuccessful();
});

it('lists all assignments in a scope', function (): void {
    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', []);
    $roleStore->save('viewer', 'Viewer', []);
    $store = app(AssignmentStore::class);
    $store->assign('user', '42', 'editor', 'group::5');
    $store->assign('user', '99', 'viewer', 'group::5');

    $this->artisan('marque:assignments', ['--scope' => 'group::5'])
        ->expectsTable(['Subject Type', 'Subject ID', 'Role', 'Scope'], [
            ['user', '42', 'editor', 'group::5'],
            ['user', '99', 'viewer', 'group::5'],
        ])
        ->assertSuccessful();
});

it('shows usage help when no arguments provided', function (): void {
    $this->artisan('marque:assignments')
        ->expectsOutputToContain('Usage:')
        ->assertSuccessful();
});

it('shows info message when no assignments found for subject', function (): void {
    $this->artisan('marque:assignments', ['subject' => 'user::999'])
        ->expectsOutput('No assignments found.')
        ->assertSuccessful();
});

it('shows error for invalid subject format', function (): void {
    $this->artisan('marque:assignments', ['subject' => 'invalid-format'])
        ->expectsOutputToContain('Invalid subject format')
        ->assertExitCode(1);
});

it('shows error for subject with empty ID after separator', function (): void {
    $this->artisan('marque:assignments', ['subject' => 'user::'])
        ->expectsOutputToContain('Invalid subject format')
        ->assertExitCode(1);
});

// --- marque:explain ---

it('explains an allowed permission check', function (): void {
    config()->set('marque.trace', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create', 'posts.update']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'editor');

    $this->artisan('marque:explain', ['subject' => 'user::42', 'permission' => 'posts.create'])
        ->expectsOutputToContain('user:42')
        ->expectsOutputToContain('ALLOW')
        ->expectsOutputToContain('editor')
        ->assertSuccessful();
});

it('explains a denied permission check', function (): void {
    config()->set('marque.trace', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('viewer', 'Viewer', ['posts.read']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'viewer');

    $this->artisan('marque:explain', ['subject' => 'user::42', 'permission' => 'posts.delete'])
        ->expectsOutputToContain('user:42')
        ->expectsOutputToContain('posts.delete')
        ->expectsOutputToContain('DENY')
        ->assertSuccessful();
});

it('shows error when explain mode is disabled', function (): void {
    config()->set('marque.trace', false);

    $this->artisan('marque:explain', ['subject' => 'user::42', 'permission' => 'posts.create'])
        ->expectsOutputToContain('Explain mode is disabled')
        ->assertExitCode(1);
});

it('shows error for invalid subject format in explain', function (): void {
    config()->set('marque.trace', true);

    $this->artisan('marque:explain', ['subject' => 'bad-format', 'permission' => 'posts.create'])
        ->expectsOutputToContain('Invalid subject format')
        ->assertExitCode(1);
});

it('explains a scoped permission check', function (): void {
    config()->set('marque.trace', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'editor', 'group::5');

    $this->artisan('marque:explain', [
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
    config()->set('marque.trace', true);

    $roleStore = app(RoleStore::class);
    $roleStore->save('viewer', 'Viewer', ['posts.read']);

    $assignmentStore = app(AssignmentStore::class);
    $assignmentStore->assign('user', '42', 'viewer');

    $this->artisan('marque:explain', ['subject' => 'user::42', 'permission' => 'posts.read'])
        ->expectsOutputToContain('Cache hit')
        ->assertSuccessful();
});

// --- marque:import ---

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

    $this->artisan('marque:import', ['path' => $path])
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

    $this->artisan('marque:import', ['path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('Permissions created: 2')
        ->expectsOutputToContain('Roles created: 1')
        ->assertSuccessful();

    expect(app(PermissionStore::class)->exists('posts.create'))->toBeFalse();
    expect(app(RoleStore::class)->find('editor'))->toBeNull();

    unlink($path);
});

it('shows error when import file not found', function (): void {
    $this->artisan('marque:import', ['path' => '/tmp/nonexistent-policy-file.json'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(1);
});

it('rejects structurally invalid document during import', function (): void {
    $document = [
        'permissions' => [123],
        'roles' => 'not-an-array',
    ];

    $path = tempnam(sys_get_temp_dir(), 'policy_');
    file_put_contents($path, json_encode($document));

    $this->artisan('marque:import', ['path' => $path])
        ->expectsOutputToContain('Document validation failed')
        ->assertExitCode(1);

    unlink($path);
});

it('catches RuntimeException during import', function (): void {
    $roleStore = app(RoleStore::class);
    $roleStore->save('protected', 'Protected Role', ['posts.create'], system: true);

    config()->set('marque.protect_system_roles', false);

    $document = [
        'version' => '1.0',
        'permissions' => ['posts.create'],
        'roles' => [
            ['id' => 'protected', 'name' => 'Hacked', 'permissions' => ['posts.create']],
        ],
    ];

    $path = tempnam(sys_get_temp_dir(), 'policy_');
    file_put_contents($path, json_encode($document));

    // Mock the RoleStore to throw RuntimeException (simulating system role protection at store level)
    $mockRoleStore = Mockery::mock(RoleStore::class);
    $mockRoleStore->shouldReceive('find')->andReturn($roleStore->find('protected'));
    $mockRoleStore->shouldReceive('save')->andThrow(new RuntimeException('Cannot modify system role'));
    app()->instance(RoleStore::class, $mockRoleStore);

    $this->artisan('marque:import', ['path' => $path])
        ->expectsOutputToContain('Import failed:')
        ->assertExitCode(1);

    unlink($path);
});

// --- marque:export ---

it('exports authorization state to stdout', function (): void {
    $permissionStore = app(PermissionStore::class);
    $permissionStore->register(['posts.create', 'posts.delete']);

    $roleStore = app(RoleStore::class);
    $roleStore->save('editor', 'Editor', ['posts.create', 'posts.delete']);

    $exitCode = Artisan::call('marque:export');
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

    $this->artisan('marque:export', ['--path' => $path])
        ->expectsOutputToContain("Exported to {$path}")
        ->assertSuccessful();

    $contents = file_get_contents($path);
    $decoded = json_decode($contents, true);

    expect($decoded)->toHaveKey('version', '2.0');
    expect($decoded['permissions'])->toContain('posts.create');
    expect($decoded['roles'])->toHaveKey('editor');

    unlink($path);
});

it('rejects export path outside configured document directory', function (): void {
    $storagePath = storage_path();

    if (! is_dir($storagePath)) {
        mkdir($storagePath, 0755, true);
    }

    config()->set('marque.document_path', $storagePath);

    $path = sys_get_temp_dir().'/policy-export-'.uniqid('', true).'.json';

    $this->artisan('marque:export', ['--path' => $path])
        ->expectsOutputToContain('Export failed:')
        ->assertExitCode(1);

    expect(file_exists($path))->toBeFalse();
});

// --- marque:validate ---

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

    $this->artisan('marque:validate', ['path' => $path])
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

    $this->artisan('marque:validate', ['path' => $path])
        ->expectsOutputToContain('invalid')
        ->assertExitCode(1);

    unlink($path);
});

it('shows error when validate file not found', function (): void {
    $this->artisan('marque:validate', ['path' => '/tmp/nonexistent-policy-file.json'])
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

    $this->artisan('marque:export', ['--scope' => 'group::5'])
        ->expectsOutputToContain('editor')
        ->assertSuccessful();
});

// --- marque:cache-clear ---

it('clears the policy engine cache using tags when supported', function (): void {
    config()->set('marque.cache.store', 'array');

    $this->artisan('marque:cache-clear')
        ->expectsOutputToContain('tagged flush')
        ->assertSuccessful();
});

it('clears the policy engine cache via generation counter on untaggable store', function (): void {
    config()->set('marque.cache.store', 'file');

    $this->artisan('marque:cache-clear')
        ->expectsOutputToContain('generation counter incremented')
        ->assertSuccessful();
});

// --- marque:sync ---

it('runs the sync command and handles missing seeder gracefully', function (): void {
    $this->artisan('marque:sync')
        ->expectsOutputToContain('Failed to sync')
        ->assertExitCode(1);
});

it('runs the sync command successfully with a valid seeder', function (): void {
    // Define the PermissionSeeder in the namespace db:seed expects
    if (! class_exists('Database\Seeders\PermissionSeeder', false)) {
        eval('namespace Database\Seeders; class PermissionSeeder extends \Illuminate\Database\Seeder { public function run(): void {} }');
    }

    $this->artisan('marque:sync')
        ->expectsOutputToContain('sync completed')
        ->assertSuccessful();
});

it('sync command uses the configured seeder class', function (): void {
    if (! class_exists('Database\Seeders\CustomPolicySeeder', false)) {
        eval('namespace Database\Seeders; class CustomPolicySeeder extends \Illuminate\Database\Seeder { public function run(): void {} }');
    }

    config()->set('marque.seeder_class', 'CustomPolicySeeder');

    $this->artisan('marque:sync')
        ->expectsOutputToContain('sync completed')
        ->assertSuccessful();
});

it('sync command fails gracefully when configured seeder class does not exist', function (): void {
    config()->set('marque.seeder_class', 'NonExistentSeeder');

    $this->artisan('marque:sync')
        ->expectsOutputToContain('Failed to sync')
        ->assertExitCode(1);
});
