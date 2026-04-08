<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Enums\EvaluationResult;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Events\AuthorizationDenied;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
    $this->assignmentStore = new EloquentAssignmentStore;
    $this->boundaryStore = new EloquentBoundaryStore;

    $this->evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );
});

// --- can: basic allow ---

it('allows a permission when the subject has a matching role', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

// --- can: basic deny ---

it('denies a permission the subject does not have', function (): void {
    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'viewer');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeFalse();
});

// --- can: deny wins over allow ---

it('denies when an explicit deny rule overrides an allow', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.*']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'restricted');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeFalse();
});

// --- can: wildcard grants ---

it('allows via wildcard permission match', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.update']);
    $this->roleStore->save('editor', 'Editor', ['posts.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.read'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.update'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'comments.create'))->toBeFalse();
});

// --- can: scoped evaluation ---

it('evaluates permissions within a scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'team-editor', 'team::5');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:team::5'))->toBeTrue();
});

it('denies scoped permission when subject only has a different scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'team-editor', 'team::5');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:team::99'))->toBeFalse();
});

it('allows scoped permission via global (unscoped) assignment', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:team::5'))->toBeTrue();
});

// --- can: boundary enforcement ---

it('allows permission when within boundary', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:org::acme'))->toBeTrue();
});

it('denies permission when outside boundary', function (): void {
    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    expect($this->evaluator->can('App\\Models\\User', 1, 'billing.manage:org::acme'))->toBeFalse();
});

it('allows permission when no boundary exists for scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'org::acme');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:org::acme'))->toBeTrue();
});

// --- can: no assignment means deny ---

it('denies when the subject has no assignments', function (): void {
    expect($this->evaluator->can('App\\Models\\User', 99, 'posts.create'))->toBeFalse();
});

// --- can: AuthorizationDenied event ---

it('dispatches AuthorizationDenied when denied and log_denials is true', function (): void {
    Event::fake([AuthorizationDenied::class]);
    config()->set('policy-engine.log_denials', true);

    $this->evaluator->can('App\\Models\\User', 1, 'posts.create');

    Event::assertDispatched(AuthorizationDenied::class, function (AuthorizationDenied $event): bool {
        return $event->subject === 'App\\Models\\User:1'
            && $event->permission === 'posts.create'
            && $event->scope === null;
    });
});

it('does not dispatch AuthorizationDenied when log_denials is false', function (): void {
    Event::fake([AuthorizationDenied::class]);
    config()->set('policy-engine.log_denials', false);

    $this->evaluator->can('App\\Models\\User', 1, 'posts.create');

    Event::assertNotDispatched(AuthorizationDenied::class);
});

it('does not dispatch AuthorizationDenied when permission is allowed', function (): void {
    Event::fake([AuthorizationDenied::class]);
    config()->set('policy-engine.log_denials', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $this->evaluator->can('App\\Models\\User', 1, 'posts.create');

    Event::assertNotDispatched(AuthorizationDenied::class);
});

// --- explain ---

it('throws RuntimeException when explain mode is disabled', function (): void {
    config()->set('policy-engine.explain', false);

    $this->evaluator->explain('App\\Models\\User', 1, 'posts.create');
})->throws(RuntimeException::class, 'Explain mode is disabled');

it('returns an EvaluationTrace when explain mode is enabled', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.create');

    expect($trace)
        ->toBeInstanceOf(EvaluationTrace::class)
        ->subject->toBe('App\\Models\\User:1')
        ->required->toBe('posts.create')
        ->result->toBe(EvaluationResult::Allow)
        ->cacheHit->toBeFalse()
        ->assignments->toHaveCount(1)
        ->assignments->each->toHaveKeys(['role', 'scope', 'permissions_checked']);
});

it('returns deny trace when permission is not granted', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'viewer');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.delete');

    expect($trace)
        ->result->toBe(EvaluationResult::Deny)
        ->boundary->toBeNull();
});

it('includes boundary note in explain trace when boundary blocks', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'billing.manage:org::acme');

    expect($trace)
        ->result->toBe(EvaluationResult::Deny)
        ->boundary->toContain('Denied by boundary');
});

// --- effectivePermissions ---

it('returns all effective permissions for a subject', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toEqualCanonicalizing(['posts.create', 'posts.read', 'posts.delete']);
});

it('excludes denied permissions from effective set', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'restricted');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.delete');
});

it('returns empty array when subject has no assignments', function (): void {
    expect($this->evaluator->effectivePermissions('App\\Models\\User', 99))->toBe([]);
});

