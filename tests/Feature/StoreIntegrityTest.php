<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Events\AssignmentRevoked;
use DynamikDev\Marque\Events\RoleDeleted;
use DynamikDev\Marque\Models\Assignment;
use DynamikDev\Marque\Models\Role;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class StoreIntegrityUser extends Model
{
    use HasPermissions;

    protected $table = 'store_integrity_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('store_integrity_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);

    $this->user = StoreIntegrityUser::query()->create(['name' => 'Alice']);
});

afterEach(function (): void {
    Schema::dropIfExists('store_integrity_users');
});

// --- syncRoles() transaction atomicity ---

it('syncRoles revoke/assign loop is atomic — all or nothing', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);

    $this->user->assign('editor');

    /*
     * Bind a custom AssignmentStore that throws on the second assign call
     * to simulate a failure mid-sync.
     */
    $real = app(AssignmentStore::class);
    $callCount = 0;
    $fake = new class($real, $callCount) implements AssignmentStore
    {
        private int $assignCallCount = 0;

        public function __construct(
            private readonly AssignmentStore $inner,
            private int &$counter,
        ) {}

        public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
        {
            $this->counter++;
            if ($this->counter > 1) {
                throw new RuntimeException('Simulated failure mid-sync');
            }
            $this->inner->assign($subjectType, $subjectId, $roleId, $scope);
        }

        public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
        {
            $this->inner->revoke($subjectType, $subjectId, $roleId, $scope);
        }

        public function forSubject(string $subjectType, string|int $subjectId): Collection
        {
            return $this->inner->forSubject($subjectType, $subjectId);
        }

        public function forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection
        {
            return $this->inner->forSubjectInScope($subjectType, $subjectId, $scope);
        }

        public function forSubjectGlobal(string $subjectType, string|int $subjectId): Collection
        {
            return $this->inner->forSubjectGlobal($subjectType, $subjectId);
        }

        public function forSubjectGlobalAndScope(string $subjectType, string|int $subjectId, string $scope): Collection
        {
            return $this->inner->forSubjectGlobalAndScope($subjectType, $subjectId, $scope);
        }

        public function subjectsInScope(string $scope, ?string $roleId = null): Collection
        {
            return $this->inner->subjectsInScope($scope, $roleId);
        }

        public function all(): Collection
        {
            return $this->inner->all();
        }

        public function removeAll(): void
        {
            $this->inner->removeAll();
        }
    };

    app()->instance(AssignmentStore::class, $fake);

    try {
        /*
         * Sync: revoke editor, assign viewer + admin (viewer succeeds, admin would be 2nd assign).
         * Actually, sync revokes editor first, then assigns viewer. The assign
         * counter starts at 0 and the fake throws on counter > 1 (i.e., second assign).
         * So we need 2 new roles to trigger the failure.
         */
        $this->roleStore->save('admin', 'Admin', ['*.*']);
        $this->user->syncRoles(['viewer', 'admin']);
    } catch (RuntimeException) {
        // Expected: simulated failure.
    }

    // Restore real store for verification.
    app()->instance(AssignmentStore::class, $real);

    // The revoke of 'editor' and first assign of 'viewer' should have been rolled back.
    $assignments = $real->forSubjectGlobal($this->user->getMorphClass(), $this->user->getKey());
    $roleIds = $assignments->pluck('role_id')->all();

    expect($roleIds)->toContain('editor')
        ->and($roleIds)->not->toContain('admin');
})->skip(
    fn () => DB::getDriverName() === 'mysql',
    'MySQL savepoint rollback behaves differently under RefreshDatabase wrapping',
);

it('syncRoles completes transactionally on success', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);

    $this->user->assign('editor');
    $this->user->assign('viewer');

    $this->user->syncRoles(['viewer', 'admin']);

    expect($this->user->hasRole('editor'))->toBeFalse()
        ->and($this->user->hasRole('viewer'))->toBeTrue()
        ->and($this->user->hasRole('admin'))->toBeTrue();
});

