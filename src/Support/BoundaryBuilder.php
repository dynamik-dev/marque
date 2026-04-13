<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use DynamikDev\Marque\Contracts\BoundaryStore;

class BoundaryBuilder
{
    public function __construct(
        private readonly BoundaryStore $store,
        private readonly string $scope,
    ) {}

    /**
     * Set the maximum allowed permissions for this boundary's scope.
     *
     * @param  array<int, string>  $permissions
     */
    public function permits(array $permissions): self
    {
        $this->store->set($this->scope, $permissions);

        return $this;
    }

    /**
     * Remove the boundary for this scope.
     */
    public function remove(): void
    {
        $this->store->remove($this->scope);
    }
}
