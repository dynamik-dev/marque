<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Concerns;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Role;
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
     * Evaluate whether this subject holds a permission, optionally within a scope.
     *
     * This is the engine method that powers Gate integration, middleware,
     * and Blade directives. For application code, prefer Laravel's
     * $user->can('permission') which delegates here via the Gate hook.
     */
    public function canDo(string $permission, mixed $scope = null): bool
    {
        return app(Evaluator::class)->can(
            $this->getMorphClass(),
            $this->getKey(),
            $this->buildScopedPermission($permission, $scope),
        );
    }

    /**
     * Inverse of canDo() — returns true when the subject does NOT hold
     * the given permission.
     */
    public function cannotDo(string $permission, mixed $scope = null): bool
    {
        return ! $this->canDo($permission, $scope);
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
        $permissionStore = app(PermissionStore::class);

        if (! $permissionStore->exists($permission)) {
            throw new \InvalidArgumentException("Permission [{$permission}] is not registered.");
        }

        $roleId = self::directPermissionRoleId($permission);
        $roleStore = app(RoleStore::class);

        if ($roleStore->find($roleId) === null) {
            $roleStore->save($roleId, "Direct: {$permission}", [$permission]);
        }

        $this->assign($roleId, $scope);
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

        foreach ($toRevoke as $roleId) {
            $assignmentStore->revoke($this->getMorphClass(), $this->getKey(), $roleId, $resolvedScope);
        }

        foreach ($toAssign as $roleId) {
            $assignmentStore->assign($this->getMorphClass(), $this->getKey(), $roleId, $resolvedScope);
        }
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

    /** @return array<int, string> */
    public function effectivePermissions(mixed $scope = null): array
    {
        return app(Evaluator::class)->effectivePermissions(
            $this->getMorphClass(),
            $this->getKey(),
            app(ScopeResolver::class)->resolve($scope),
        );
    }

    /** @return Collection<int, Role> */
    public function getRoles(): Collection
    {
        $roleStore = app(RoleStore::class);

        return $this->assignments()
            ->pluck('role_id')
            ->unique()
            ->reject(static fn (string $roleId): bool => str_starts_with($roleId, self::DIRECT_PERMISSION_PREFIX))
            ->map(static fn (string $roleId): ?Role => $roleStore->find($roleId))
            ->filter()
            ->values();
    }

    /** @return Collection<int, Role> */
    public function getRolesFor(mixed $scope): Collection
    {
        $roleStore = app(RoleStore::class);

        return $this->assignmentsFor($scope)
            ->pluck('role_id')
            ->unique()
            ->reject(static fn (string $roleId): bool => str_starts_with($roleId, self::DIRECT_PERMISSION_PREFIX))
            ->map(static fn (string $roleId): ?Role => $roleStore->find($roleId))
            ->filter()
            ->values();
    }

    /**
     * Check whether this subject has a specific role assignment.
     *
     * Delegates to the Evaluator (which caches results via CachedEvaluator).
     */
    public function hasRole(string $role, mixed $scope = null): bool
    {
        return app(Evaluator::class)->hasRole(
            $this->getMorphClass(),
            $this->getKey(),
            $role,
            app(ScopeResolver::class)->resolve($scope),
        );
    }

    public function explain(string $permission, mixed $scope = null): EvaluationTrace
    {
        return app(Evaluator::class)->explain(
            $this->getMorphClass(),
            $this->getKey(),
            $this->buildScopedPermission($permission, $scope),
        );
    }

    /**
     * Build a permission string with an optional scope suffix.
     *
     * The evaluator expects the format `permission:scope` when a scope
     * is provided, and plain `permission` when unscoped.
     */
    private function buildScopedPermission(string $permission, mixed $scope): string
    {
        $resolvedScope = app(ScopeResolver::class)->resolve($scope);

        if ($resolvedScope === null) {
            return $permission;
        }

        return $permission.':'.$resolvedScope;
    }
}
