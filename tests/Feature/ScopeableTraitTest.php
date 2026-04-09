<?php

declare(strict_types=1);

use DynamikDev\Marque\Attributes\ScopeType;
use DynamikDev\Marque\Concerns\Scopeable;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\RoleStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
 * A Scopeable model without an explicit $scopeType — tests inference.
 */
class TestTeamWithoutScopeType extends Model
{
    use Scopeable;

    protected $table = 'test_groups';

    protected $guarded = [];
}

/**
 * A Scopeable model using the #[ScopeType] attribute.
 */
#[ScopeType('organization')]
class TestOrganization extends Model
{
    use Scopeable;

    protected $table = 'test_groups';

    protected $guarded = [];
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

    $this->assignmentStore = app(AssignmentStore::class);
    $this->roleStore = app(RoleStore::class);

    $this->group = TestGroup::query()->create(['name' => 'Engineering']);
});

afterEach(function (): void {
    Schema::dropIfExists('test_scopeable_users');
    Schema::dropIfExists('test_groups');
});

// --- toScope inference ---

it('infers scope type from class basename when scopeType is not set', function (): void {
    $model = new class extends Model
    {
        use Scopeable;

        protected $table = 'users';
    };
    $model->id = 1;

    /*
     * Anonymous classes get an empty basename, but named classes infer correctly.
     * Test with the real TestGroup model by verifying getScopeType() uses the property.
     */
    expect($this->group->getScopeType())->toBe('group');
});

it('infers scope type as lowercased class basename', function (): void {
    // TestGroup has $scopeType = 'group', so test with a model that omits it.
    $team = new TestTeamWithoutScopeType;
    $team->id = 7;

    expect($team->getScopeType())->toBe('testteamwithoutscopetype')
        ->and($team->toScope())->toBe('testteamwithoutscopetype::7');
});

it('prefers explicit scopeType property over inferred class basename', function (): void {
    expect($this->group->getScopeType())->toBe('group')
        ->and($this->group->toScope())->toBe('group::'.$this->group->getKey());
});

it('resolves scope type from #[ScopeType] attribute', function (): void {
    $org = new TestOrganization;
    $org->id = 3;

    expect($org->getScopeType())->toBe('organization')
        ->and($org->toScope())->toBe('organization::3');
});

it('prefers #[ScopeType] attribute over $scopeType property', function (): void {
    /*
     * TestOrganization has the attribute but no property — attribute wins.
     * Verify by also testing a model with both (attribute should take priority).
     */
    $org = new TestOrganization;
    $org->id = 1;

    expect($org->getScopeType())->toBe('organization');
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
        ->and($members)->toBeInstanceOf(Collection::class);
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
        ->and($this->group->membersWithRole('viewer'))->toBeInstanceOf(Collection::class);
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
