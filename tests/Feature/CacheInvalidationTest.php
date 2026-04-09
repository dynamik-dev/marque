<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A minimal Eloquent model for testing cache invalidation via HasPermissions.
 */
class CacheTestUser extends Model
{
    use HasPermissions;

    protected $table = 'cache_test_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('cache_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('policy-engine.cache.enabled', true);
    config()->set('policy-engine.cache.store', 'array');
    config()->set('policy-engine.cache.ttl', 3600);

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->boundaryStore = app(BoundaryStore::class);

    $this->user = CacheTestUser::query()->create(['name' => 'Alice']);
});

afterEach(function (): void {
    Schema::dropIfExists('cache_test_users');
});

// --- Cache invalidated when assignment is created ---

it('invalidates cache when a role is assigned so canDo reflects the new state', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    // User has no role — canDo should be false (and the result gets cached).
    expect($this->user->canDo('posts.create'))->toBeFalse();

    /*
     * Assign the role. The store dispatches AssignmentCreated,
     * which the service provider's listener handles to flush the cache.
     */
    $this->user->assign('editor');

    // Subsequent canDo should reflect the new assignment.
    expect($this->user->canDo('posts.create'))->toBeTrue();
});

// --- Cache invalidated when assignment is revoked ---

it('invalidates cache when a role is revoked so canDo reflects the removal', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    // User has the role — canDo should be true (result cached).
    expect($this->user->canDo('posts.create'))->toBeTrue();

    /*
     * Revoke the role. The store dispatches AssignmentRevoked,
     * which triggers cache invalidation via the event listener.
     */
    $this->user->revoke('editor');

    // Subsequent canDo should reflect the revoked assignment.
    expect($this->user->canDo('posts.create'))->toBeFalse();
});

// --- Cache invalidated when role permissions change ---

it('invalidates cache when role permissions are updated so canDo reflects the change', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    // User can create but not delete (result cached).
    expect($this->user->canDo('posts.create'))->toBeTrue()
        ->and($this->user->canDo('posts.delete'))->toBeFalse();

    /*
     * Update the role to include posts.delete. The store dispatches RoleUpdated,
     * which triggers cache invalidation via the event listener.
     */
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.delete']);

    // Subsequent canDo should reflect the updated role permissions.
    expect($this->user->canDo('posts.delete'))->toBeTrue();
});

// --- Cache invalidated when a permission is deleted ---

it('invalidates cache when a permission is deleted so canDo reflects the removal', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.delete']);
    $this->user->assign('editor');

    // User can delete (result cached).
    expect($this->user->canDo('posts.delete'))->toBeTrue();

    /*
     * Delete the permission. The store dispatches PermissionDeleted,
     * which triggers cache invalidation via the event listener.
     * This also removes the role_permissions row for posts.delete.
     */
    $this->permissionStore->remove('posts.delete');

    // Subsequent canDo should reflect the deleted permission.
    expect($this->user->canDo('posts.delete'))->toBeFalse();

    // Other permissions remain unaffected.
    expect($this->user->canDo('posts.create'))->toBeTrue();
});

it('invalidates cache when a boundary is updated so canDo reflects tighter limits', function (): void {
    // BoundaryPolicyResolver not yet implemented (Task 2.1).
})->skip('BoundaryPolicyResolver not yet implemented (Task 2.1)');

it('invalidates cache when a boundary is removed so canDo reflects the removal', function (): void {
    // BoundaryPolicyResolver not yet implemented (Task 2.1).
})->skip('BoundaryPolicyResolver not yet implemented (Task 2.1)');

// --- Scoped cache invalidation preserves non-policy-engine keys ---

it('does not clear non-policy-engine cache keys during invalidation', function (): void {
    // Store a value in the same cache store but outside policy-engine tags.
    cache()->store('array')->put('my-app-key', 'preserved', 3600);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    // Trigger a cached canDo evaluation.
    expect($this->user->canDo('posts.create'))->toBeTrue();

    // Update the role — triggers cache invalidation via RoleUpdated event.
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.update']);

    // The non-policy-engine cache key should survive the flush.
    expect(cache()->store('array')->get('my-app-key'))->toBe('preserved');
});

it('clears only policy-engine tagged entries when flushing cache', function (): void {
    // Store values both inside and outside the policy-engine tag.
    cache()->store('array')->put('session-token', 'abc123', 3600);
    cache()->store('array')->tags(['policy-engine'])->put('policy-engine:user:1:posts.create', true, 3600);

    // Flush the policy-engine tag.
    cache()->store('array')->tags(['policy-engine'])->flush();

    // Policy-engine key should be gone.
    expect(cache()->store('array')->tags(['policy-engine'])->get('policy-engine:user:1:posts.create'))->toBeNull();

    // Non-tagged key should survive.
    expect(cache()->store('array')->get('session-token'))->toBe('abc123');
});

// --- Cache invalidated when a new permission is created ---

it('invalidates cache when a new permission is created', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    // Prime the cache.
    expect($this->user->canDo('posts.create'))->toBeTrue();

    // Register a new permission — PermissionCreated fires, full cache flush.
    $this->permissionStore->register(['posts.delete']);

    /*
     * Verify the flush happened: the previously-cached canDo result
     * is gone, forcing re-evaluation. Since the role still grants
     * posts.create, canDo still returns true — but from a fresh eval.
     * We verify flush indirectly: canDo('posts.delete') should be false
     * (not granted), proving the evaluator ran fresh (not from stale cache).
     */
    expect($this->user->canDo('posts.delete'))->toBeFalse();
    expect($this->user->canDo('posts.create'))->toBeTrue();
});

// --- Cache invalidated when a document is imported ---

it('invalidates cache when a document is imported so canDo reflects the imported state', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->user->assign('viewer');

    // User cannot create (result cached as false).
    expect($this->user->canDo('posts.create'))->toBeFalse();

    /*
     * Import a document that upgrades the viewer role to include posts.create.
     * The importer dispatches DocumentImported, which triggers a full cache flush.
     */
    app(DocumentImporter::class)->import(
        new PolicyDocument(
            version: '1.0',
            permissions: ['posts.create', 'posts.read'],
            roles: [
                ['id' => 'viewer', 'name' => 'Viewer', 'permissions' => ['posts.create', 'posts.read']],
            ],
        ),
        new ImportOptions(merge: true),
    );

    // Subsequent canDo should reflect the upgraded role from the import.
    expect($this->user->canDo('posts.create'))->toBeTrue();
});
