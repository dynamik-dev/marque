<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Contracts\ScopeResolver;
use Illuminate\Database\Eloquent\Model;

class RoleBuilder
{
    public function __construct(
        private readonly RoleStore $roleStore,
        private readonly string $roleId,
        private readonly ?AssignmentStore $assignmentStore = null,
        private readonly ?ScopeResolver $scopeResolver = null,
    ) {}

    /**
     * Grant additional permissions to the role.
     *
     * @param  array<int, string>  $permissions
     */
    public function grant(array $permissions): self
    {
        $role = $this->roleStore->find($this->roleId);

        if ($role === null) {
            throw new \RuntimeException("Role [{$this->roleId}] not found.");
        }

        $current = $this->roleStore->permissionsFor($this->roleId);
        $merged = array_values(array_unique([...$current, ...$permissions]));

        $this->roleStore->save($this->roleId, $role->name, $merged, $role->is_system);

        return $this;
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
