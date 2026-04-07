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
        foreach ((array) $permissions as $permission) {
            $this->validateIdentifier($permission, 'permission');

            $wasRecentlyCreated = Permission::query()
                ->firstOrCreate(['id' => $permission])
                ->wasRecentlyCreated;

            if ($wasRecentlyCreated) {
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

        return Permission::query()
            ->whereRaw("\"id\" like ? escape '\\'", [$escaped.'.%'])
            ->get();
    }

    public function exists(string $id): bool
    {
        return Permission::query()->where('id', $id)->exists();
    }
}
