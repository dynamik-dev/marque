<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class PipelineTestUser extends \Illuminate\Database\Eloquent\Model
{
    use HasPermissions;

    protected $table = 'pipeline_test_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('pipeline_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->boundaryStore = app(BoundaryStore::class);

    $this->user = PipelineTestUser::query()->create(['name' => 'Alice']);
});

afterEach(function (): void {
    Schema::dropIfExists('pipeline_test_users');
});

it('allows a user with member role to do posts.create', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.update']);
    $this->roleStore->save('member', 'Member', ['posts.create', 'posts.read', 'posts.update']);
    $this->user->assign('member');

    expect($this->user->canDo('posts.create'))->toBeTrue();
});

it('denies a user with member role from doing members.remove', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('member', 'Member', ['posts.create', 'posts.read']);
    $this->user->assign('member');

    expect($this->user->canDo('members.remove'))->toBeFalse();
});

it('denies when moderator role has deny rule that overrides allow', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete.any']);
    $this->roleStore->save('moderator', 'Moderator', ['posts.*', '!posts.delete.any']);
    $this->user->assign('moderator');

    expect($this->user->canDo('posts.create'))->toBeTrue()
        ->and($this->user->canDo('posts.delete.any'))->toBeFalse();
});

it('allows admin with wildcard *.* to do anything', function (): void {
    $this->permissionStore->register(['posts.create', 'members.remove', 'billing.manage', 'settings.update']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);
    $this->user->assign('admin');

    expect($this->user->canDo('posts.create'))->toBeTrue()
        ->and($this->user->canDo('members.remove'))->toBeTrue()
        ->and($this->user->canDo('billing.manage'))->toBeTrue()
        ->and($this->user->canDo('settings.update'))->toBeTrue();
});

it('allows scoped permission when user has assignment in that scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('group-editor', 'Group Editor', ['posts.create']);
    $this->user->assign('group-editor', 'group::5');

    expect($this->user->canDo('posts.create', 'group::5'))->toBeTrue();
});

it('denies scoped permission when user has assignment in a different scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('group-editor', 'Group Editor', ['posts.create']);
    $this->user->assign('group-editor', 'group::5');

    expect($this->user->canDo('posts.create', 'group::99'))->toBeFalse();
});

it('allows scoped permission via global assignment', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->canDo('posts.create', 'group::5'))->toBeTrue()
        ->and($this->user->canDo('posts.create', 'team::99'))->toBeTrue();
});

it('denies when boundary restricts wildcard permissions in scope', function (): void {
    $this->permissionStore->register(['posts.create', 'members.invite']);
    $this->roleStore->save('super', 'Super', ['*.*']);
    $this->user->assign('super', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    expect($this->user->canDo('posts.create', 'org::acme'))->toBeTrue()
        ->and($this->user->canDo('members.invite', 'org::acme'))->toBeFalse();
});

it('allows when permission is within boundary ceiling', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    expect($this->user->canDo('posts.create', 'org::acme'))->toBeTrue();
});

it('denies when subject has no assignments', function (): void {
    $this->permissionStore->register(['posts.create']);

    expect($this->user->canDo('posts.create'))->toBeFalse();
});
