<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Stores;

use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Events\PermissionCreated;
use DynamikDev\PolicyEngine\Events\PermissionDeleted;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\RolePermission;
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
            $this->validateIdentifier($permission, 'permission');
        }

        /** @var array<int, string> $existing */
        $existing = Permission::query()
            ->whereIn('id', $permissions)
            ->pluck('id')
            ->all();

        $new = array_diff($permissions, $existing);

        if ($new !== []) {
            Permission::query()->insert(
                array_map(static fn (string $id): array => ['id' => $id], $new),
            );

            foreach ($new as $permission) {
                Event::dispatch(new PermissionCreated($permission));
            }
        }
    }

    /**
     * Validate that an identifier string is safe for use as a permission or role ID.
     *
     * @throws \InvalidArgumentException
     */
    private function validateIdentifier(string $id, string $type): void
    {
        if ($id === '' || preg_match('/[\s:]/', $id) || str_starts_with($id, '!')) {
            throw new \InvalidArgumentException(
                "Invalid {$type} ID [{$id}]. IDs must not be empty, contain whitespace or colons, or start with '!'.",
            );
        }

        if (strlen($id) > 255) {
            throw new \InvalidArgumentException(
                "Invalid {$type} ID [{$id}]. IDs must not exceed 255 characters.",
            );
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
        Permission::query()->where('id', $id)->delete();

        Event::dispatch(new PermissionDeleted($id));
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

        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $prefix);

        // whereRaw is necessary here because Laravel's ->where('like') does not
        // support the ESCAPE clause, which is required for correct handling of
        // user-supplied prefixes containing '%' or '_' characters.
        return Permission::query()
            ->whereRaw("id like ? escape '\\'", [$escaped.'.%'])
            ->get();
    }

    public function exists(string $id): bool
    {
        return Permission::query()->where('id', $id)->exists();
    }

    /**
     * Remove all permissions, dispatching PermissionDeleted for each.
     *
     * Also removes all role_permission pivot rows referencing the deleted permissions.
     */
    public function removeAll(): void
    {
        RolePermission::query()->delete();

        Permission::query()->get()->each(function (Permission $permission): void {
            $permission->delete();
            Event::dispatch(new PermissionDeleted($permission->id));
        });
    }
}
