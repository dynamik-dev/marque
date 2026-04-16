<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Events\AssignmentCreated;
use DynamikDev\Marque\Events\AssignmentRevoked;
use DynamikDev\Marque\Models\Assignment;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class EloquentAssignmentStore implements AssignmentStore
{
    public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
    {
        $existing = Assignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('role_id', $roleId)
            ->where(fn ($q) => $scope === null ? $q->whereNull('scope') : $q->where('scope', $scope))
            ->exists();

        if ($existing) {
            return;
        }

        try {
            $assignment = Assignment::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'role_id' => $roleId,
                'scope' => $scope,
            ]);
        } catch (QueryException $e) {
            // A concurrent assign() lost the race against the partial unique index from migration 0006.
            // Treat as idempotent success (per AssignmentStore::assign contract) and do not dispatch
            // AssignmentCreated, since this process did not perform the insert.
            if ($this->isUniqueConstraintViolation($e)) {
                return;
            }

            throw $e;
        }

        $assignment->getConnection()->afterCommit(function () use ($assignment): void {
            Event::dispatch(new AssignmentCreated($assignment));
        });
    }

    /**
     * Detect a unique-constraint violation across MySQL, Postgres, and SQLite.
     *
     * MySQL/MariaDB: SQLSTATE 23000 with driver code 1062.
     * Postgres: SQLSTATE 23505 (unique_violation).
     * SQLite: SQLSTATE 23000 with driver code 19 (constraint) or 2067 (unique).
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = $e->getCode();
        $driverCode = $e->errorInfo[1] ?? null;

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState === '23000') {
            return in_array($driverCode, [1062, 19, 2067], true);
        }

        return false;
    }

    public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
    {
        $assignment = Assignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('role_id', $roleId)
            ->when(
                $scope === null,
                static fn ($query) => $query->whereNull('scope'),
                static fn ($query) => $query->where('scope', $scope),
            )
            ->first();

        if ($assignment) {
            $assignment->delete();
            $assignment->getConnection()->afterCommit(function () use ($assignment): void {
                Event::dispatch(new AssignmentRevoked($assignment));
            });
        }
    }

    /**
     * Get all assignments for a subject across all scopes.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubject(string $subjectType, string|int $subjectId): Collection
    {
        return Assignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get();
    }

    /**
     * Get global (unscoped) assignments for a subject.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubjectGlobal(string $subjectType, string|int $subjectId): Collection
    {
        return Assignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('scope')
            ->get();
    }

    /**
     * Get assignments for a subject that are either global or in the given scope.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubjectGlobalAndScope(string $subjectType, string|int $subjectId, string $scope): Collection
    {
        return Assignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where(static function ($query) use ($scope): void {
                $query->whereNull('scope')
                    ->orWhere('scope', $scope);
            })
            ->get();
    }

    /**
     * Get all assignments for a subject within a specific scope.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection
    {
        return Assignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('scope', $scope)
            ->get();
    }

    /**
     * Get all subjects assigned within a scope, optionally filtered by role.
     *
     * @return Collection<int, Assignment>
     */
    public function subjectsInScope(string $scope, ?string $roleId = null): Collection
    {
        return Assignment::query()
            ->where('scope', $scope)
            ->when($roleId, static fn ($query, string $roleId) => $query->where('role_id', $roleId))
            ->get();
    }

    /**
     * Get all assignments.
     *
     * @return Collection<int, Assignment>
     */
    public function all(): Collection
    {
        return Assignment::query()->get();
    }

    /**
     * Remove all assignments, dispatching AssignmentRevoked for each.
     */
    public function removeAll(): void
    {
        $revoked = [];

        Assignment::query()->chunkById(200, function (Collection $assignments) use (&$revoked): void {
            $assignments->each(function (Assignment $assignment) use (&$revoked): void {
                $revoked[] = clone $assignment;
                $assignment->delete();
            });
        });

        /** @var Connection $connection */
        $connection = Assignment::query()->getConnection();
        $connection->afterCommit(function () use (&$revoked): void {
            foreach ($revoked as $assignment) {
                Event::dispatch(new AssignmentRevoked($assignment));
            }
        });
    }
}
