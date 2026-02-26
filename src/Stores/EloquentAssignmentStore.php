<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Stores;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Models\Assignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class EloquentAssignmentStore implements AssignmentStore
{
    /**
     * Assign a role to a subject, optionally within a scope.
     */
    public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
    {
        $assignment = Assignment::query()->firstOrCreate([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'role_id' => $roleId,
            'scope' => $scope,
        ]);

        if ($assignment->wasRecentlyCreated) {
            Event::dispatch(new AssignmentCreated($assignment));
        }
    }

    /**
     * Revoke a role from a subject, optionally within a scope.
     */
    public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void
    {
        $assignment = Assignment::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('role_id', $roleId)
            ->where('scope', $scope)
            ->first();

        if ($assignment) {
            $assignment->delete();
            Event::dispatch(new AssignmentRevoked($assignment));
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
            ->when($roleId, fn ($query, string $roleId) => $query->where('role_id', $roleId))
            ->get();
    }
}
