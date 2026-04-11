<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use DynamikDev\Marque\Contracts\BoundaryStore;

/**
 * Fluent builder for managing the boundary attached to a scope.
 *
 * Goes through the BoundaryStore contract only, never touching Eloquent
 * directly, so any driver implementing the contract works with this sugar.
 */
class BoundaryBuilder
{
    public function __construct(
        private readonly BoundaryStore $store,
        private readonly string $scope,
    ) {}

    /**
     * Set the maximum allowed permissions for this scope.
     *
     * Calls replace the entire permission set rather than merging, matching
     * BoundaryStore::set() semantics.
     *
     * @param  array<int, string>  $permissions
     */
    public function permits(array $permissions): self
    {
        $this->store->set($this->scope, $permissions);

        return $this;
    }

    /**
     * Remove the boundary for this scope entirely.
     */
    public function remove(): void
    {
        $this->store->remove($this->scope);
    }
}