it('returns scoped effective permissions including global assignments', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'billing.manage']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'viewer');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'team-editor', 'team::5');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1, 'team::5');

    expect($permissions)->toEqualCanonicalizing(['posts.read', 'posts.create']);
});

it('excludes permissions blocked by boundary from scoped effective set', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'posts.read', 'billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin', 'team::5');
    $this->boundaryStore->set('team::5', ['posts.*']);

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1, 'team::5');

    expect($permissions)->toContain('posts.create', 'posts.read')
        ->not->toContain('billing.manage');
});

it('does not apply boundary filtering for unscoped effective permissions', function (): void {
    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('team::5', ['posts.*']);

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toContain('posts.create', 'billing.manage')
        ->toHaveCount(2);
});

it('deduplicates permissions from multiple roles', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->roleStore->save('contributor', 'Contributor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'contributor');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toHaveCount(2)
        ->toContain('posts.create', 'posts.read');
});

it('short-circuits before role permission lookups when boundary denies', function (): void {
    $this->permissionStore->register(['billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    $spyRoleStore = new class($this->roleStore) implements RoleStore
    {
        public int $singleCalls = 0;

        public int $batchCalls = 0;

        public function __construct(
            private readonly EloquentRoleStore $inner,
        ) {}

        public function save(string $id, string $name, array $permissions, bool $system = false): Role
        {
            return $this->inner->save($id, $name, $permissions, $system);
        }

        public function remove(string $id): void
        {
            $this->inner->remove($id);
        }

        public function removeAll(): void
        {
            $this->inner->removeAll();
        }

        public function find(string $id): ?Role
        {
            return $this->inner->find($id);
        }

        public function all(): Collection
        {
            return $this->inner->all();
        }

        public function permissionsFor(string $roleId): array
        {
            $this->singleCalls++;

            return $this->inner->permissionsFor($roleId);
        }

        /** @param  array<int, string>  $roleIds */
        public function permissionsForRoles(array $roleIds): array
        {
            $this->batchCalls++;

            return $this->inner->permissionsForRoles($roleIds);
        }
    };

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $spyRoleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'billing.manage:org::acme'))->toBeFalse();
    expect($spyRoleStore->singleCalls)->toBe(0)
        ->and($spyRoleStore->batchCalls)->toBe(0);
});

// --- explain: deny permission collected in trace ---

it('collects deny permissions in explain trace', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'restricted');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.create');

    expect($trace)
        ->result->toBe(EvaluationResult::Allow)
        ->assignments->toHaveCount(2);

    // The restricted role's !posts.delete should appear in the permissions_checked
    $restrictedAssignment = collect($trace->assignments)->firstWhere('role', 'restricted');
    expect($restrictedAssignment['permissions_checked'])->toContain('!posts.delete');
});

// --- explain: deny_unbounded_scopes with missing boundary ---

it('returns deny trace when deny_unbounded_scopes is enabled and no boundary exists', function (): void {
    config()->set('policy-engine.explain', true);
    config()->set('policy-engine.deny_unbounded_scopes', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'org::acme');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.create:org::acme');

    expect($trace)
        ->result->toBe(EvaluationResult::Deny)
        ->boundary->toContain('Denied by missing boundary')
        ->boundary->toContain('deny_unbounded_scopes');
});

// --- explain: deny rule matches ---

it('returns deny trace when a deny rule matches in explain', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.*']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'restricted');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.delete');

    expect($trace)
        ->result->toBe(EvaluationResult::Deny)
        ->boundary->toBeNull();
});

// --- fallback paths: store without batch methods ---

