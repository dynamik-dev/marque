<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Enums\EvaluationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A minimal Eloquent model for testing the HasPermissions trait.
 */
class TestUser extends Model
{
    use HasPermissions;

    protected $table = 'test_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);

    $this->user = TestUser::query()->create(['name' => 'Alice']);
});

afterEach(function (): void {
    Schema::dropIfExists('test_users');
});

// --- canDo ---

it('returns true when the subject has the permission', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->canDo('posts.create'))->toBeTrue();
});

it('returns false when the subject lacks the permission', function (): void {
    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->user->assign('viewer');

    expect($this->user->canDo('posts.delete'))->toBeFalse();
});

it('evaluates scoped permissions via canDo', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    expect($this->user->canDo('posts.create', 'team::5'))->toBeTrue()
        ->and($this->user->canDo('posts.create', 'team::99'))->toBeFalse();
});

// --- cannotDo ---

it('returns true for cannotDo when permission is missing', function (): void {
    expect($this->user->cannotDo('posts.delete'))->toBeTrue();
});

it('returns false for cannotDo when permission is granted', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->cannotDo('posts.create'))->toBeFalse();
});

// --- assign / revoke ---

it('assigns a role to the subject', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->assignments())->toHaveCount(1)
        ->and($this->user->assignments()->first()->role_id)->toBe('editor');
});

it('assigns a scoped role to the subject', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor', 'team::5');

    expect($this->user->assignmentsFor('team::5'))->toHaveCount(1)
        ->and($this->user->assignmentsFor('team::5')->first()->scope)->toBe('team::5');
});

it('revokes a role from the subject', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->assignments())->toHaveCount(1);

    $this->user->revoke('editor');

    expect($this->user->assignments())->toHaveCount(0);
});

it('revokes a scoped role from the subject', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor', 'team::5');
    $this->user->assign('editor');

    $this->user->revoke('editor', 'team::5');

    expect($this->user->assignments())->toHaveCount(1)
        ->and($this->user->assignments()->first()->scope)->toBeNull();
});

// --- assignments / assignmentsFor ---

it('returns all assignments for the subject', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['admin.access']);
    $this->user->assign('editor');
    $this->user->assign('admin', 'org::acme');

    expect($this->user->assignments())->toHaveCount(2);
});

it('returns assignments filtered by scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['admin.access']);
    $this->user->assign('editor');
    $this->user->assign('admin', 'org::acme');

    expect($this->user->assignmentsFor('org::acme'))->toHaveCount(1)
        ->and($this->user->assignmentsFor('org::acme')->first()->role_id)->toBe('admin');
});

// --- getRoles / getRolesFor ---

it('returns unique roles for the subject', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['admin.access']);
    $this->user->assign('editor');
    $this->user->assign('admin');

    $roles = $this->user->getRoles();

    expect($roles)->toHaveCount(2)
        ->and($roles->pluck('id')->sort()->values()->all())->toBe(['admin', 'editor']);
});

it('deduplicates roles across scopes', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');
    $this->user->assign('editor', 'team::5');

    expect($this->user->getRoles())->toHaveCount(1)
        ->and($this->user->getRoles()->first()->id)->toBe('editor');
});

it('returns roles filtered by scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['admin.access']);
    $this->user->assign('editor');
    $this->user->assign('admin', 'org::acme');

    $roles = $this->user->getRolesFor('org::acme');

    expect($roles)->toHaveCount(1)
        ->and($roles->first()->id)->toBe('admin');
});

it('returns empty collection when subject has no roles', function (): void {
    expect($this->user->getRoles())->toHaveCount(0)
        ->and($this->user->getRoles())->toBeInstanceOf(Collection::class);
});

// --- hasRole ---

it('returns true when user has the role (unscoped)', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->hasRole('editor'))->toBeTrue();
});

it('returns false when user does not have the role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->hasRole('admin'))->toBeFalse();
});

it('returns true when user has role in the given scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor', 'team::5');

    expect($this->user->hasRole('editor', 'team::5'))->toBeTrue();
});

it('returns false when user has role in a different scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor', 'team::5');

    expect($this->user->hasRole('editor', 'team::99'))->toBeFalse();
});

it('returns false for unscoped hasRole when role is only scoped', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor', 'team::5');

    expect($this->user->hasRole('editor'))->toBeFalse();
});

it('returns false for scoped hasRole when role is only global', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    expect($this->user->hasRole('editor', 'team::5'))->toBeFalse();
});

// --- effectivePermissions ---

it('returns all effective permissions for the subject', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.delete']);
    $this->user->assign('editor');

    expect($this->user->effectivePermissions())
        ->toContain('posts.create', 'posts.read', 'posts.delete')
        ->toHaveCount(3);
});

it('excludes denied permissions from effective set', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->user->assign('editor');
    $this->user->assign('restricted');

    expect($this->user->effectivePermissions())
        ->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.delete');
});

it('returns scoped effective permissions', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('viewer');
    $this->user->assign('team-editor', 'team::5');

    expect($this->user->effectivePermissions('team::5'))
        ->toContain('posts.read', 'posts.create');
});

it('returns empty array when subject has no assignments', function (): void {
    expect($this->user->effectivePermissions())->toBe([]);
});

// --- explain ---

it('returns an EvaluationTrace via explain', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $trace = $this->user->explain('posts.create');

    expect($trace)
        ->toBeInstanceOf(EvaluationTrace::class)
        ->subject->toBe(TestUser::class.':'.$this->user->getKey())
        ->required->toBe('posts.create')
        ->result->toBe(EvaluationResult::Allow);
});

