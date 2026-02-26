<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
