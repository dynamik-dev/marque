<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
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

    // Assign the role. The store dispatches AssignmentCreated,
    // which the service provider's listener handles to flush the cache.
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

    // Revoke the role. The store dispatches AssignmentRevoked,
    // which triggers cache invalidation via the event listener.
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

    // Update the role to include posts.delete. The store dispatches RoleUpdated,
    // which triggers cache invalidation via the event listener.
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

    // Delete the permission. The store dispatches PermissionDeleted,
    // which triggers cache invalidation via the event listener.
    // This also removes the role_permissions row for posts.delete.
    $this->permissionStore->remove('posts.delete');

    // Subsequent canDo should reflect the deleted permission.
    expect($this->user->canDo('posts.delete'))->toBeFalse();

    // Other permissions remain unaffected.
    expect($this->user->canDo('posts.create'))->toBeTrue();
});
