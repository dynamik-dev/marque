<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\Models\Boundary;
use Illuminate\Support\Collection;

interface BoundaryStore
{
    /**
     * Set the maximum allowed permissions for a scope.
     *
     * @param  array<int, string>  $maxPermissions
     */
    public function set(string $scope, array $maxPermissions): void;

    /**
     * Remove the boundary for a scope.
     */
    public function remove(string $scope): void;

    /**
     * Find the boundary for a scope, or return null.
     */
    public function find(string $scope): ?Boundary;

    /**
     * Get all boundaries.
     *
     * @return Collection<int, Boundary>
     */
    public function all(): Collection;

    /**
     * Remove all boundaries, dispatching events for each.
     */
    public function removeAll(): void;
}
