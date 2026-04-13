<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\Models\Permission;
use Illuminate\Support\Collection;

interface PermissionStore
{
    /**
     * Register one or more permissions.
     *
     * @param  string|array<int, string>  $permissions
     */
    public function register(string|array $permissions): void;

    /**
     * Remove a permission by its identifier.
     */
    public function remove(string $id): void;

    /**
     * Retrieve all permissions, optionally filtered by a dot-notated prefix.
     *
     * @return Collection<int, Permission>
     */
    public function all(?string $prefix = null): Collection;

    /**
     * Check whether a permission exists.
     */
    public function exists(string $id): bool;

    /**
     * Find a permission by its identifier, or return null.
     */
    public function find(string $id): ?Permission;

    /**
     * Remove all permissions, dispatching events for each.
     */
    public function removeAll(): void;
}
