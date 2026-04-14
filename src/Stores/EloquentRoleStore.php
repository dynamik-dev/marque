<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Events\RoleCreated;
use DynamikDev\Marque\Events\RoleDeleted;
use DynamikDev\Marque\Events\RoleUpdated;
use DynamikDev\Marque\Models\Role;
use DynamikDev\Marque\Models\RolePermission;
use DynamikDev\Marque\Support\IdentifierValidator;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class EloquentRoleStore implements RoleStore
{
    private const DIRECT_PERMISSION_PREFIX = '__dp.';

    /**
     * Create or update a role with the given permissions.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, array<int, array{type: string, parameters: array<string, mixed>}>>  $conditions
     */
    public function save(string $id, string $name, array $permissions, bool $system = false, array $conditions = []): Role
    {
        IdentifierValidator::validate($id, 'role');

        if (str_starts_with($id, self::DIRECT_PERMISSION_PREFIX)) {
            throw new \InvalidArgumentException(
                "Invalid role ID [{$id}]. The '__dp.' prefix is reserved for internal use.",
            );
        }

        $existing = Role::query()->find($id);

        if ($existing !== null && $existing->is_system && config('marque.protect_system_roles')) {
            if (! $system) {
                throw new \RuntimeException("Cannot remove system flag from protected role [{$id}].");
            }

            $currentPermissions = $this->permissionsFor($id);

            if ($currentPermissions !== []) {
                $sortedCurrent = array_unique($currentPermissions);
                $sortedNew = array_unique($permissions);
                sort($sortedCurrent);
                sort($sortedNew);

                if ($sortedNew !== $sortedCurrent) {
                    throw new \RuntimeException("Cannot modify permissions on protected system role [{$id}].");
                }
            }
        }

        $role = Role::query()->getConnection()->transaction(function () use ($id, $name, $permissions, $system, $conditions): Role {
            $role = Role::query()->updateOrCreate(
                ['id' => $id],
                ['name' => $name, 'is_system' => $system],
            );

            RolePermission::query()->where('role_id', $id)->delete();

            $uniquePermissions = array_values(array_unique($permissions));

            if ($uniquePermissions !== []) {
                RolePermission::query()->insert(
                    array_map(
                        static fn (string $perm): array => [
                            'role_id' => $id,
                            'permission_id' => $perm,
                            'conditions' => isset($conditions[$perm]) ? json_encode($conditions[$perm]) : null,
                        ],
                        $uniquePermissions,
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

        if ($role->is_system && config('marque.protect_system_roles')) {
            throw new \RuntimeException("Cannot delete system role [{$id}].");
        }

        $role->delete();

        $role->getConnection()->afterCommit(function () use ($role): void {
            Event::dispatch(new RoleDeleted($role));
        });
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

        $deleted = [];

        Role::query()->chunkById(200, function (Collection $roles) use (&$deleted): void {
            $roles->each(function (Role $role) use (&$deleted): void {
                $deleted[] = clone $role;
                $role->delete();
            });
        });

        /** @var Connection $connection */
        $connection = Role::query()->getConnection();
        $connection->afterCommit(function () use (&$deleted): void {
            foreach ($deleted as $role) {
                Event::dispatch(new RoleDeleted($role));
            }
        });
    }

    public function find(string $id): ?Role
    {
        return Role::query()->find($id);
    }

    /**
     * Find multiple roles by their identifiers in a single query.
     *
     * @param  array<int, string>  $ids
     * @return Collection<string, Role>
     */
    public function findMany(array $ids): Collection
    {
        $uniqueIds = array_values(array_unique($ids));

        if ($uniqueIds === []) {
            return collect();
        }

        return Role::query()
            ->whereIn('id', $uniqueIds)
            ->get()
            ->keyBy('id');
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
     * Get permissions with their conditions for multiple roles in one query.
     *
     * @param  array<int, string>  $roleIds
     * @return array<string, array<int, array{permission: string, conditions: array<int, array{type: string, parameters: array<string, mixed>}>}>>
     */
    public function permissionsWithConditionsForRoles(array $roleIds): array
    {
        $uniqueRoleIds = array_values(array_unique($roleIds));

        if ($uniqueRoleIds === []) {
            return [];
        }

        $grouped = RolePermission::query()
            ->whereIn('role_id', $uniqueRoleIds)
            ->get(['role_id', 'permission_id', 'conditions'])
            ->groupBy('role_id');

        /** @var array<string, array<int, array{permission: string, conditions: array<int, array{type: string, parameters: array<string, mixed>}>}>> $mapped */
        $mapped = $grouped->map(static function (Collection $rows): array {
            return $rows->map(static fn (RolePermission $row): array => [
                'permission' => $row->permission_id,
                'conditions' => is_array($row->conditions) ? $row->conditions : [],
            ])->all();
        })->all();

        $result = [];

        foreach ($uniqueRoleIds as $roleId) {
            $result[$roleId] = $mapped[$roleId] ?? [];
        }

        return $result;
    }

    /**
     * Create the internal synthetic role for a direct permission assignment.
     *
     * Bypasses `validateIdentifier()` since the `__dp.` prefix is reserved
     * and rejected by `save()`. The role ID is built internally from the
     * permission name.
     *
     * @param  array<int, string>  $permissions
     */
    public function saveDirectPermissionRole(string $permission, array $permissions): Role
    {
        $roleId = self::DIRECT_PERMISSION_PREFIX.$permission;

        $role = Role::query()->getConnection()->transaction(function () use ($roleId, $permission, $permissions): Role {
            $role = Role::query()->updateOrCreate(
                ['id' => $roleId],
                ['name' => "Direct: {$permission}", 'is_system' => false],
            );

            RolePermission::query()->where('role_id', $roleId)->delete();

            $uniquePermissions = array_values(array_unique($permissions));

            if ($uniquePermissions !== []) {
                RolePermission::query()->insert(
                    array_map(
                        static fn (string $perm): array => ['role_id' => $roleId, 'permission_id' => $perm],
                        $uniquePermissions,
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
}
