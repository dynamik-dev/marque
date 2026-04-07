<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Enums\EvaluationResult;
use DynamikDev\PolicyEngine\Evaluators\CachedEvaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use DynamikDev\PolicyEngine\Listeners\InvalidatePermissionCache;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
    $this->assignmentStore = new EloquentAssignmentStore;
    $this->boundaryStore = new EloquentBoundaryStore;

    $this->inner = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    $this->cacheManager = app(CacheManager::class);

    $this->evaluator = new CachedEvaluator(
        inner: $this->inner,
        cache: $this->cacheManager,
    );

    config()->set('policy-engine.cache.enabled', true);
    config()->set('policy-engine.cache.store', 'array');
    config()->set('policy-engine.cache.ttl', 3600);
});

// --- Cache miss: delegates to inner evaluator and caches ---

it('resolves permissions on cache miss and caches the result', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    // Verify the result was cached per permission (tagged store for array driver).
    $cacheKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.create');
    $cached = $this->cacheManager->store('array')->tags(['policy-engine'])->get($cacheKey);

    expect($cached)->toBeTrue();
});

// --- Cache hit: serves from cache without inner evaluation ---

it('serves from cache on subsequent calls', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // First call populates cache.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    // Now revoke the role directly in DB — cache should still return true.
    $this->assignmentStore->revoke('App\\Models\\User', 1, 'editor');

    // Without invalidation, the cached result persists.
    // Manually re-set the cached value in the tagged store (array driver supports tags).
    $cacheKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.create');
    $this->cacheManager->store('array')->tags(['policy-engine'])->put($cacheKey, true, 3600);

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

// --- Cache invalidation on assignment change ---

it('invalidates cache when an assignment is created', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    // Pre-populate cache with a deny result in the tagged store (no assignments yet).
    $cacheKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.create');
    $this->cacheManager->store('array')->tags(['policy-engine'])->put($cacheKey, false, 3600);

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeFalse();

    // Create assignment and fire listener manually.
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $assignment = Assignment::query()->first();

    $listener = new InvalidatePermissionCache($this->cacheManager);
    $listener->handle(new AssignmentCreated($assignment));

    // Cache should be cleared — next call re-evaluates.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

it('invalidates cache when an assignment is revoked', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // Populate cache.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    // Revoke and fire listener.
    $assignment = Assignment::query()->first();
    $this->assignmentStore->revoke('App\\Models\\User', 1, 'editor');

    $listener = new InvalidatePermissionCache($this->cacheManager);
    $listener->handle(new AssignmentRevoked($assignment));

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeFalse();
});

// --- Cache invalidation on role change ---

it('invalidates cache when a role is updated', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // Populate cache — posts.delete should be denied.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeFalse();

    // Update role to include posts.delete.
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.delete']);
    $role = Role::query()->find('editor');

    $listener = new InvalidatePermissionCache($this->cacheManager);
    $listener->handle(new RoleUpdated($role, ['permissions' => ['posts.create', 'posts.delete']]));

    // Cache cleared — now should allow.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeTrue();
});

it('invalidates cache when a role is deleted', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // Populate cache.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    // Revoke assignment first, then delete the role.
    // (SQLite does not enforce FK cascades by default.)
    $this->assignmentStore->revoke('App\\Models\\User', 1, 'editor');
    $role = Role::query()->find('editor');
    $this->roleStore->remove('editor');

    $listener = new InvalidatePermissionCache($this->cacheManager);
    $listener->handle(new RoleDeleted($role));

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeFalse();
});

// --- Cache disabled config ---

it('bypasses cache when cache is disabled', function (): void {
    config()->set('policy-engine.cache.enabled', false);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    // Verify nothing was cached.
    $cacheKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.create');
    $cached = $this->cacheManager->store('array')->get($cacheKey);

    expect($cached)->toBeNull();
});

// --- Explain and effectivePermissions delegate to inner ---

it('delegates explain() to inner evaluator', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.create');

    expect($trace->result)->toBe(EvaluationResult::Allow)
        ->and($trace->cacheHit)->toBeFalse();
});

it('delegates effectivePermissions() to inner evaluator', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toContain('posts.create', 'posts.read');
});

// --- Scoped cache key ---

it('uses separate cache keys for different scopes', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->roleStore->save('org-admin', 'Org Admin', ['posts.create', 'posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'team-editor', 'team::5');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'org-admin', 'org::acme');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:team::5'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.delete:team::5'))->toBeFalse()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.delete:org::acme'))->toBeTrue();

    // Verify separate cache keys exist for different permission+scope combos (tagged store).
    $teamCreateKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.create:team::5');
    $orgDeleteKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.delete:org::acme');

    expect($this->cacheManager->store('array')->tags(['policy-engine'])->get($teamCreateKey))->toBeTrue()
        ->and($this->cacheManager->store('array')->tags(['policy-engine'])->get($orgDeleteKey))->toBeTrue();
});
