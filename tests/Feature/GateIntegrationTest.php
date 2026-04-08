<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Concerns\Scopeable;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class GateTestUser extends Authenticatable
{
    use HasPermissions;

    protected $table = 'gate_test_users';

    protected $guarded = [];
}

class GateTestUserWithoutTrait extends Authenticatable
{
    protected $table = 'gate_test_users';

    protected $guarded = [];
}

class GateTestTeam extends Model
{
    use Scopeable;

    protected string $scopeType = 'team';

    protected $table = 'gate_test_teams';

    protected $guarded = [];
}

/**
 * A policy that always denies — proves the Gate hook defers to it.
 */
class GateTestAlwaysDenyPolicy
{
    public function view(GateTestUser $user, GateTestTeam $team): bool
    {
        return false;
    }
}

/**
 * A policy that calls canDo() internally — proves engine + policy composition.
 */
class GateTestCanDoPolicy
{
    public function update(GateTestUser $user, GateTestTeam $team): bool
    {
        return $user->canDo('teams.update', $team);
    }
}

beforeEach(function (): void {
    Schema::create('gate_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('gate_test_teams', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);

    $this->user = GateTestUser::query()->create(['name' => 'Alice']);
});

afterEach(function (): void {
    Schema::dropIfExists('gate_test_teams');
    Schema::dropIfExists('gate_test_users');
});

// --- Basic Gate integration ---

it('allows $user->can() for a dot-notated permission the user has', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->can('posts.create'))->toBeTrue();
});

it('denies $user->cannot() for a permission the user lacks', function (): void {
    expect($this->user->cannot('posts.delete'))->toBeTrue();
});

// --- Scoped permissions ---

it('allows $user->can() with a scopeable model argument', function (): void {
    $team = GateTestTeam::query()->create(['name' => 'Team A']);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', $team);

    expect($this->user->can('posts.create', $team))->toBeTrue();
});

it('allows $user->can() with a string scope argument', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    expect($this->user->can('posts.create', 'team::5'))->toBeTrue();
});

// --- Deny rules ---

it('enforces deny rules through the Gate', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.delete', '!posts.delete']);
    $this->user->assign('editor');

    expect($this->user->can('posts.create'))->toBeTrue()
        ->and($this->user->can('posts.delete'))->toBeFalse();
});

// --- Non-dot abilities are not intercepted ---

it('does not intercept non-dot abilities so Gate::define still works', function (): void {
    Gate::define('admin-dashboard', fn (): bool => true);

    $this->actingAs($this->user);

    expect($this->user->can('admin-dashboard'))->toBeTrue();
});

// --- Wildcard permissions ---

it('supports wildcard permissions through the Gate', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.update', 'posts.delete']);
    $this->roleStore->save('super-editor', 'Super Editor', ['posts.*']);
    $this->user->assign('super-editor');

    expect($this->user->can('posts.create'))->toBeTrue()
        ->and($this->user->can('posts.update'))->toBeTrue()
        ->and($this->user->can('posts.delete'))->toBeTrue();
});

// --- @can Blade directive ---

it('renders @can Blade directive correctly with gate integration', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user);

    $html = trim(Blade::render(<<<'BLADE'
        @can("posts.create")
            VISIBLE
        @endcan
        BLADE));

    expect($html)->toBe('VISIBLE');
});

// --- User without HasPermissions trait ---

it('abstains for user models without HasPermissions trait', function (): void {
    $userWithoutTrait = GateTestUserWithoutTrait::query()->create(['name' => 'Bob']);

    // Without trait and without a Gate definition, should deny
    expect($userWithoutTrait->cannot('posts.create'))->toBeTrue();
});

// --- gate_passthrough config ---

it('skips passthrough abilities so other Gate definitions can handle them', function (): void {
    config()->set('policy-engine.gate_passthrough', ['admin.panel']);

    Gate::define('admin.panel', fn (): bool => true);

    $this->actingAs($this->user);

    expect($this->user->can('admin.panel'))->toBeTrue();
});

// --- Two-lane authorization: dot-notated = engine, non-dot = policy ---

it('handles dot-notated abilities through the engine even when a policy exists', function (): void {
    Gate::policy(GateTestTeam::class, GateTestAlwaysDenyPolicy::class);

    $team = GateTestTeam::query()->create(['name' => 'Team A']);

    $this->permissionStore->register(['teams.view']);
    $this->roleStore->save('viewer', 'Viewer', ['teams.view']);
    $this->user->assign('viewer', $team);

    $this->actingAs($this->user);

    // Dot-notated: engine handles it, policy is not consulted.
    expect($this->user->can('teams.view', $team))->toBeTrue();
});

it('defers non-dot abilities to policies that call canDo internally', function (): void {
    Gate::policy(GateTestTeam::class, GateTestCanDoPolicy::class);

    $team = GateTestTeam::query()->create(['name' => 'Team A']);

    $this->permissionStore->register(['teams.update']);
    $this->roleStore->save('team-admin', 'Team Admin', ['teams.update']);
    $this->user->assign('team-admin', $team);

    $this->actingAs($this->user);

    // Non-dot 'update': policy handles it, calls canDo() internally.
    expect($this->user->can('update', $team))->toBeTrue();
});

it('policy calling canDo denies when engine denies', function (): void {
    Gate::policy(GateTestTeam::class, GateTestCanDoPolicy::class);

    $team = GateTestTeam::query()->create(['name' => 'Team A']);

    $this->permissionStore->register(['teams.update']);
    $this->roleStore->save('viewer', 'Viewer', ['teams.view']);
    $this->user->assign('viewer');

    $this->actingAs($this->user);

    // Policy calls canDo('teams.update', $team) — user lacks permission.
    expect($this->user->can('update', $team))->toBeFalse();
});
