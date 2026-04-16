<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\DocumentExporter;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\MarqueManager;
use DynamikDev\Marque\Resolvers\BoundaryPolicyResolver;
use DynamikDev\Marque\Stores\CachingBoundaryStore;
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

    config()->set('marque.cache.enabled', true);
    config()->set('marque.cache.store', 'array');
    config()->set('marque.cache.ttl', 3600);
    config()->set('marque.enforce_boundaries_on_global', true);

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

it('fires at most 2 boundary queries for 100 sequential canDo() checks with enforce_boundaries_on_global', function (): void {
    $user = BoundaryCacheUser::query()->create(['name' => 'Alice']);

    $user->assign('editor', 'org::acme');

    $queryCount = 0;
    DB::listen(function ($query) use (&$queryCount): void {
        if (str_contains($query->sql, 'boundaries')) {
            $queryCount++;
        }
    });

    for ($i = 0; $i < 100; $i++) {
        $user->canDo('posts.create', 'org::acme');
    }

    // The CachingBoundaryStore caches the all() result; boundary queries should be minimal.
    expect($queryCount)->toBeLessThanOrEqual(2);
});

it('refreshes cached boundaries after a BoundarySet event fires', function (): void {
    $user = BoundaryCacheUser::query()->create(['name' => 'Bob']);

    $user->assign('editor', 'org::acme');

    // Initially can do posts.create within org::acme (no tight boundary yet).
    expect($user->canDo('posts.create', 'org::acme'))->toBeTrue();

    // Set boundary that restricts to only posts.read — BoundarySet event should flush cache.
    $this->boundaryStore->set('org::acme', ['posts.read']);

    // Cache should be invalidated, posts.create should now be denied.
    expect($user->canDo('posts.create', 'org::acme'))->toBeFalse()
        ->and($user->canDo('posts.read', 'org::acme'))->toBeTrue();
});

it('refreshes cached boundaries after a BoundaryRemoved event fires', function (): void {
    $user = BoundaryCacheUser::query()->create(['name' => 'Carol']);

    $user->assign('editor', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.read']);

    // posts.create is denied by boundary.
    expect($user->canDo('posts.create', 'org::acme'))->toBeFalse();

    // Remove boundary — BoundaryRemoved event should flush cache.
    $this->boundaryStore->remove('org::acme');

    // posts.create should be allowed again.
    expect($user->canDo('posts.create', 'org::acme'))->toBeTrue();
});

it('skips boundary cache when cache is disabled', function (): void {
    config()->set('marque.cache.enabled', false);

    $user = BoundaryCacheUser::query()->create(['name' => 'Dave']);

    $user->assign('editor', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.read']);

    // Even with cache disabled, boundary enforcement should work.
    expect($user->canDo('posts.create', 'org::acme'))->toBeFalse()
        ->and($user->canDo('posts.read', 'org::acme'))->toBeTrue();
});

it('caches boundary lookups for canDo with enforce_boundaries_on_global', function (): void {
    $user = BoundaryCacheUser::query()->create(['name' => 'Eve']);

    $user->assign('editor');

    /* With enforce_boundaries_on_global=true, combined ceiling is posts.* (org::acme and org::beta seeds). posts.create is within posts.* so allowed; posts.delete is not in any boundary. */
    expect($user->canDo('posts.read'))->toBeTrue()
        ->and($user->canDo('posts.create'))->toBeTrue();

    // posts.delete is not in any ceiling — should be denied.
    expect($user->canDo('posts.delete'))->toBeFalse();
});

it('binds BoundaryStore to CachingBoundaryStore so all consumers (manager, exporter, resolver) share the cache', function (): void {
    // Container binding wraps the Eloquent store in CachingBoundaryStore.
    expect(app(BoundaryStore::class))->toBeInstanceOf(CachingBoundaryStore::class);

    // The MarqueManager constructor receives the same cached instance.
    $manager = app(MarqueManager::class);
    $managerBoundaries = (new ReflectionClass($manager))->getProperty('boundaries')->getValue($manager);
    expect($managerBoundaries)->toBeInstanceOf(CachingBoundaryStore::class)
        ->and($managerBoundaries)->toBe(app(BoundaryStore::class));

    // The DocumentExporter receives the same cached instance.
    $exporter = app(DocumentExporter::class);
    $exporterBoundaries = (new ReflectionClass($exporter))->getProperty('boundaryStore')->getValue($exporter);
    expect($exporterBoundaries)->toBeInstanceOf(CachingBoundaryStore::class)
        ->and($exporterBoundaries)->toBe(app(BoundaryStore::class));

    // BoundaryPolicyResolver no longer wraps inline — it receives the container's cached store.
    $resolver = app(BoundaryPolicyResolver::class);
    $resolverBoundaries = (new ReflectionClass($resolver))->getProperty('boundaries')->getValue($resolver);
    expect($resolverBoundaries)->toBeInstanceOf(CachingBoundaryStore::class)
        ->and($resolverBoundaries)->toBe(app(BoundaryStore::class));
});

it('invalidates the boundary cache for ALL consumers when a boundary is mutated', function (): void {
    $user = BoundaryCacheUser::query()->create(['name' => 'Frank']);
    $user->assign('editor', 'org::acme');

    // Warm the cache via the resolver path (canDo uses BoundaryPolicyResolver -> BoundaryStore).
    expect($user->canDo('posts.create', 'org::acme'))->toBeTrue();

    // Warm the cache via a direct facade-style read (manager uses the same cached store).
    $managerCountBefore = app(BoundaryStore::class)->all()->count();
    expect($managerCountBefore)->toBeGreaterThan(0);

    // Mutate via the same cached store — BoundarySet event should flush the cache for everyone.
    $this->boundaryStore->set('org::acme', ['posts.read']);

    // Resolver consumer sees the new ceiling immediately.
    expect($user->canDo('posts.create', 'org::acme'))->toBeFalse();

    // Manager/exporter consumer also sees the new ceiling (no stale all() result).
    $newAcme = app(BoundaryStore::class)->find('org::acme');
    expect($newAcme)->not->toBeNull();
    expect($newAcme?->max_permissions)->toBe(['posts.read']);

    // Removal also cascades to all consumers.
    $this->boundaryStore->remove('org::acme');
    expect($user->canDo('posts.create', 'org::acme'))->toBeTrue()
        ->and(app(BoundaryStore::class)->find('org::acme'))->toBeNull();
});
