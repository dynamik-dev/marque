<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // Bind all contracts to concrete implementations for trait resolution.
    $assignmentStore = new EloquentAssignmentStore;
    $roleStore = new EloquentRoleStore;
    $boundaryStore = new EloquentBoundaryStore;
    $matcher = new WildcardMatcher;
    $scopeResolver = new ModelScopeResolver;
    $evaluator = new DefaultEvaluator(
        assignments: $assignmentStore,
        roles: $roleStore,
        boundaries: $boundaryStore,
        matcher: $matcher,
    );

    app()->instance(AssignmentStore::class, $assignmentStore);
    app()->instance(RoleStore::class, $roleStore);
    app()->instance(BoundaryStore::class, $boundaryStore);
    app()->instance(Matcher::class, $matcher);
    app()->instance(ScopeResolver::class, $scopeResolver);
    app()->instance(Evaluator::class, $evaluator);

    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = $roleStore;
    $this->assignmentStore = $assignmentStore;

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

// --- roles / rolesFor ---

it('returns unique roles for the subject', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['admin.access']);
    $this->user->assign('editor');
    $this->user->assign('admin');

    $roles = $this->user->roles();

    expect($roles)->toHaveCount(2)
        ->and($roles->pluck('id')->sort()->values()->all())->toBe(['admin', 'editor']);
});

it('deduplicates roles across scopes', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');
    $this->user->assign('editor', 'team::5');

    expect($this->user->roles())->toHaveCount(1)
        ->and($this->user->roles()->first()->id)->toBe('editor');
});

it('returns roles filtered by scope', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['admin.access']);
    $this->user->assign('editor');
    $this->user->assign('admin', 'org::acme');

    $roles = $this->user->rolesFor('org::acme');

    expect($roles)->toHaveCount(1)
        ->and($roles->first()->id)->toBe('admin');
});

it('returns empty collection when subject has no roles', function (): void {
    expect($this->user->roles())->toHaveCount(0)
        ->and($this->user->roles())->toBeInstanceOf(\Illuminate\Support\Collection::class);
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
        ->result->toBe('allow');
});

it('returns deny trace when permission is not granted', function (): void {
    config()->set('policy-engine.explain', true);

    $trace = $this->user->explain('posts.delete');

    expect($trace)
        ->toBeInstanceOf(EvaluationTrace::class)
        ->result->toBe('deny');
});

it('returns scoped explain trace', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    $trace = $this->user->explain('posts.create', 'team::5');

    expect($trace)
        ->result->toBe('allow')
        ->assignments->toHaveCount(1);
});

it('throws RuntimeException when explain mode is disabled', function (): void {
    config()->set('policy-engine.explain', false);

    $this->user->explain('posts.create');
})->throws(\RuntimeException::class);

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
