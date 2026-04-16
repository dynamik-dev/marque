<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

use DynamikDev\Marque\Models\Assignment;
use Illuminate\Support\Collection;

interface AssignmentStore
{
    /**
     * Assign a role to a subject, optionally within a scope.
     *
     * Idempotent: assigning the same (subject, role, scope) tuple more than once is a silent no-op
     * and does not throw, even under concurrent execution.
     */
    public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void;

    /**
     * Revoke a role from a subject, optionally within a scope.
     */
    public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void;

    /**
     * Get all assignments for a subject across all scopes.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubject(string $subjectType, string|int $subjectId): Collection;

    /**
     * Get all assignments for a subject within a specific scope.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection;

    /**
     * Get global (unscoped) assignments for a subject.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubjectGlobal(string $subjectType, string|int $subjectId): Collection;

    /**
     * Get assignments for a subject that are either global or in the given scope.
     *
     * @return Collection<int, Assignment>
     */
    public function forSubjectGlobalAndScope(string $subjectType, string|int $subjectId, string $scope): Collection;

    /**
     * Get all subjects assigned within a scope, optionally filtered by role.
     *
     * @return Collection<int, Assignment>
     */
    public function subjectsInScope(string $scope, ?string $roleId = null): Collection;

    /**
     * Get all assignments.
     *
     * @return Collection<int, Assignment>
     */
    public function all(): Collection;

    /**
     * Remove all assignments, dispatching events for each.
     */
    public function removeAll(): void;
}
