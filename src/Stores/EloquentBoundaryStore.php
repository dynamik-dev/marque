<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Stores;

use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Events\BoundaryRemoved;
use DynamikDev\PolicyEngine\Events\BoundarySet;
use DynamikDev\PolicyEngine\Models\Boundary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

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
        foreach ($maxPermissions as $value) {
            if (! is_string($value) || $value === '') { // @phpstan-ignore function.alreadyNarrowedType
                throw new \InvalidArgumentException('All max_permissions values must be non-empty strings.');
            }
        }

        $boundary = Boundary::query()->updateOrCreate(
            ['scope' => $scope],
            ['max_permissions' => $maxPermissions],
        );

        $boundary->getConnection()->afterCommit(function () use ($boundary): void {
            Event::dispatch(new BoundarySet($boundary));
        });
    }

    /**
     * Remove the boundary for a scope.
     *
     * Silently succeeds if no boundary exists for the given scope.
     */
    public function remove(string $scope): void
    {
        $deleted = Boundary::query()->where('scope', $scope)->delete();

        if ($deleted > 0) {
            Boundary::query()->getConnection()->afterCommit(function () use ($scope): void {
                Event::dispatch(new BoundaryRemoved($scope));
            });
        }
    }

    public function find(string $scope): ?Boundary
    {
        return Boundary::query()->where('scope', $scope)->first();
    }

    /**
     * Get all boundaries.
     *
     * @return Collection<int, Boundary>
     */
    public function all(): Collection
    {
        return Boundary::query()->get();
    }

    /**
     * Remove all boundaries, dispatching BoundaryRemoved for each.
     */
    public function removeAll(): void
    {
        Boundary::query()->get()->each(function (Boundary $boundary): void {
            $boundary->delete();
            Event::dispatch(new BoundaryRemoved($boundary->scope));
        });
    }
}
