<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

use Illuminate\Support\Collection;

interface AssignmentStore
{
    /**
     * Assign a role to a subject, optionally within a scope.
     */
    public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void;

    /**
     * Revoke a role from a subject, optionally within a scope.
     */
    public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void;

    /**
     * Get all assignments for a subject across all scopes.
     *
     * @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment>
     */
    public function forSubject(string $subjectType, string|int $subjectId): Collection;

    /**
     * Get all assignments for a subject within a specific scope.
     *
     * @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment>
     */
    public function forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection;

    /**
     * Get all subjects assigned within a scope, optionally filtered by role.
     *
     * @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment>
     */
    public function subjectsInScope(string $scope, ?string $roleId = null): Collection;
}
