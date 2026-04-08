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
        $this->validateIdentifier($id);

        $existing = Role::query()->find($id);

        if ($existing !== null && $existing->is_system && config('policy-engine.protect_system_roles')) {
            if (! $system) {
                throw new \RuntimeException("Cannot remove system flag from protected role [{$id}].");
            }

            $currentPermissions = $this->permissionsFor($id);

            $sortedCurrent = $currentPermissions;
            $sortedNew = $permissions;
            sort($sortedCurrent);
            sort($sortedNew);

            if ($sortedNew !== $sortedCurrent) {
                throw new \RuntimeException("Cannot modify permissions on protected system role [{$id}].");
            }
        }

        $role = Role::query()->getConnection()->transaction(function () use ($id, $name, $permissions, $system): Role {
            $role = Role::query()->updateOrCreate(
                ['id' => $id],
                ['name' => $name, 'is_system' => $system],
            );

            RolePermission::query()->where('role_id', $id)->delete();

            if ($permissions !== []) {
                RolePermission::query()->insert(
                    array_map(
                        static fn (string $perm): array => ['role_id' => $id, 'permission_id' => $perm],
                        $permissions,
                    ),
                );
            }

            return $role;
        });

        /** @var Role $role */
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
     * Remove all roles, dispatching RoleDeleted for each.
     *
     * Bypasses system-role protection since this is used for full-replace imports.
     * Also removes all role_permission pivot rows.
     */
    public function removeAll(): void
    {
        RolePermission::query()->delete();

        Role::query()->get()->each(function (Role $role): void {
            $role->delete();
            Event::dispatch(new RoleDeleted($role));
        });
    }

    public function find(string $id): ?Role
    {
        return Role::query()->find($id);
    }

    /** @return Collection<int, Role> */
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
        /** @var array<int, string> $permissions */
        $permissions = RolePermission::query()
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->all();

        return $permissions;
    }

    /**
     * Get permission identifiers for multiple roles in one query.
     *
     * @param  array<int, string>  $roleIds
     * @return array<string, array<int, string>>
     */
    public function permissionsForRoles(array $roleIds): array
    {
        $uniqueRoleIds = array_values(array_unique($roleIds));

        if ($uniqueRoleIds === []) {
            return [];
        }

        /** @var array<string, array<int, string>> $grouped */
        $grouped = RolePermission::query()
            ->whereIn('role_id', $uniqueRoleIds)
            ->get(['role_id', 'permission_id'])
            ->groupBy('role_id')
            ->map(static fn (Collection $rows): array => $rows->pluck('permission_id')->all())
            ->all();

        $result = [];

        foreach ($uniqueRoleIds as $roleId) {
            $result[$roleId] = $grouped[$roleId] ?? [];
        }

        return $result;
    }

    /**
     * Validate that a role identifier string is safe for use as a role ID.
     *
     * @throws \InvalidArgumentException
     */
    private function validateIdentifier(string $id): void
    {
        if ($id === '' || preg_match('/[\s:]/', $id) || str_starts_with($id, '!')) {
            throw new \InvalidArgumentException(
                "Invalid role ID [{$id}]. IDs must not be empty, contain whitespace or colons, or start with '!'.",
            );
        }

        if (strlen($id) > 255) {
            throw new \InvalidArgumentException(
                "Invalid role ID [{$id}]. IDs must not exceed 255 characters.",
            );
        }
    }
}