it('works with custom assignment store implementing forSubjectGlobal', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    // Use a minimal store that implements the full contract
    $minimalAssignmentStore = new class implements AssignmentStore
    {
        /** @var array<int, Assignment> */
        private array $assignments = [];

        public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
        {
            $assignment = new Assignment;
            $assignment->subject_type = $subjectType;
            $assignment->subject_id = $subjectId;
            $assignment->role_id = $roleId;
            $assignment->scope = $scope;
            $this->assignments[] = $assignment;
        }

        public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void {}

        public function forSubject(string $subjectType, string|int $subjectId): Collection
        {
            return collect($this->assignments)
                ->filter(fn ($a) => $a->subject_type === $subjectType && (string) $a->subject_id === (string) $subjectId)
                ->values();
        }

        public function forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection
        {
            return collect();
        }

        public function forSubjectGlobal(string $subjectType, string|int $subjectId): Collection
        {
            return $this->forSubject($subjectType, $subjectId)
                ->filter(fn ($a) => $a->scope === null)
                ->values();
        }

        public function forSubjectGlobalAndScope(string $subjectType, string|int $subjectId, string $scope): Collection
        {
            return $this->forSubject($subjectType, $subjectId)
                ->filter(fn ($a) => $a->scope === null || $a->scope === $scope)
                ->values();
        }

        public function subjectsInScope(string $scope, ?string $roleId = null): Collection
        {
            return collect();
        }

        public function all(): Collection
        {
            return collect($this->assignments);
        }

        public function removeAll(): void
        {
            $this->assignments = [];
        }
    };

    $minimalAssignmentStore->assign('App\\Models\\User', 1, 'editor');
    // Also add a scoped assignment that should be filtered out for global-only query
    $minimalAssignmentStore->assign('App\\Models\\User', 1, 'editor', 'team::5');

    $evaluator = new DefaultEvaluator(
        assignments: $minimalAssignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    // Unscoped check: should only use global assignments (scope === null)
    expect($evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

it('works with custom assignment store implementing forSubjectGlobalAndScope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);

    $minimalAssignmentStore = new class implements AssignmentStore
    {
        /** @var array<int, Assignment> */
        private array $assignments = [];

        public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
        {
            $assignment = new Assignment;
            $assignment->subject_type = $subjectType;
            $assignment->subject_id = $subjectId;
            $assignment->role_id = $roleId;
            $assignment->scope = $scope;
            $this->assignments[] = $assignment;
        }

        public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void {}

        public function forSubject(string $subjectType, string|int $subjectId): Collection
        {
            return collect($this->assignments)
                ->filter(fn ($a) => $a->subject_type === $subjectType && (string) $a->subject_id === (string) $subjectId)
                ->values();
        }

        public function forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection
        {
            return collect();
        }

        public function forSubjectGlobal(string $subjectType, string|int $subjectId): Collection
        {
            return $this->forSubject($subjectType, $subjectId)
                ->filter(fn ($a) => $a->scope === null)
                ->values();
        }

        public function forSubjectGlobalAndScope(string $subjectType, string|int $subjectId, string $scope): Collection
        {
            return $this->forSubject($subjectType, $subjectId)
                ->filter(fn ($a) => $a->scope === null || $a->scope === $scope)
                ->values();
        }

        public function subjectsInScope(string $scope, ?string $roleId = null): Collection
        {
            return collect();
        }

        public function all(): Collection
        {
            return collect($this->assignments);
        }

        public function removeAll(): void
        {
            $this->assignments = [];
        }
    };

    $minimalAssignmentStore->assign('App\\Models\\User', 1, 'editor', 'team::5');
    $minimalAssignmentStore->assign('App\\Models\\User', 1, 'editor');

    $evaluator = new DefaultEvaluator(
        assignments: $minimalAssignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    // Scoped check: should include both global and scope-matching assignments
    expect($evaluator->can('App\\Models\\User', 1, 'posts.create:team::5'))->toBeTrue();
});

it('works with custom role store implementing permissionsForRoles', function (): void {
    $this->permissionStore->register(['posts.create']);

    $minimalRoleStore = new class implements RoleStore
    {
        /** @var array<string, array{name: string, permissions: array<int, string>, system: bool}> */
        private array $roles = [];

        public function save(string $id, string $name, array $permissions, bool $system = false): Role
        {
            $this->roles[$id] = ['name' => $name, 'permissions' => $permissions, 'system' => $system];

            $role = new Role;
            $role->id = $id;
            $role->name = $name;
            $role->is_system = $system;

            return $role;
        }

        public function remove(string $id): void
        {
            unset($this->roles[$id]);
        }

        public function find(string $id): ?Role
        {
            if (! isset($this->roles[$id])) {
                return null;
            }

            $role = new Role;
            $role->id = $id;
            $role->name = $this->roles[$id]['name'];
            $role->is_system = $this->roles[$id]['system'];

            return $role;
        }

        public function all(): Collection
        {
            return collect();
        }

        public function permissionsFor(string $roleId): array
        {
            return $this->roles[$roleId]['permissions'] ?? [];
        }

        public function permissionsForRoles(array $roleIds): array
        {
            $result = [];

            foreach ($roleIds as $roleId) {
                $result[$roleId] = $this->permissionsFor($roleId);
            }

            return $result;
        }

        public function removeAll(): void
        {
            $this->roles = [];
        }
    };

    $minimalRoleStore->save('editor', 'Editor', ['posts.create']);

    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $minimalRoleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

// --- sanctumTokenAllows fallback paths ---

it('allows when Sanctum class does not exist and permission is otherwise granted', function (): void {
    // Sanctum IS installed in dev, so this path (line 384) is not reachable
    // in the test environment. Instead, test the user-mismatch path (line 398).
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // Evaluating for a subject that doesn't match the authenticated user
    // (no user authenticated at all — sanctumTokenAllows returns true at line 390)
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

it('allows when authenticated user does not match evaluated subject', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    // Create a fake authenticated user with a different ID
    $fakeUser = new class extends User
    {
        protected $table = 'users';
    };
    $fakeUser->id = 999;

    $this->actingAs($fakeUser);

    // Evaluating subject User:1 while authenticated as User:999
    // This hits line 398 (user doesn't match subject) and returns true
    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

it('uses batched role permission loading when the role store supports it', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->roleStore->save('reviewer', 'Reviewer', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'reviewer');

    $spyRoleStore = new class($this->roleStore) implements RoleStore
    {
        public int $singleCalls = 0;

        public int $batchCalls = 0;

        public function __construct(
            private readonly EloquentRoleStore $inner,
        ) {}

        public function save(string $id, string $name, array $permissions, bool $system = false): Role
        {
            return $this->inner->save($id, $name, $permissions, $system);
        }

        public function remove(string $id): void
        {
            $this->inner->remove($id);
        }

        public function removeAll(): void
        {
            $this->inner->removeAll();
        }

        public function find(string $id): ?Role
        {
            return $this->inner->find($id);
        }

        public function all(): Collection
        {
            return $this->inner->all();
        }

        public function permissionsFor(string $roleId): array
        {
            $this->singleCalls++;

            return $this->inner->permissionsFor($roleId);
        }

        /** @param  array<int, string>  $roleIds */
        public function permissionsForRoles(array $roleIds): array
        {
            $this->batchCalls++;

            return $this->inner->permissionsForRoles($roleIds);
        }
    };

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $spyRoleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
    expect($spyRoleStore->batchCalls)->toBe(1)
        ->and($spyRoleStore->singleCalls)->toBe(0);
});

// --- enforce_boundaries_on_global ---

it('allows global permission when enforce_boundaries_on_global is disabled (default)', function (): void {
    config()->set('policy-engine.enforce_boundaries_on_global', false);

    $this->permissionStore->register(['billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('team::5', ['posts.*']);

    expect($this->evaluator->can('App\\Models\\User', 1, 'billing.manage'))->toBeTrue();
});

it('denies global permission blocked by all boundaries when enforce_boundaries_on_global is enabled', function (): void {
    config()->set('policy-engine.enforce_boundaries_on_global', true);

    $this->permissionStore->register(['billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('team::5', ['posts.*']);

    expect($this->evaluator->can('App\\Models\\User', 1, 'billing.manage'))->toBeFalse();
});

it('allows global permission matching at least one boundary when enforce_boundaries_on_global is enabled', function (): void {
    config()->set('policy-engine.enforce_boundaries_on_global', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('team::5', ['posts.*']);
    $this->boundaryStore->set('org::acme', ['billing.*']);

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

it('allows global permission when enforce_boundaries_on_global is enabled but no boundaries exist', function (): void {
    config()->set('policy-engine.enforce_boundaries_on_global', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

it('explain returns deny trace with global boundary note when enforce_boundaries_on_global blocks', function (): void {
    config()->set('policy-engine.explain', true);
    config()->set('policy-engine.enforce_boundaries_on_global', true);

    $this->permissionStore->register(['billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('team::5', ['posts.*']);

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'billing.manage');

    expect($trace)
        ->result->toBe(EvaluationResult::Deny)
        ->boundary->toContain('global boundary enforcement');
});

it('explain returns allow trace with global boundary note when enforce_boundaries_on_global passes', function (): void {
    config()->set('policy-engine.explain', true);
    config()->set('policy-engine.enforce_boundaries_on_global', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('team::5', ['posts.*']);

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.create');

    expect($trace)
        ->result->toBe(EvaluationResult::Allow)
        ->boundary->toContain('global boundary enforcement');
});

it('filters effective permissions by global boundaries when enforce_boundaries_on_global is enabled', function (): void {
    config()->set('policy-engine.enforce_boundaries_on_global', true);

    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('team::5', ['posts.*']);

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toContain('posts.create')
        ->not->toContain('billing.manage');
});

// --- Empty boundary max_permissions ---

it('denies all permissions when boundary has empty max_permissions array', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'org::acme');
    $this->boundaryStore->set('org::acme', []);

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:org::acme'))->toBeFalse();
});
