<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PermissionStore::class, new EloquentPermissionStore);
    app()->instance(RoleStore::class, new EloquentRoleStore);
    app()->instance(AssignmentStore::class, new EloquentAssignmentStore);
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
