<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Evaluators;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Events\AuthorizationDenied;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class DefaultEvaluator implements Evaluator
{
    public function __construct(
        private readonly AssignmentStore $assignments,
        private readonly RoleStore $roles,
        private readonly BoundaryStore $boundaries,
        private readonly Matcher $matcher,
    ) {}

    /**
     * Determine whether a subject holds a given permission.
     */
    public function can(string $subjectType, string|int $subjectId, string $permission): bool
    {
        [$requiredPermission, $scope] = $this->parseScope($permission);

        $allAssignments = $this->gatherAssignments($subjectType, $subjectId, $scope);

        if ($allAssignments->isEmpty()) {
            $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

            return false;
        }

        $allows = [];
        $denies = [];

        foreach ($allAssignments as $assignment) {
            foreach ($this->roles->permissionsFor($assignment->role_id) as $perm) {
                if (str_starts_with($perm, '!')) {
                    $denies[] = $perm;
                } else {
                    $allows[] = $perm;
                }
            }
        }

        // Boundary check: if a scope is present and a boundary exists,
        // verify the required permission is covered by at least one max_permissions entry.
        if ($scope !== null) {
            $boundary = $this->boundaries->find($scope);

            if ($boundary !== null && ! $this->matchesAny($boundary->max_permissions, $requiredPermission)) {
                $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

                return false;
            }
        }

        // Deny wins: if any deny permission matches the required permission, deny.
        foreach ($denies as $deny) {
            if ($this->matcher->matches(substr($deny, 1), $requiredPermission)) {
                $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

                return false;
            }
        }

        // Check for an allow match.
        $roleAllowed = false;
        foreach ($allows as $allow) {
            if ($this->matcher->matches($allow, $requiredPermission)) {
                $roleAllowed = true;
                break;
            }
        }

        if (! $roleAllowed) {
            $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

            return false;
        }

        // Sanctum token scoping: if the authenticated user has a Sanctum token,
        // the permission must also be covered by the token's abilities.
        if (! $this->sanctumTokenAllows($subjectType, $subjectId, $requiredPermission)) {
            $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

            return false;
        }

        return true;
    }

    /**
     * Evaluate a permission and return a detailed trace of the decision.
     *
     * @throws \RuntimeException If explain mode is disabled in config.
     */
    public function explain(string $subjectType, string|int $subjectId, string $permission): EvaluationTrace
    {
        if (! config('policy-engine.explain')) {
            throw new \RuntimeException('Explain mode is disabled. Set policy-engine.explain to true.');
        }

        [$requiredPermission, $scope] = $this->parseScope($permission);

        $allAssignments = $this->gatherAssignments($subjectType, $subjectId, $scope);

        $traceAssignments = [];
        $allows = [];
        $denies = [];

        foreach ($allAssignments as $assignment) {
            $permissions = $this->roles->permissionsFor($assignment->role_id);

            $traceAssignments[] = [
                'role' => $assignment->role_id,
                'scope' => $assignment->scope,
                'permissions_checked' => $permissions,
            ];

            foreach ($permissions as $perm) {
                if (str_starts_with($perm, '!')) {
                    $denies[] = $perm;
                } else {
                    $allows[] = $perm;
                }
            }
        }

        $boundaryNote = null;
        $result = 'deny';

        // Boundary check.
        if ($scope !== null) {
            $boundary = $this->boundaries->find($scope);

            if ($boundary !== null) {
                if (! $this->matchesAny($boundary->max_permissions, $requiredPermission)) {
                    $boundaryNote = "Denied by boundary on scope [{$scope}]";

                    return new EvaluationTrace(
                        subject: $subjectType.':'.$subjectId,
                        required: $requiredPermission,
                        result: $result,
                        assignments: $traceAssignments,
                        boundary: $boundaryNote,
                        cacheHit: false,
                    );
                }

                $boundaryNote = "Passed boundary on scope [{$scope}]";
            }
        }

        // Deny wins.
        foreach ($denies as $deny) {
            if ($this->matcher->matches(substr($deny, 1), $requiredPermission)) {
                return new EvaluationTrace(
                    subject: $subjectType.':'.$subjectId,
                    required: $requiredPermission,
                    result: 'deny',
                    assignments: $traceAssignments,
                    boundary: $boundaryNote,
                    cacheHit: false,
                );
            }
        }

        // Allow check.
        foreach ($allows as $allow) {
            if ($this->matcher->matches($allow, $requiredPermission)) {
                $result = 'allow';
                break;
            }
        }

        return new EvaluationTrace(
            subject: $subjectType.':'.$subjectId,
            required: $requiredPermission,
            result: $result,
            assignments: $traceAssignments,
            boundary: $boundaryNote,
            cacheHit: false,
        );
    }

    /**
     * Collect all effective permissions for a subject, optionally within a scope.
     *
     * @return array<int, string>
     */
    public function effectivePermissions(string $subjectType, string|int $subjectId, ?string $scope = null): array
    {
        $allAssignments = $this->gatherAssignments($subjectType, $subjectId, $scope);

        $allows = [];
        $denies = [];

        foreach ($allAssignments as $assignment) {
            foreach ($this->roles->permissionsFor($assignment->role_id) as $perm) {
                if (str_starts_with($perm, '!')) {
                    $denies[] = substr($perm, 1);
                } else {
                    $allows[] = $perm;
                }
            }
        }

        // Remove any allowed permission that is matched by a deny rule.
        return array_values(array_filter(
            array_unique($allows),
            fn (string $allow): bool => ! $this->isDenied($allow, $denies),
        ));
    }

    /**
     * Parse an optional scope suffix from a permission string.
     *
     * Format: `permission:scope` (e.g., `posts.create:group::5`).
     * The first colon separates permission from scope. The scope itself
     * may contain colons (e.g., `group::5`).
     *
     * @return array{0: string, 1: ?string}
     */
    private function parseScope(string $permission): array
    {
        $colonPos = strpos($permission, ':');

        if ($colonPos === false) {
            return [$permission, null];
        }

        return [
            substr($permission, 0, $colonPos),
            substr($permission, $colonPos + 1),
        ];
    }

    /**
     * Gather all relevant assignments for a subject.
     *
     * If a scope is present, returns both global (unscoped) assignments
     * and assignments specific to that scope. Otherwise, returns only
     * global assignments.
     *
     * @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment>
     */
    private function gatherAssignments(string $subjectType, string|int $subjectId, ?string $scope): Collection
    {
        if ($scope === null) {
            return $this->assignments->forSubject($subjectType, $subjectId)
                ->filter(fn ($assignment): bool => $assignment->scope === null);
        }

        $global = $this->assignments->forSubject($subjectType, $subjectId)
            ->filter(fn ($assignment): bool => $assignment->scope === null);

        $scoped = $this->assignments->forSubjectInScope($subjectType, $subjectId, $scope);

        return $global->merge($scoped);
    }

    /**
     * Check whether any pattern in the given list matches the required permission.
     *
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(array $patterns, string $required): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matcher->matches($pattern, $required)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether an allowed permission is denied by any deny rule.
     *
     * @param  array<int, string>  $denies  Deny patterns (already stripped of `!` prefix).
     */
    private function isDenied(string $allow, array $denies): bool
    {
        foreach ($denies as $deny) {
            if ($this->matcher->matches($deny, $allow)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the current Sanctum token (if any) allows the required permission.
     *
     * Returns true if there is no Sanctum token (session auth) or if the token's
     * abilities include a matching permission. Returns false only when a Sanctum
     * token is present but does not grant the required permission.
     */
    private function sanctumTokenAllows(string $subjectType, string|int $subjectId, string $requiredPermission): bool
    {
        if (! class_exists(\Laravel\Sanctum\PersonalAccessToken::class)) {
            return true;
        }

        $user = auth()->user();

        if ($user === null) {
            return true;
        }

        // Only apply token scoping if the authenticated user matches the subject being evaluated.
        if ($user->getMorphClass() !== $subjectType || $user->getKey() != $subjectId) {
            return true;
        }

        if (! method_exists($user, 'currentAccessToken')) {
            return true;
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            return true;
        }

        // Check if any of the token's abilities match the required permission.
        foreach ($token->abilities as $ability) {
            if ($ability === '*' || $this->matcher->matches($ability, $requiredPermission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dispatch an AuthorizationDenied event if log_denials is enabled.
     */
    private function dispatchDenialIfEnabled(string $subjectType, string|int $subjectId, string $permission, ?string $scope): void
    {
        if (config('policy-engine.log_denials')) {
            Event::dispatch(new AuthorizationDenied(
                subject: $subjectType.':'.$subjectId,
                permission: $permission,
                scope: $scope,
            ));
        }
    }
}
