<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\Scopeable;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Exceptions\RoleNotFoundException;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Models\Role;
use DynamikDev\Marque\Support\RoleBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class RoleSugarTestUser extends Model
{
    protected $table = 'role_sugar_test_users';

    protected $guarded = [];
}

class RoleSugarTestTeam extends Model
{
    use Scopeable;

    protected $table = 'role_sugar_test_teams';

    protected $guarded = [];

    protected string $scopeType = 'team';
}

beforeEach(function (): void {
    Schema::create('role_sugar_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('role_sugar_test_teams', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
});

afterEach(function (): void {
    Schema::dropIfExists('role_sugar_test_teams');
    Schema::dropIfExists('role_sugar_test_users');
});

// --- createRole ---

it('creates a role and returns a RoleBuilder via createRole()', function (): void {
    $builder = Marque::createRole('editor', 'Editor');

    expect($builder)->toBeInstanceOf(RoleBuilder::class)
        ->and($this->roleStore->find('editor'))->not->toBeNull()
        ->and($this->roleStore->find('editor')->name)->toBe('Editor');
});

it('creates a system role via createRole()', function (): void {
    Marque::createRole('super-admin', 'Super Admin', system: true);

    expect($this->roleStore->find('super-admin')->is_system)->toBeTrue();
});

// --- getRole ---

it('returns Role model when found via getRole()', function (): void {
    $this->roleStore->save('editor', 'Editor', []);

    $role = Marque::getRole('editor');

    expect($role)->toBeInstanceOf(Role::class)
        ->and($role->id)->toBe('editor')
        ->and($role->name)->toBe('Editor');
});

it('returns null when role not found via getRole()', function (): void {
    expect(Marque::getRole('nonexistent'))->toBeNull();
});

// --- role (builder handle) ---

it('returns a RoleBuilder for an existing role via role()', function (): void {
    $this->roleStore->save('editor', 'Editor', []);

    expect(Marque::role('editor'))->toBeInstanceOf(RoleBuilder::class);
});

it('throws RoleNotFoundException for a missing role via role()', function (): void {
    Marque::role('nonexistent');
})->throws(RoleNotFoundException::class, 'Role [nonexistent] not found.');

// --- RoleBuilder::permissions ---

it('returns string array of permissions via role()->permissions()', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);

    $permissions = Marque::role('editor')->permissions();

    expect($permissions)->toBeArray()
        ->and($permissions)->toContain('posts.create', 'posts.read')
        ->and($permissions)->toHaveCount(2);
});

// --- RoleBuilder::assignTo ---

it('assigns role to a subject globally via assignTo()', function (): void {
    $this->roleStore->save('editor', 'Editor', []);
    $user = RoleSugarTestUser::query()->create(['name' => 'Alice']);

    Marque::role('editor')->assignTo($user);

    $assignments = $this->assignmentStore->forSubject($user->getMorphClass(), $user->getKey());

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->role_id)->toBe('editor')
        ->and($assignments->first()->scope)->toBeNull();
});

it('assigns role to a subject with a Scopeable model scope via assignTo()', function (): void {
    $this->roleStore->save('editor', 'Editor', []);
    $user = RoleSugarTestUser::query()->create(['name' => 'Alice']);
    $team = RoleSugarTestTeam::query()->create(['name' => 'Engineering']);

    Marque::role('editor')->assignTo($user, scope: $team);

    $assignments = $this->assignmentStore->forSubjectInScope(
        $user->getMorphClass(),
        $user->getKey(),
        $team->toScope(),
    );

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->role_id)->toBe('editor')
        ->and($assignments->first()->scope)->toBe('team::'.$team->getKey());
});

it('assigns role to a subject with a raw string scope via assignTo()', function (): void {
    $this->roleStore->save('editor', 'Editor', []);
    $user = RoleSugarTestUser::query()->create(['name' => 'Alice']);

    Marque::role('editor')->assignTo($user, scope: 'team::1');

    $assignments = $this->assignmentStore->forSubjectInScope(
        $user->getMorphClass(),
        $user->getKey(),
        'team::1',
    );

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->role_id)->toBe('editor')
        ->and($assignments->first()->scope)->toBe('team::1');
});

// --- RoleBuilder::revokeFrom ---

it('revokes role from a subject globally via revokeFrom()', function (): void {
    $this->roleStore->save('editor', 'Editor', []);
    $user = RoleSugarTestUser::query()->create(['name' => 'Alice']);

    $this->assignmentStore->assign($user->getMorphClass(), $user->getKey(), 'editor');

    Marque::role('editor')->revokeFrom($user);

    expect($this->assignmentStore->forSubject($user->getMorphClass(), $user->getKey()))->toHaveCount(0);
});

it('revokes role from a subject with a Scopeable model scope via revokeFrom()', function (): void {
    $this->roleStore->save('editor', 'Editor', []);
    $user = RoleSugarTestUser::query()->create(['name' => 'Alice']);
    $team = RoleSugarTestTeam::query()->create(['name' => 'Engineering']);

    $this->assignmentStore->assign($user->getMorphClass(), $user->getKey(), 'editor', $team->toScope());

    Marque::role('editor')->revokeFrom($user, scope: $team);

    expect($this->assignmentStore->forSubjectInScope(
        $user->getMorphClass(),
        $user->getKey(),
        $team->toScope(),
    ))->toHaveCount(0);
});

// --- method chaining ---

it('chains createRole with grant and assignTo', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $user = RoleSugarTestUser::query()->create(['name' => 'Alice']);

    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create', 'posts.read'])
        ->assignTo($user);

    expect($this->roleStore->permissionsFor('editor'))
        ->toContain('posts.create', 'posts.read')
        ->toHaveCount(2);

    $assignments = $this->assignmentStore->forSubject($user->getMorphClass(), $user->getKey());

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->role_id)->toBe('editor');
});
