<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Stores;

use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Events\RoleCreated;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Models\RolePermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class EloquentRoleStore implements RoleStore
{
    /**
     * Create or update a role with the given permissions.
     *
     * @param  array<int, string>  $permissions
     */
    public function save(string $id, string $name, array $permissions, bool $system = false): Role
    {
        $existing = Role::query()->find($id);

        if ($existing !== null && $existing->is_system && config('policy-engine.protect_system_roles')) {
            if (! $system) {
                throw new \RuntimeException("Cannot remove system flag from protected role [{$id}].");
            }

            $currentPermissions = $this->permissionsFor($id);

            if ($permissions !== $currentPermissions) {
                throw new \RuntimeException("Cannot modify permissions on protected system role [{$id}].");
            }
        }

        $role = Role::query()->updateOrCreate(
            ['id' => $id],
            ['name' => $name, 'is_system' => $system],
        );

        RolePermission::query()->where('role_id', $id)->delete();

        foreach ($permissions as $permissionId) {
            RolePermission::query()->create([
                'role_id' => $id,
                'permission_id' => $permissionId,
            ]);
        }

        Event::dispatch(
            $role->wasRecentlyCreated
                ? new RoleCreated($role)
                : new RoleUpdated($role, $role->getChanges()),
        );

        return $role;
    }

    /**
     * Remove a role by its identifier.
     *
     * @throws \RuntimeException If the role is system-protected and protection is enabled.
     */
    public function remove(string $id): void
    {
        $role = Role::query()->findOrFail($id);

        if ($role->is_system && config('policy-engine.protect_system_roles')) {
            throw new \RuntimeException("Cannot delete system role [{$id}].");
        }

        $role->delete();

        Event::dispatch(new RoleDeleted($role));
    }

    /**
     * Find a role by its identifier, or return null.
     */
    public function find(string $id): ?Role
    {
        return Role::query()->find($id);
    }

    /**
     * Retrieve all roles.
     *
     * @return Collection<int, Role>
     */
    public function all(): Collection
    {
        return Role::query()->get();
    }

    /**
     * Get the permission identifiers attached to a role.
     *
     * @return array<int, string>
     */
    public function permissionsFor(string $roleId): array
    {
        return RolePermission::query()
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->all();
    }
}
