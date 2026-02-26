<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Stores;

use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Models\Boundary;

class EloquentBoundaryStore implements BoundaryStore
{
    /**
     * Set the maximum allowed permissions for a scope.
     *
     * Creates or updates the boundary record using updateOrCreate.
     *
     * @param  array<int, string>  $maxPermissions
     */
    public function set(string $scope, array $maxPermissions): void
    {
        Boundary::query()->updateOrCreate(
            ['scope' => $scope],
            ['max_permissions' => $maxPermissions],
        );
    }

    /**
     * Remove the boundary for a scope.
     *
     * Silently succeeds if no boundary exists for the given scope.
     */
    public function remove(string $scope): void
    {
        Boundary::query()->where('scope', $scope)->delete();
    }

    /**
     * Find the boundary for a scope, or return null.
     */
    public function find(string $scope): ?Boundary
    {
        return Boundary::query()->where('scope', $scope)->first();
    }
}