it('returns deny trace when permission is not granted', function (): void {
    config()->set('policy-engine.explain', true);

    $trace = $this->user->explain('posts.delete');

    expect($trace)
        ->toBeInstanceOf(EvaluationTrace::class)
        ->result->toBe(EvaluationResult::Deny);
});

it('returns scoped explain trace', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    $trace = $this->user->explain('posts.create', 'team::5');

    expect($trace)
        ->result->toBe(EvaluationResult::Allow)
        ->assignments->toHaveCount(1);
});

it('throws RuntimeException when explain mode is disabled', function (): void {
    config()->set('policy-engine.explain', false);

    $this->user->explain('posts.create');
})->throws(RuntimeException::class);

// --- wildcard permissions ---

it('supports wildcard permissions via canDo', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.update']);
    $this->roleStore->save('editor', 'Editor', ['posts.*']);
    $this->user->assign('editor');

    expect($this->user->canDo('posts.create'))->toBeTrue()
        ->and($this->user->canDo('posts.read'))->toBeTrue()
        ->and($this->user->canDo('comments.create'))->toBeFalse();
});

// --- deny wins over allow ---

it('denies when an explicit deny rule overrides an allow', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.*']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->user->assign('editor');
    $this->user->assign('restricted');

    expect($this->user->canDo('posts.create'))->toBeTrue()
        ->and($this->user->canDo('posts.delete'))->toBeFalse();
});

// --- givePermission / revokePermission ---

it('gives a permission directly without an explicit role', function (): void {
    $this->permissionStore->register(['posts.create']);

    $this->user->givePermission('posts.create');

    expect($this->user->canDo('posts.create'))->toBeTrue();
});

it('revokes a directly given permission', function (): void {
    $this->permissionStore->register(['posts.create']);

    $this->user->givePermission('posts.create');
    expect($this->user->canDo('posts.create'))->toBeTrue();

    $this->user->revokePermission('posts.create');
    expect($this->user->canDo('posts.create'))->toBeFalse();
});

it('throws when giving an unregistered permission', function (): void {
    $this->user->givePermission('nonexistent.perm');
})->throws(InvalidArgumentException::class, 'not registered');

it('hides direct permission roles from getRoles', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $this->user->assign('editor');
    $this->user->givePermission('posts.create');

    $roles = $this->user->getRoles();

    expect($roles)->toHaveCount(1)
        ->and($roles->first()->id)->toBe('editor');
});

it('gives a scoped direct permission', function (): void {
    $this->permissionStore->register(['posts.create']);

    $this->user->givePermission('posts.create', 'team::5');

    expect($this->user->canDo('posts.create', 'team::5'))->toBeTrue()
        ->and($this->user->canDo('posts.create'))->toBeFalse();
});

// --- syncRoles ---

it('syncs roles by revoking removed and assigning new', function (): void {
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

it('syncs scoped roles without affecting global assignments', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);

    $this->user->assign('admin');
    $this->user->assign('editor', 'team::5');

    $this->user->syncRoles(['viewer'], 'team::5');

    // Global admin untouched.
    expect($this->user->hasRole('admin'))->toBeTrue();
    // Scoped editor replaced with viewer.
    expect($this->user->hasRole('editor', 'team::5'))->toBeFalse()
        ->and($this->user->hasRole('viewer', 'team::5'))->toBeTrue();
});

it('syncRoles does not revoke direct permission roles', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);

    $this->user->givePermission('posts.create');
    $this->user->assign('editor');

    $this->user->syncRoles(['viewer']);

    expect($this->user->canDo('posts.create'))->toBeTrue()
        ->and($this->user->hasRole('editor'))->toBeFalse()
        ->and($this->user->hasRole('viewer'))->toBeTrue();
});

it('syncs to empty array revokes all roles in scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $this->user->assign('editor');

    $this->user->syncRoles([]);

    expect($this->user->hasRole('editor'))->toBeFalse();
});

// --- hasAnyRole / hasAllRoles ---

it('hasAnyRole returns true when user has at least one role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);

    $this->user->assign('editor');

    expect($this->user->hasAnyRole(['admin', 'editor']))->toBeTrue();
});

it('hasAnyRole returns false when user has none of the roles', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $this->user->assign('editor');

    expect($this->user->hasAnyRole(['admin', 'super']))->toBeFalse();
});

it('hasAllRoles returns true when user has every role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);

    $this->user->assign('editor');
    $this->user->assign('viewer');

    expect($this->user->hasAllRoles(['editor', 'viewer']))->toBeTrue();
});

it('hasAllRoles returns false when user is missing one role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);

    $this->user->assign('editor');

    expect($this->user->hasAllRoles(['editor', 'viewer']))->toBeFalse();
});

it('hasAnyRole works with scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $this->user->assign('editor', 'team::5');

    expect($this->user->hasAnyRole(['editor'], 'team::5'))->toBeTrue()
        ->and($this->user->hasAnyRole(['editor'], 'team::99'))->toBeFalse();
});

it('hasAllRoles returns false for an empty array', function (): void {
    expect($this->user->hasAllRoles([]))->toBeFalse();
});

// --- assignmentsFor null scope ---

it('throws InvalidArgumentException when assignmentsFor receives a null scope', function (): void {
    $this->user->assignmentsFor(null);
})->throws(InvalidArgumentException::class, 'Scope could not be resolved.');
