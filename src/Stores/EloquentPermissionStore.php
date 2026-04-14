<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Events\PermissionCreated;
use DynamikDev\Marque\Events\PermissionDeleted;
use DynamikDev\Marque\Models\Permission;
use DynamikDev\Marque\Models\RolePermission;
use DynamikDev\Marque\Support\IdentifierValidator;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class EloquentPermissionStore implements PermissionStore
{
    /**
     * Register one or more permissions.
     *
     * Idempotent — existing permissions are silently skipped.
     * Dispatches PermissionCreated for each newly created permission.
     *
     * @param  string|array<int, string>  $permissions
     */
    public function register(string|array $permissions): void
    {
        $permissions = (array) $permissions;

        foreach ($permissions as $permission) {
            IdentifierValidator::validate($permission, 'permission');
        }

        /** @var array<int, string> $existing */
        $existing = Permission::query()
            ->whereIn('id', $permissions)
            ->pluck('id')
            ->all();

        $new = array_values(array_unique(array_diff($permissions, $existing)));

        if ($new !== []) {
            (new Permission)->getConnection()->transaction(function () use ($new): void {
                Permission::query()->insert(
                    array_map(static fn (string $id): array => ['id' => $id], $new),
                );
            });

            foreach ($new as $permission) {
                Event::dispatch(new PermissionCreated($permission));
            }
        }
    }

    /**
     * Remove a permission by its identifier.
     *
     * Also removes any role_permissions rows that reference it.
     * Dispatches PermissionDeleted after deletion.
     */
    public function remove(string $id): void
    {
        RolePermission::query()->where('permission_id', $id)->delete();
        $deleted = Permission::query()->where('id', $id)->delete();

        if ($deleted > 0) {
            /** @var Connection $connection */
            $connection = Permission::query()->getConnection();
            $connection->afterCommit(function () use ($id): void {
                Event::dispatch(new PermissionDeleted($id));
            });
        }
    }

    /**
     * Retrieve all permissions, optionally filtered by a dot-notated prefix.
     *
     * @return Collection<int, Permission>
     */
    public function all(?string $prefix = null): Collection
    {
        if ($prefix === null) {
            return Permission::query()->get();
        }

        $escaped = str_replace(['#', '%', '_'], ['##', '#%', '#_'], $prefix);

        /*
         * whereRaw is necessary here because Laravel's ->where('like') does not
         * support the ESCAPE clause, which is required for correct handling of
         * user-supplied prefixes containing '%' or '_' characters.
         * Uses '#' as escape char for cross-database compatibility (backslash
         * is a special character in MySQL string literals).
         */
        return Permission::query()
            ->whereRaw("id like ? escape '#'", [$escaped.'.%'])
            ->get();
    }

    public function exists(string $id): bool
    {
        return Permission::query()->where('id', $id)->exists();
    }

    public function find(string $id): ?Permission
    {
        return Permission::query()->find($id);
    }

    /**
     * Remove all permissions, dispatching PermissionDeleted for each.
     *
     * Also removes all role_permission pivot rows referencing the deleted permissions.
     */
    public function removeAll(): void
    {
        RolePermission::query()->delete();

        $deletedIds = [];

        Permission::query()->get()->each(function (Permission $permission) use (&$deletedIds): void {
            $deletedIds[] = $permission->id;
            $permission->delete();
        });

        /** @var Connection $connection */
        $connection = Permission::query()->getConnection();
        $connection->afterCommit(function () use (&$deletedIds): void {
            foreach ($deletedIds as $id) {
                Event::dispatch(new PermissionDeleted($id));
            }
        });
    }
}
