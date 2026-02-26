<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\Scopeable;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A minimal Eloquent model for testing the Scopeable trait.
 */
class TestGroup extends Model
{
    use Scopeable;

    protected $table = 'test_groups';

    protected $guarded = [];

    protected string $scopeType = 'group';
}

/**
 * A minimal subject model for creating assignments against the scope.
 */
class TestScopeableUser extends Model
{
    protected $table = 'test_scopeable_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('test_groups', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('test_scopeable_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $assignmentStore = new EloquentAssignmentStore;
    $roleStore = new EloquentRoleStore;

    app()->instance(AssignmentStore::class, $assignmentStore);

    $this->assignmentStore = $assignmentStore;
    $this->roleStore = $roleStore;

    $this->group = TestGroup::query()->create(['name' => 'Engineering']);
});

afterEach(function (): void {
    Schema::dropIfExists('test_scopeable_users');
    Schema::dropIfExists('test_groups');
});

// --- toScope ---

it('returns the correct scope string format', function (): void {
    expect($this->group->toScope())->toBe('group::'.$this->group->getKey());
});

it('includes the scopeType and primary key in the scope string', function (): void {
    $anotherGroup = TestGroup::query()->create(['name' => 'Marketing']);

    expect($anotherGroup->toScope())->toBe('group::'.$anotherGroup->getKey())
        ->and($anotherGroup->toScope())->not->toBe($this->group->toScope());
});

// --- members ---

it('returns all assignments within the scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $userA = TestScopeableUser::query()->create(['name' => 'Alice']);
    $userB = TestScopeableUser::query()->create(['name' => 'Bob']);

    $this->assignmentStore->assign($userA->getMorphClass(), $userA->getKey(), 'editor', $this->group->toScope());
    $this->assignmentStore->assign($userB->getMorphClass(), $userB->getKey(), 'editor', $this->group->toScope());

    $members = $this->group->members();

    expect($members)->toHaveCount(2);
});

it('returns empty collection when no members exist in the scope', function (): void {
    $members = $this->group->members();

    expect($members)->toHaveCount(0)
        ->and($members)->toBeInstanceOf(\Illuminate\Support\Collection::class);
});

it('does not include assignments from other scopes', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $user = TestScopeableUser::query()->create(['name' => 'Alice']);
    $otherGroup = TestGroup::query()->create(['name' => 'Marketing']);

    $this->assignmentStore->assign($user->getMorphClass(), $user->getKey(), 'editor', $this->group->toScope());
    $this->assignmentStore->assign($user->getMorphClass(), $user->getKey(), 'editor', $otherGroup->toScope());

    expect($this->group->members())->toHaveCount(1)
        ->and($otherGroup->members())->toHaveCount(1);
});

// --- membersWithRole ---

it('returns only assignments matching the given role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);

    $userA = TestScopeableUser::query()->create(['name' => 'Alice']);
    $userB = TestScopeableUser::query()->create(['name' => 'Bob']);

    $this->assignmentStore->assign($userA->getMorphClass(), $userA->getKey(), 'editor', $this->group->toScope());
    $this->assignmentStore->assign($userB->getMorphClass(), $userB->getKey(), 'viewer', $this->group->toScope());

    $editors = $this->group->membersWithRole('editor');
    $viewers = $this->group->membersWithRole('viewer');

    expect($editors)->toHaveCount(1)
        ->and((int) $editors->first()->subject_id)->toBe($userA->getKey())
        ->and($viewers)->toHaveCount(1)
        ->and((int) $viewers->first()->subject_id)->toBe($userB->getKey());
});

it('returns empty collection when no members have the specified role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $user = TestScopeableUser::query()->create(['name' => 'Alice']);
    $this->assignmentStore->assign($user->getMorphClass(), $user->getKey(), 'editor', $this->group->toScope());

    expect($this->group->membersWithRole('viewer'))->toHaveCount(0)
        ->and($this->group->membersWithRole('viewer'))->toBeInstanceOf(\Illuminate\Support\Collection::class);
});

it('does not include assignments from other scopes when filtering by role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $user = TestScopeableUser::query()->create(['name' => 'Alice']);
    $otherGroup = TestGroup::query()->create(['name' => 'Marketing']);

    $this->assignmentStore->assign($user->getMorphClass(), $user->getKey(), 'editor', $this->group->toScope());
    $this->assignmentStore->assign($user->getMorphClass(), $user->getKey(), 'editor', $otherGroup->toScope());

    expect($this->group->membersWithRole('editor'))->toHaveCount(1)
        ->and($otherGroup->membersWithRole('editor'))->toHaveCount(1);
});
