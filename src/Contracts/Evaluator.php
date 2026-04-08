<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;

interface Evaluator
{
    /**
     * Determine whether a subject holds a given permission.
     */
    public function can(string $subjectType, string|int $subjectId, string $permission): bool;

    /**
     * Evaluate a permission and return a detailed trace of the decision.
     */
    public function explain(string $subjectType, string|int $subjectId, string $permission): EvaluationTrace;

    /**
     * Check whether a subject has a specific role assignment.
     */
    public function hasRole(string $subjectType, string|int $subjectId, string $role, ?string $scope = null): bool;

    /**
     * Collect all effective permissions for a subject, optionally within a scope.
     *
     * @return array<int, string>
     */
    public function effectivePermissions(string $subjectType, string|int $subjectId, ?string $scope = null): array;
}
