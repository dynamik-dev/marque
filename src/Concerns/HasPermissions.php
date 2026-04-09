<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Concerns;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\PolicyResolver;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Contracts\ScopeResolver;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\DTOs\Resource;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Models\Assignment;
use DynamikDev\Marque\Models\Role;
use Illuminate\Support\Collection;

/**
 * Provides permission-checking, role assignment, and evaluation
 * delegation to any Eloquent model that uses this trait.
 *
 * @internal DIRECT_PERMISSION_PREFIX is used to create synthetic roles
 *           for direct permission assignments. These roles are filtered
 *           from getRoles() / getRolesFor().
 *
 * The using model must be an Eloquent Model (provides getMorphClass() and getKey()).
 */
trait HasPermissions
{
    private const DIRECT_PERMISSION_PREFIX = '__dp.';

    /**
     * Build the Principal DTO for this model.
     */
    public function toPrincipal(): Principal
    {
        return new Principal(
            type: $this->getMorphClass(),
            id: $this->getKey(),
            attributes: $this->principalAttributes(),
        );
    }

    /**
     * Return extra attributes to embed in the Principal DTO.
     *
     * Override this method on the model to include domain-specific
     * context (e.g. department_id, tenant_id) that policy resolvers
     * and conditions can inspect.
     *
     * @return array<string, mixed>
     */
    protected function principalAttributes(): array
    {
        return [];
    }

    /**
     * Evaluate whether this subject holds a permission, optionally within a scope.
     *
     * This is the engine method that powers Gate integration, middleware,
     * and Blade directives. For application code, prefer Laravel's
     * $user->can('permission') which delegates here via the Gate hook.
     */
    public function canDo(
        string $permission,
        mixed $scope = null,
        ?Resource $resource = null,
        array $environment = [],
    ): bool {
        $result = app(Evaluator::class)->evaluate(
            $this->buildEvaluationRequest($permission, $scope, $resource, $environment),
        );

        return $result->decision === Decision::Allow;
    }

    /**
     * Inverse of canDo() — returns true when the subject does NOT hold
     * the given permission.
     */
    public function cannotDo(
        string $permission,
        mixed $scope = null,
        ?Resource $resource = null,
        array $environment = [],
    ): bool {
        return ! $this->canDo($permission, $scope, $resource, $environment);
    }

    /**
     * Return the full EvaluationResult for a permission check.
     *
     * Useful for debugging, audit logging, and policy inspection. The
     * trace config controls what is populated in the result.
     */
    public function explain(
        string $permission,
        mixed $scope = null,
        ?Resource $resource = null,
        array $environment = [],
    ): EvaluationResult {
        return app(Evaluator::class)->evaluate(
            $this->buildEvaluationRequest($permission, $scope, $resource, $environment),
        );
    }

    /**
     * Assign a role to this subject.
     *
     * WARNING: This is a privileged operation. Never expose this method to
     * end-user input (controllers, Livewire actions, API endpoints) without
     * an authorization guard. A user calling $this->assign('admin') on their
     * own model can escalate privileges.
     */
    public function assign(string $roleId, mixed $scope = null): void
    {
        app(AssignmentStore::class)->assign(
            $this->getMorphClass(),
            $this->getKey(),
            $roleId,
            app(ScopeResolver::class)->resolve($scope),
        );
    }

    /**
     * Revoke a role from this subject.
     *
     * WARNING: This is a privileged operation. Never expose this method to
     * end-user input without an authorization guard.
     */
    public function revoke(string $roleId, mixed $scope = null): void
    {
        app(AssignmentStore::class)->revoke(
            $this->getMorphClass(),
            $this->getKey(),
            $roleId,
            app(ScopeResolver::class)->resolve($scope),
        );
    }

    /**
     * Give a permission directly to this subject without creating an explicit role.
     *
     * Creates a hidden internal role and assigns it. The role is prefixed
     * with __dp. and is filtered from getRoles() / getRolesFor().
     */
    public function givePermission(string $permission, mixed $scope = null): void
    {
        if (! app(PermissionStore::class)->exists($permission)) {
            throw new \InvalidArgumentException("Permission [{$permission}] is not registered.");
        }

        $roleStore = app(RoleStore::class);

        if ($roleStore->find(self::directPermissionRoleId($permission)) === null) {
            $roleStore->saveDirectPermissionRole($permission, [$permission]);
        }

        $this->assign(self::directPermissionRoleId($permission), $scope);
    }

    /**
     * Revoke a directly-given permission from this subject.
     */
    public function revokePermission(string $permission, mixed $scope = null): void
    {
        $this->revoke(self::directPermissionRoleId($permission), $scope);
    }

    /**
     * Replace all role assignments with the given set, optionally within a scope.
     *
     * @param  array<int, string>  $roleIds
     */
    public function syncRoles(array $roleIds, mixed $scope = null): void
    {
        $resolvedScope = app(ScopeResolver::class)->resolve($scope);
        $assignmentStore = app(AssignmentStore::class);

        $current = $resolvedScope !== null
            ? $assignmentStore->forSubjectInScope($this->getMorphClass(), $this->getKey(), $resolvedScope)
            : $assignmentStore->forSubjectGlobal($this->getMorphClass(), $this->getKey());

        $currentRoleIds = array_values(array_filter(
            $current->pluck('role_id')->all(),
            static fn (string $roleId): bool => ! str_starts_with($roleId, self::DIRECT_PERMISSION_PREFIX),
        ));
        $toRevoke = array_diff($currentRoleIds, $roleIds);
        $toAssign = array_diff($roleIds, $currentRoleIds);

        Assignment::query()->getConnection()->transaction(function () use ($assignmentStore, $toRevoke, $toAssign, $resolvedScope): void {
            foreach ($toRevoke as $roleId) {
                $assignmentStore->revoke($this->getMorphClass(), $this->getKey(), $roleId, $resolvedScope);
            }

            foreach ($toAssign as $roleId) {
                $assignmentStore->assign($this->getMorphClass(), $this->getKey(), $roleId, $resolvedScope);
            }
        });
    }