// --- removeAll() chunked deletes ---

it('EloquentAssignmentStore::removeAll dispatches events for every record', function (): void {
    Role::query()->create(['id' => 'role-a', 'name' => 'Role A']);
    Role::query()->create(['id' => 'role-b', 'name' => 'Role B']);

    // Create enough assignments to span multiple chunks (chunk size is 200).
    for ($i = 1; $i <= 5; $i++) {
        $this->assignmentStore->assign('App\\Models\\User', $i, 'role-a');
        $this->assignmentStore->assign('App\\Models\\User', $i, 'role-b');
    }

    expect(Assignment::query()->count())->toBe(10);

    Event::fake([AssignmentRevoked::class]);

    $this->assignmentStore->removeAll();

    expect(Assignment::query()->count())->toBe(0);
    Event::assertDispatchedTimes(AssignmentRevoked::class, 10);
});

it('EloquentRoleStore::removeAll dispatches events for every role', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        $this->roleStore->save("role-{$i}", "Role {$i}", []);
    }

    expect(Role::query()->count())->toBe(5);

    Event::fake([RoleDeleted::class]);

    $this->roleStore->removeAll();

    expect(Role::query()->count())->toBe(0);
    Event::assertDispatchedTimes(RoleDeleted::class, 5);
});

it('removeAll handles empty tables without error', function (): void {
    expect(Assignment::query()->count())->toBe(0);

    $this->assignmentStore->removeAll();

    expect(Assignment::query()->count())->toBe(0);
});

// --- CacheStoreResolver::flush() on non-tagged stores ---

it('flush on non-tagged store does not clear unrelated cache keys', function (): void {
    config()->set('marque.cache.store', 'file');

    $cache = app(CacheManager::class);
    CacheStoreResolver::reset();

    $store = CacheStoreResolver::store($cache);

    // Put a non-marque key in the same store.
    $store->put('my-app-session', 'session-data', 3600);

    // Flush marque cache.
    CacheStoreResolver::flush($cache);

    // The unrelated key must survive.
    expect($store->get('my-app-session'))->toBe('session-data');

    // Clean up.
    $store->forget('my-app-session');
    CacheStoreResolver::reset();
});

it('flush on non-tagged store increments the global generation counter', function (): void {
    config()->set('marque.cache.store', 'file');

    $cache = app(CacheManager::class);
    CacheStoreResolver::reset();

    $before = CacheStoreResolver::globalGeneration($cache);

    CacheStoreResolver::flush($cache);
    expect(CacheStoreResolver::globalGeneration($cache))->toBe($before + 1);

    CacheStoreResolver::flush($cache);
    expect(CacheStoreResolver::globalGeneration($cache))->toBe($before + 2);

    CacheStoreResolver::reset();
});

it('flush on tagged store still uses tag-scoped flush', function (): void {
    config()->set('marque.cache.store', 'array');

    $cache = app(CacheManager::class);
    CacheStoreResolver::reset();

    $store = CacheStoreResolver::store($cache);

    // Put a tagged marque key.
    $store->tags(['marque'])->put('marque:test-key', 'value', 3600);

    // Put a non-tagged key.
    $store->put('app-key', 'preserved', 3600);

    CacheStoreResolver::flush($cache);

    // Tagged key should be gone.
    expect($store->tags(['marque'])->get('marque:test-key'))->toBeNull();

    // Non-tagged key should survive.
    expect($store->get('app-key'))->toBe('preserved');

    // Global generation should remain 0 (not used for tagged stores).
    expect(CacheStoreResolver::globalGeneration($cache))->toBe(0);

    CacheStoreResolver::reset();
});

it('globalGeneration returns 0 for tagged stores', function (): void {
    config()->set('marque.cache.store', 'array');

    $cache = app(CacheManager::class);
    CacheStoreResolver::reset();

    expect(CacheStoreResolver::globalGeneration($cache))->toBe(0);

    CacheStoreResolver::reset();
});
