<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

use DynamikDev\PolicyEngine\Models\Role;
use Illuminate\Support\Collection;

interface RoleStore
{
    /**
     * Create or update a role with the given permissions.
     *
     * @param  array<int, string>  $permissions
     */
    public function save(string $id, string $name, array $permissions, bool $system = false): Role;

    /**
     * Remove a role by its identifier.
     */
    public function remove(string $id): void;

    /**
     * Find a role by its identifier, or return null.
     */
    public function find(string $id): ?Role;

    /**
     * Retrieve all roles.
     *
     * @return Collection<int, Role>
     */
    public function all(): Collection;

    /**
     * Get the permission identifiers attached to a role.
     *
     * @return array<int, string>
     */
    public function permissionsFor(string $roleId): array;

    /**
     * Get permission identifiers for multiple roles in one query.
     *
     * @param  array<int, string>  $roleIds
     * @return array<string, array<int, string>>
     */
    public function permissionsForRoles(array $roleIds): array;

    /**
     * Remove all roles, dispatching events for each.
     */
    public function removeAll(): void;
}
