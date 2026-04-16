<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Resolvers\BoundaryPolicyResolver;
use DynamikDev\Marque\Resolvers\SanctumPolicyResolver;
use DynamikDev\Marque\Stores\CachingPermissionStore;
use DynamikDev\Marque\Stores\EloquentPermissionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class PermissionCacheUser extends Model
{
    use HasPermissions;

    protected $table = 'permission_cache_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('permission_cache_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('marque.cache.enabled', true);
    config()->set('marque.cache.store', 'array');
    config()->set('marque.cache.ttl', 3600);
    config()->set('marque.enforce_boundaries_on_global', true);

    app(PermissionStore::class)->register(['posts.create', 'posts.read', 'posts.delete']);
    app(RoleStore::class)->save('editor', 'Editor', ['posts.create', 'posts.read']);
    app(BoundaryStore::class)->set('org::acme', ['posts.*']);
});

afterEach(function (): void {
    Schema::dropIfExists('permission_cache_users');
});

it('binds PermissionStore to CachingPermissionStore so all consumers share the cache', function (): void {
    expect(app(PermissionStore::class))->toBeInstanceOf(CachingPermissionStore::class);

    $boundaryResolver = app(BoundaryPolicyResolver::class);
    $resolverPermissions = (new ReflectionClass($boundaryResolver))->getProperty('permissionStore')->getValue($boundaryResolver);
    expect($resolverPermissions)->toBeInstanceOf(CachingPermissionStore::class)
        ->and($resolverPermissions)->toBe(app(PermissionStore::class));

    $sanctumResolver = app(SanctumPolicyResolver::class);
    $sanctumPermissions = (new ReflectionClass($sanctumResolver))->getProperty('permissionStore')->getValue($sanctumResolver);
    expect($sanctumPermissions)->toBeInstanceOf(CachingPermissionStore::class)
        ->and($sanctumPermissions)->toBe(app(PermissionStore::class));
});

it('wraps an EloquentPermissionStore as the inner store', function (): void {
    $store = app(PermissionStore::class);
    $inner = (new ReflectionClass($store))->getProperty('inner')->getValue($store);
    expect($inner)->toBeInstanceOf(EloquentPermissionStore::class);
});

it('serves the second all() call from cache without hitting the database', function (): void {
    $store = app(PermissionStore::class);

    // Warm cache.
    $first = $store->all();
    expect($first)->toHaveCount(3);

    $queryCount = 0;
    DB::listen(function ($query) use (&$queryCount): void {
        if (str_contains($query->sql, 'permissions')) {
            $queryCount++;
        }
    });

    for ($i = 0; $i < 10; $i++) {
        $store->all();
    }

    expect($queryCount)->toBe(0);
});

it('fires at most 1 permission query for many sequential canDo() checks involving a scope', function (): void {
    $user = PermissionCacheUser::query()->create(['name' => 'Alice']);
    $user->assign('editor', 'org::acme');

    // Warm-up call ensures any unrelated permission query (e.g., schema introspection) is excluded.
    $user->canDo('posts.create', 'org::acme');

    $queryCount = 0;
    DB::listen(function ($query) use (&$queryCount): void {
        if (str_contains($query->sql, 'from "permissions"') || str_contains($query->sql, 'from `permissions`') || str_contains($query->sql, 'from "marque_permissions"')) {
            $queryCount++;
        }
    });

    // Each canDo() with a fresh action invalidates the per-eval cache, so BoundaryPolicyResolver
    // re-runs and would normally call permissionStore->all() each time.
    for ($i = 0; $i < 50; $i++) {
        $user->canDo('posts.create', 'org::acme');
        $user->canDo('posts.read', 'org::acme');
        $user->canDo('posts.delete', 'org::acme');
    }

    expect($queryCount)->toBeLessThanOrEqual(1);
});

it('invalidates the cache after PermissionCreated so new permissions are visible', function (): void {
    $store = app(PermissionStore::class);

    expect($store->all())->toHaveCount(3);

    $store->register('posts.publish');

    $refreshed = $store->all();
    expect($refreshed)->toHaveCount(4)
        ->and($refreshed->pluck('id')->all())->toContain('posts.publish');
});

it('invalidates the cache after PermissionDeleted so removed permissions disappear', function (): void {
    $store = app(PermissionStore::class);

    expect($store->all())->toHaveCount(3);

    $store->remove('posts.delete');

    $refreshed = $store->all();
    expect($refreshed)->toHaveCount(2)
        ->and($refreshed->pluck('id')->all())->not->toContain('posts.delete');
});

it('does not cache prefix-filtered all() calls', function (): void {
    $store = app(PermissionStore::class);

    // Warm any unfiltered cache so the only queries we count are prefix lookups.
    $store->all();

    $queryCount = 0;
    DB::listen(function ($query) use (&$queryCount): void {
        if (str_contains($query->sql, 'permissions')) {
            $queryCount++;
        }
    });

    $store->all('posts');
    $store->all('posts');

    expect($queryCount)->toBe(2);
});

it('skips cache when marque.cache.enabled is false', function (): void {
    config()->set('marque.cache.enabled', false);

    $store = app(PermissionStore::class);

    // First call populates nothing; both calls hit the database.
    $store->all();

    $queryCount = 0;
    DB::listen(function ($query) use (&$queryCount): void {
        if (str_contains($query->sql, 'permissions')) {
            $queryCount++;
        }
    });

    $store->all();
    $store->all();

    expect($queryCount)->toBe(2);
});

it('issues a single permission query across 200 cached all() calls with several hundred registered permissions', function (): void {
    $store = app(PermissionStore::class);

    /* Register 300 additional permissions to make the per-eval cost meaningful. */
    $bulk = collect(range(1, 300))->map(fn (int $i): string => "bulk.permission.{$i}")->all();
    $store->register($bulk);

    /* Warm cache (this is the only DB hit we expect from now on, until invalidation). */
    expect($store->all())->toHaveCount(303);

    $queryCount = 0;
    DB::listen(function ($query) use (&$queryCount): void {
        if (str_contains($query->sql, 'from "permissions"') || str_contains($query->sql, 'from `permissions`') || str_contains($query->sql, 'from "marque_permissions"')) {
            $queryCount++;
        }
    });

    for ($i = 0; $i < 200; $i++) {
        $store->all();
    }

    /* Without caching this would be 200 SELECT queries. With caching it must be 0. */
    expect($queryCount)->toBe(0);
});

it('returns identical results from cached and uncached calls after registration', function (): void {
    $store = app(PermissionStore::class);

    $beforeIds = $store->all()->pluck('id')->sort()->values()->all();
    expect($beforeIds)->toBe(['posts.create', 'posts.delete', 'posts.read']);

    $store->register(['posts.archive', 'posts.publish']);

    $afterIds = $store->all()->pluck('id')->sort()->values()->all();
    expect($afterIds)->toBe(['posts.archive', 'posts.create', 'posts.delete', 'posts.publish', 'posts.read']);
});
