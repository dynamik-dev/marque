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
use Illuminate\Support\Facades\DB;
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

// --- Core: boundary queries are cached across sequential can() checks ---

it('fires at most 2 boundary queries for 100 sequential can() checks with enforce_boundaries_on_global', function (): void {
    // Warm any framework-internal queries (e.g., migration state) so they don't pollute the log.
    $this->evaluator->can('App\\Models\\User', 1, 'posts.create');

    // Reset the query log and count only boundary-table queries.
    DB::enableQueryLog();
    DB::flushQueryLog();

    for ($i = 0; $i < 100; $i++) {
        $this->evaluator->can('App\\Models\\User', 1, 'posts.create');
    }

    $queries = DB::getQueryLog();

    /** @var string $boundariesTable */
    $boundariesTable = config('policy-engine.table_prefix', '').'boundaries';

    $boundaryQueries = array_filter(
        $queries,
        fn (array $query): bool => str_contains($query['query'], $boundariesTable),
    );

    /*
     * The first can() call above (warm-up) may trigger one boundary query
     * that gets cached. Subsequent 100 calls should all serve from cache.
     * Allow up to 2 for edge cases (e.g., two code paths: evaluateBoundary + resolveBoundaryMaxPermissions).
     */
    expect(count($boundaryQueries))->toBeLessThanOrEqual(2);

    DB::disableQueryLog();
});

// --- Boundary cache is invalidated when a boundary changes ---

it('refreshes cached boundaries after a BoundarySet event fires', function (): void {
    // User can do posts.create globally because at least one boundary allows posts.*.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    /*
     * Now narrow ALL boundaries so that posts.create is no longer allowed anywhere.
     * BoundarySet events fire and flush the entire policy-engine cache.
     */
    $this->boundaryStore->set('org::acme', ['billing.*']);
    $this->boundaryStore->set('org::beta', ['billing.*']);

    // The cached boundary collection should be gone. Next can() re-fetches from DB.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeFalse();
});

// --- Boundary cache is invalidated when a boundary is removed ---

it('refreshes cached boundaries after a BoundaryRemoved event fires', function (): void {
    // Start with two boundaries that block billing.manage globally.
    $this->roleStore->save('admin', 'Admin', ['billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 2, 'admin');
    $this->permissionStore->register(['billing.manage']);

    // Neither boundary's max_permissions includes billing.manage, so it's denied.
    expect($this->evaluator->can('App\\Models\\User', 2, 'billing.manage'))->toBeFalse();

    // Remove all boundaries — with no boundaries, global enforcement passes.
    $this->boundaryStore->remove('org::acme');
    $this->boundaryStore->remove('org::beta');

    expect($this->evaluator->can('App\\Models\\User', 2, 'billing.manage'))->toBeTrue();
});

// --- Boundary cache bypassed when cache is disabled ---

it('skips boundary cache when cache is disabled', function (): void {
    config()->set('policy-engine.cache.enabled', false);

    // Should still work correctly, just without caching.
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();

    DB::enableQueryLog();
    DB::flushQueryLog();

    // Each call hits the DB directly (no caching).
    for ($i = 0; $i < 5; $i++) {
        $this->evaluator->can('App\\Models\\User', 1, 'posts.create');
    }

    $queries = DB::getQueryLog();

    /** @var string $boundariesTable */
    $boundariesTable = config('policy-engine.table_prefix', '').'boundaries';

    $boundaryQueries = array_filter(
        $queries,
        fn (array $query): bool => str_contains($query['query'], $boundariesTable),
    );

    // Without cache, each can() call should query boundaries.
    expect(count($boundaryQueries))->toBeGreaterThanOrEqual(5);

    DB::disableQueryLog();
});

// --- effectivePermissions also benefits from cached boundaries ---

it('caches boundary lookups for effectivePermissions with enforce_boundaries_on_global', function (): void {
    // Warm the cache with one call.
    $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    DB::enableQueryLog();
    DB::flushQueryLog();

    for ($i = 0; $i < 50; $i++) {
        $this->evaluator->effectivePermissions('App\\Models\\User', 1);
    }

    $queries = DB::getQueryLog();

    /** @var string $boundariesTable */
    $boundariesTable = config('policy-engine.table_prefix', '').'boundaries';

    $boundaryQueries = array_filter(
        $queries,
        fn (array $query): bool => str_contains($query['query'], $boundariesTable),
    );

    /*
     * effectivePermissions is itself cached by CachedEvaluator, but even if it
     * falls through, the boundary lookup is cached by CachingBoundaryStore.
     */
    expect(count($boundaryQueries))->toBeLessThanOrEqual(2);

    DB::disableQueryLog();
});
