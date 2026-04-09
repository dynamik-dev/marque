<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class BoundaryCacheUser extends Model
{
    use HasPermissions;

    protected $table = 'boundary_cache_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('boundary_cache_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('policy-engine.cache.enabled', true);
    config()->set('policy-engine.cache.store', 'array');
    config()->set('policy-engine.cache.ttl', 3600);
    config()->set('policy-engine.enforce_boundaries_on_global', true);

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
    $this->boundaryStore = app(BoundaryStore::class);
    $this->evaluator = app(Evaluator::class);

    // Seed base data: permissions, roles, boundaries, and a user with a global assignment.
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->boundaryStore->set('org::acme', ['posts.*']);
    $this->boundaryStore->set('org::beta', ['posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
});

afterEach(function (): void {
    Schema::dropIfExists('boundary_cache_users');
});

// All tests in this file require BoundaryPolicyResolver (Task 2.1).
// They are skipped until that resolver is implemented.

it('fires at most 2 boundary queries for 100 sequential can() checks with enforce_boundaries_on_global', function (): void {
    // BoundaryPolicyResolver not yet implemented (Task 2.1).
})->skip('BoundaryPolicyResolver not yet implemented (Task 2.1)');

it('refreshes cached boundaries after a BoundarySet event fires', function (): void {
    // BoundaryPolicyResolver not yet implemented (Task 2.1).
})->skip('BoundaryPolicyResolver not yet implemented (Task 2.1)');

it('refreshes cached boundaries after a BoundaryRemoved event fires', function (): void {
    // BoundaryPolicyResolver not yet implemented (Task 2.1).
})->skip('BoundaryPolicyResolver not yet implemented (Task 2.1)');

it('skips boundary cache when cache is disabled', function (): void {
    // BoundaryPolicyResolver not yet implemented (Task 2.1).
})->skip('BoundaryPolicyResolver not yet implemented (Task 2.1)');

it('caches boundary lookups for effectivePermissions with enforce_boundaries_on_global', function (): void {
    // BoundaryPolicyResolver not yet implemented (Task 2.1).
})->skip('BoundaryPolicyResolver not yet implemented (Task 2.1)');