    /**
     * Check whether this subject has any of the given roles.
     *
     * @param  array<int, string>  $roles
     */
    public function hasAnyRole(array $roles, mixed $scope = null): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether this subject has all of the given roles.
     *
     * @param  array<int, string>  $roles
     */
    public function hasAllRoles(array $roles, mixed $scope = null): bool
    {
        if ($roles === []) {
            return false;
        }

        foreach ($roles as $role) {
            if (! $this->hasRole($role, $scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether this subject has a specific role assignment.
     *
     * Queries the AssignmentStore directly rather than going through the
     * Evaluator, so the result is not affected by permission logic or cache.
     */
    public function hasRole(string $role, mixed $scope = null): bool
    {
        $resolvedScope = app(ScopeResolver::class)->resolve($scope);

        if ($resolvedScope !== null) {
            return app(AssignmentStore::class)
                ->forSubjectInScope($this->getMorphClass(), $this->getKey(), $resolvedScope)
                ->contains('role_id', $role);
        }

        return app(AssignmentStore::class)
            ->forSubjectGlobal($this->getMorphClass(), $this->getKey())
            ->contains('role_id', $role);
    }

    /**
     * Return all effective (net-allowed) permission strings for this subject.
     *
     * Queries each PolicyResolver in the resolver chain with a wildcard request,
     * then subtracts any action covered by a Deny statement.
     *
     * @return array<int, string>
     */
    public function effectivePermissions(mixed $scope = null): array
    {
        $resolvedScope = app(ScopeResolver::class)->resolve($scope);

        $request = new EvaluationRequest(
            principal: $this->toPrincipal(),
            action: '*.*',
            resource: null,
            context: new Context(scope: $resolvedScope),
        );

        /** @var array<string, class-string<PolicyResolver>> $resolverClasses */
        $resolverClasses = config('marque.resolvers', []);

        $statements = collect();

        foreach ($resolverClasses as $resolverClass) {
            /** @var PolicyResolver $resolver */
            $resolver = app($resolverClass);
            $statements = $statements->merge($resolver->resolve($request));
        }

        /** @var Collection<int, PolicyStatement> $allows */
        $allows = $statements->filter(
            static fn (PolicyStatement $s): bool => $s->effect === Effect::Allow,
        );

        /** @var Collection<int, PolicyStatement> $denies */
        $denies = $statements->filter(
            static fn (PolicyStatement $s): bool => $s->effect === Effect::Deny,
        );

        $matcher = app(Matcher::class);

        return $allows
            ->filter(function (PolicyStatement $allow) use ($denies, $matcher): bool {
                foreach ($denies as $deny) {
                    if ($matcher->matches($deny->action, $allow->action)) {
                        return false;
                    }
                }

                return true;
            })
            ->pluck('action')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build the internal role ID for a direct permission assignment.
     */
    private static function directPermissionRoleId(string $permission): string
    {
        return self::DIRECT_PERMISSION_PREFIX.$permission;
    }

    /** @return Collection<int, Assignment> */
    public function assignments(): Collection
    {
        return app(AssignmentStore::class)->forSubject(
            $this->getMorphClass(),
            $this->getKey(),
        );
    }

    /** @return Collection<int, Assignment> */
    public function assignmentsFor(mixed $scope): Collection
    {
        $resolvedScope = app(ScopeResolver::class)->resolve($scope);

        if ($resolvedScope === null) {
            throw new \InvalidArgumentException('Scope could not be resolved.');
        }

        return app(AssignmentStore::class)->forSubjectInScope(
            $this->getMorphClass(),
            $this->getKey(),
            $resolvedScope,
        );
    }

    /** @return Collection<int, Role> */
    public function getRoles(): Collection
    {
        $roleIds = $this->assignments()
            ->pluck('role_id')
            ->unique()
            ->reject(static fn (string $roleId): bool => str_starts_with($roleId, self::DIRECT_PERMISSION_PREFIX))
            ->values()
            ->all();

        return app(RoleStore::class)->findMany($roleIds)->values();
    }

    /** @return Collection<int, Role> */
    public function getRolesFor(mixed $scope): Collection
    {
        $roleIds = $this->assignmentsFor($scope)
            ->pluck('role_id')
            ->unique()
            ->reject(static fn (string $roleId): bool => str_starts_with($roleId, self::DIRECT_PERMISSION_PREFIX))
            ->values()
            ->all();

        return app(RoleStore::class)->findMany($roleIds)->values();
    }

    /**
     * Build an EvaluationRequest DTO from the given permission, scope, resource, and environment.
     */
    private function buildEvaluationRequest(
        string $permission,
        mixed $scope,
        ?Resource $resource,
        array $environment,
    ): EvaluationRequest {
        $resolvedScope = app(ScopeResolver::class)->resolve($scope);

        return new EvaluationRequest(
            principal: $this->toPrincipal(),
            action: $permission,
            resource: $resource,
            context: new Context(scope: $resolvedScope, environment: $environment),
        );
    }
}
