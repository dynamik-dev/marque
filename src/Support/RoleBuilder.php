<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Contracts\ScopeResolver;
use Illuminate\Database\Eloquent\Model;

class RoleBuilder
{
    public function __construct(
        private readonly RoleStore $roleStore,
        private readonly string $roleId,
        private readonly ?PermissionStore $permissionStore = null,
        private readonly ?AssignmentStore $assignmentStore = null,
        private readonly ?ScopeResolver $scopeResolver = null,
    ) {}

    /**
     * Grant additional permissions to the role.
     *
     * Any literal (non-wildcard) permissions not already registered are
     * auto-registered through the PermissionStore so a seeder can declare
     * a role in one step without a separate Marque::permissions() call.
     *
     * @param  array<int, string>  $permissions
     */
    public function grant(array $permissions): self
    {
        $role = $this->roleStore->find($this->roleId);

        if ($role === null) {
            throw new \RuntimeException("Role [{$this->roleId}] not found.");
        }

        $this->autoRegisterPermissions($permissions);

        $current = $this->roleStore->permissionsFor($this->roleId);
        $merged = array_values(array_unique([...$current, ...$permissions]));

        $this->roleStore->save($this->roleId, $role->name, $merged, $role->is_system);

        return $this;
    }

    /**
     * Register any literal permissions in the given set that are not
     * already present in the PermissionStore. Wildcards and already-
     * registered permissions are skipped.
     *
     * @param  array<int, string>  $permissions
     */
    private function autoRegisterPermissions(array $permissions): void
    {
        if ($this->permissionStore === null) {
            return;
        }

        $toRegister = [];

        foreach ($permissions as $permission) {
            $base = str_starts_with($permission, '!') ? substr($permission, 1) : $permission;

            if (str_contains($base, '*')) {
                continue;
            }

            if (! $this->permissionStore->exists($base)) {
                $toRegister[] = $base;
            }
        }

        if ($toRegister !== []) {
            $this->permissionStore->register($toRegister);
        }
    }

    /**
     * Add deny rules to the role.
     *
     * @param  array<int, string>  $permissions
     */
    public function deny(array $permissions): self
    {
        return $this->grant(array_map(
            static fn (string $p): string => str_starts_with($p, '!') ? $p : "!{$p}",
            $permissions,
        ));
    }

    /**
     * Remove permissions from the role.
     *
     * @param  array<int, string>  $permissions
     */
    public function ungrant(array $permissions): self
    {
        $role = $this->roleStore->find($this->roleId);

        if ($role === null) {
            throw new \RuntimeException("Role [{$this->roleId}] not found.");
        }

        $current = $this->roleStore->permissionsFor($this->roleId);
        $remaining = array_values(array_diff($current, $permissions));

        $this->roleStore->save($this->roleId, $role->name, $remaining, $role->is_system);

        return $this;
    }

    /**
     * Remove the role entirely.
     */
    public function remove(): void
    {
        $this->roleStore->remove($this->roleId);
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return $this->roleStore->permissionsFor($this->roleId);
    }

    public function assignTo(Model $subject, mixed $scope = null): self
    {
        $this->requireAssignmentDeps();

        $this->assignmentStore->assign(
            $subject->getMorphClass(),
            $this->resolveSubjectKey($subject),
            $this->roleId,
            $this->scopeResolver->resolve($scope),
        );

        return $this;
    }

    public function revokeFrom(Model $subject, mixed $scope = null): self
    {
        $this->requireAssignmentDeps();

        $this->assignmentStore->revoke(
            $subject->getMorphClass(),
            $this->resolveSubjectKey($subject),
            $this->roleId,
            $this->scopeResolver->resolve($scope),
        );

        return $this;
    }

    private function resolveSubjectKey(Model $subject): int|string
    {
        $key = $subject->getKey();

        if (is_int($key) || is_string($key)) {
            return $key;
        }

        throw new \RuntimeException('Subject key must be an int or string.');
    }

    /**
     * @phpstan-assert !null $this->assignmentStore
     * @phpstan-assert !null $this->scopeResolver
     */
    private function requireAssignmentDeps(): void
    {
        if ($this->assignmentStore === null || $this->scopeResolver === null) {
            throw new \RuntimeException(
                'RoleBuilder requires AssignmentStore and ScopeResolver for assignment operations.',
            );
        }
    }
}
