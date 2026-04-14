<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Events\BoundaryRemoved;
use DynamikDev\Marque\Events\BoundarySet;
use DynamikDev\Marque\Models\Boundary;
use Illuminate\Database\Connection;
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
            /** @var Connection $connection */
            $connection = Boundary::query()->getConnection();
            $connection->afterCommit(function () use ($scope): void {
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
        $scopes = [];

        Boundary::query()->get()->each(function (Boundary $boundary) use (&$scopes): void {
            $scopes[] = $boundary->scope;
            $boundary->delete();
        });

        /** @var Connection $connection */
        $connection = Boundary::query()->getConnection();
        $connection->afterCommit(function () use (&$scopes): void {
            foreach ($scopes as $scope) {
                Event::dispatch(new BoundaryRemoved($scope));
            }
        });
    }
}
