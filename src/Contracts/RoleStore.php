<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\Models\Role;
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
     * Find multiple roles by their identifiers in a single query.
     *
     * @param  array<int, string>  $ids
     * @return Collection<string, Role>
     */
    public function findMany(array $ids): Collection;

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

    /**
     * Create the internal synthetic role for a direct permission assignment.
     *
     * Implementations MUST build the role ID using the reserved `__dp.` prefix
     * internally. The `save()` method MUST reject IDs starting with `__dp.`
     * so that external callers cannot create or overwrite these roles.
     *
     * @param  string  $permission  The permission identifier to wrap in a synthetic role.
     * @param  array<int, string>  $permissions  The permission set for the role (typically just [$permission]).
     */
    public function saveDirectPermissionRole(string $permission, array $permissions): Role;
}
