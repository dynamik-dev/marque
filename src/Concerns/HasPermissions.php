<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Concerns;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Models\Role;
use Illuminate\Support\Collection;

/**
 * Provides permission-checking, role assignment, and evaluation
 * delegation to any Eloquent model that uses this trait.
 *
 * The using model must be an Eloquent Model (provides getMorphClass() and getKey()).
 */
trait HasPermissions
{
    public function canDo(string $permission, mixed $scope = null): bool
    {
        return app(Evaluator::class)->can(
            $this->getMorphClass(),
            $this->getKey(),
            $this->buildScopedPermission($permission, $scope),
        );
    }

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

    /** @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment> */
    public function assignments(): Collection
    {
        return app(AssignmentStore::class)->forSubject(
            $this->getMorphClass(),
            $this->getKey(),
        );
    }

    /** @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment> */
    public function assignmentsFor(mixed $scope): Collection
    {
        return app(AssignmentStore::class)->forSubjectInScope(
            $this->getMorphClass(),
            $this->getKey(),
            app(ScopeResolver::class)->resolve($scope),
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
    public function roles(): Collection
    {
        $roleStore = app(RoleStore::class);

        return $this->assignments()
            ->pluck('role_id')
            ->unique()
            ->map(static fn (string $roleId): ?Role => $roleStore->find($roleId))
            ->filter()
            ->values();
    }

    /** @return Collection<int, Role> */
    public function rolesFor(mixed $scope): Collection
    {
        $roleStore = app(RoleStore::class);

        return $this->assignmentsFor($scope)
            ->pluck('role_id')
            ->unique()
            ->map(static fn (string $roleId): ?Role => $roleStore->find($roleId))
            ->filter()
            ->values();
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
