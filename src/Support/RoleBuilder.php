<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Support;

use DynamikDev\PolicyEngine\Contracts\RoleStore;

class RoleBuilder
{
    public function __construct(
        private readonly RoleStore $roleStore,
        private readonly string $roleId,
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
}
