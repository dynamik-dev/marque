<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Enums\EvaluationResult;
use DynamikDev\PolicyEngine\Evaluators\CachedEvaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use DynamikDev\PolicyEngine\Listeners\InvalidatePermissionCache;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Role;
use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
    $this->boundaryStore = app(BoundaryStore::class);

    $this->inner = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: app(Matcher::class),
    );

    $this->cacheManager = app(CacheManager::class);

    $this->evaluator = new CachedEvaluator(
        inner: $this->inner,
        cache: $this->cacheManager,
    );

    config()->set('policy-engine.cache.enabled', true);
    config()->set('policy-engine.cache.store', 'array');
    config()->set('policy-engine.cache.ttl', 300);
});

// --- Cache miss: delegates to inner evaluator and caches ---

it('resolves permissions on cache miss and caches the result', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    // Verify the result was cached per permission (tagged store for array driver).
    $cacheKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.create');
    $cached = $this->cacheManager->store('array')->tags(['policy-engine', 'pe:App\\Models\\User:1'])->get($cacheKey);

    expect($cached)->toBe(['v' => true]);
});

// --- Cache hit: serves from cache without inner evaluation ---

it('serves from cache on subsequent calls', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // First call populates cache.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    // Delete assignment directly in DB to avoid triggering cache invalidation events.
    Assignment::query()
        ->where('subject_type', 'App\\Models\\User')
        ->where('subject_id', 1)
        ->delete();

    // Second call should still return true from cache despite DB change.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

// --- Denied permission is served from cache, not re-evaluated ---

it('caches denied permissions and returns false from cache without re-evaluating', function (): void {
    $this->permissionStore->register(['posts.delete']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'viewer');

    // Build a spy that counts calls to the inner evaluator.
    $callCount = 0;
    $spy = new class($this->inner, $callCount) implements Evaluator
    {
        public function __construct(
            private readonly Evaluator $delegate,
            private int &$callCount,
        ) {}

        public function can(string $subjectType, string|int $subjectId, string $permission): bool
        {
            $this->callCount++;

            return $this->delegate->can($subjectType, $subjectId, $permission);
        }

        public function explain(string $subjectType, string|int $subjectId, string $permission): EvaluationTrace
        {
            return $this->delegate->explain($subjectType, $subjectId, $permission);
        }

        public function effectivePermissions(string $subjectType, string|int $subjectId, ?string $scope = null): array
        {
            return $this->delegate->effectivePermissions($subjectType, $subjectId, $scope);
        }

        public function hasRole(string $subjectType, string|int $subjectId, string $role, ?string $scope = null): bool
        {
            return $this->delegate->hasRole($subjectType, $subjectId, $role, $scope);
        }
    };

    $evaluator = new CachedEvaluator(inner: $spy, cache: $this->cacheManager);

    // First call: cache miss, delegates to inner evaluator.
    expect($evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeFalse();
    expect($callCount)->toBe(1);

    // Second call: cache hit, must NOT delegate to inner evaluator.
    expect($evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeFalse();
    expect($callCount)->toBe(1);
});

// --- Cache invalidation on assignment change ---

it('invalidates cache when an assignment is created', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    // Pre-populate cache with a deny result in the tagged store (no assignments yet).
    $cacheKey = CachedEvaluator::cacheKey('App\\Models\\User', 1, 'posts.create');
    $this->cacheManager->store('array')->tags(['policy-engine', 'pe:App\\Models\\User:1'])->put($cacheKey, ['v' => false], 3600);

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

// --- hasRole caching ---

it('caches hasRole results', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->hasRole('App\\Models\\User', 1, 'editor'))->toBeTrue();

    // Delete assignment directly to avoid cache invalidation events.
    Assignment::query()->delete();

    // Should still return true from cache.
    expect($this->evaluator->hasRole('App\\Models\\User', 1, 'editor'))->toBeTrue();
});

it('invalidates hasRole cache on assignment revoke', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->hasRole('App\\Models\\User', 1, 'editor'))->toBeTrue();

    // Revoke via store (fires invalidation events).
    $assignment = Assignment::query()->first();
    $this->assignmentStore->revoke('App\\Models\\User', 1, 'editor');

    $listener = new InvalidatePermissionCache($this->cacheManager);
    $listener->handle(new AssignmentRevoked($assignment));

    expect($this->evaluator->hasRole('App\\Models\\User', 1, 'editor'))->toBeFalse();
});

// --- effectivePermissions caching ---

it('caches effectivePermissions results', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $first = $this->evaluator->effectivePermissions('App\\Models\\User', 1);
    expect($first)->toEqualCanonicalizing(['posts.create', 'posts.read']);

    // Delete assignment directly to avoid cache invalidation.
    Assignment::query()->delete();

    // Should still return cached result.
    $second = $this->evaluator->effectivePermissions('App\\Models\\User', 1);
    expect($second)->toEqualCanonicalizing(['posts.create', 'posts.read']);
});

// --- Cache key isolation ---

it('does not collide cache keys between can, hasRole, and effectivePermissions', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // Populate all three cache types for the same subject.
    $canResult = $this->evaluator->can('App\\Models\\User', 1, 'posts.create');
    $roleResult = $this->evaluator->hasRole('App\\Models\\User', 1, 'editor');
    $effectiveResult = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($canResult)->toBeTrue()
        ->and($roleResult)->toBeTrue()
        ->and($effectiveResult)->toContain('posts.create');

    // Verify keys are distinct.
    $canKey = CachedEvaluator::key('can', 'App\\Models\\User', 1, 'posts.create');
    $roleKey = CachedEvaluator::key('role', 'App\\Models\\User', 1, 'editor');
    $effectiveKey = CachedEvaluator::key('effective', 'App\\Models\\User', 1);

    expect($canKey)->not->toBe($roleKey)
        ->and($canKey)->not->toBe($effectiveKey)
        ->and($roleKey)->not->toBe($effectiveKey);
});

// --- Scoped cache keys ---

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

    expect($this->cacheManager->store('array')->tags(['policy-engine', 'pe:App\\Models\\User:1'])->get($teamCreateKey))->toBe(['v' => true])
        ->and($this->cacheManager->store('array')->tags(['policy-engine', 'pe:App\\Models\\User:1'])->get($orgDeleteKey))->toBe(['v' => true]);
});
