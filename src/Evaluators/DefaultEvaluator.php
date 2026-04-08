<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Evaluators;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Enums\EvaluationResult;
use DynamikDev\PolicyEngine\Events\AuthorizationDenied;
use DynamikDev\PolicyEngine\Models\Assignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;

class DefaultEvaluator implements Evaluator
{
    public function __construct(
        private readonly AssignmentStore $assignments,
        private readonly RoleStore $roles,
        private readonly BoundaryStore $boundaries,
        private readonly Matcher $matcher,
    ) {}

    public function can(string $subjectType, string|int $subjectId, string $permission): bool
    {
        [$requiredPermission, $scope] = $this->parseScope($permission);

        $allAssignments = $this->gatherAssignments($subjectType, $subjectId, $scope);

        if ($allAssignments->isEmpty()) {
            $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

            return false;
        }

        if (! $this->passesBoundaryCheck($scope, $requiredPermission)) {
            $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

            return false;
        }

        $allows = [];
        $denies = [];
        $permissionsByRole = $this->permissionsByRole($allAssignments);

        foreach ($permissionsByRole as $permissions) {
            foreach ($permissions as $permission) {
                if (str_starts_with($permission, '!')) {
                    $denies[] = $permission;
                } else {
                    $allows[] = $permission;
                }
            }
        }

        foreach ($denies as $deny) {
            if ($this->matcher->matches(substr($deny, 1), $requiredPermission)) {
                $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);

                return false;
            }
        }

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
        $permissionsByRole = $this->permissionsByRole($allAssignments);

        foreach ($allAssignments as $assignment) {
            $permissions = $permissionsByRole[$assignment->role_id] ?? [];

            $traceAssignments[] = [
                'role' => $assignment->role_id,
                'scope' => $assignment->scope,
                'permissions_checked' => $permissions,
            ];
        }

        foreach ($permissionsByRole as $permissions) {
            foreach ($permissions as $permission) {
                if (str_starts_with($permission, '!')) {
                    $denies[] = $permission;
                } else {
                    $allows[] = $permission;
                }
            }
        }

        $boundaryNote = null;
        $result = EvaluationResult::Deny;

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

            if ($boundary === null && config('policy-engine.deny_unbounded_scopes')) {
                $boundaryNote = "Denied by missing boundary on scope [{$scope}] (deny_unbounded_scopes enabled)";

                return new EvaluationTrace(
                    subject: $subjectType.':'.$subjectId,
                    required: $requiredPermission,
                    result: $result,
                    assignments: $traceAssignments,
                    boundary: $boundaryNote,
                    cacheHit: false,
                );
            }
        }

        if ($scope === null && config('policy-engine.enforce_boundaries_on_global')) {
            $allBoundaries = $this->boundaries->all();

            if ($allBoundaries->isNotEmpty()) {
                $passesAny = false;
                foreach ($allBoundaries as $boundary) {
                    if ($this->matchesAny($boundary->max_permissions, $requiredPermission)) {
                        $passesAny = true;
                        break;
                    }
                }

                if (! $passesAny) {
                    $boundaryNote = 'Denied by global boundary enforcement (enforce_boundaries_on_global enabled)';

                    return new EvaluationTrace(
                        subject: $subjectType.':'.$subjectId,
                        required: $requiredPermission,
                        result: $result,
                        assignments: $traceAssignments,
                        boundary: $boundaryNote,
                        cacheHit: false,
                    );
                }

                $boundaryNote = 'Passed global boundary enforcement';
            }
        }

        foreach ($denies as $deny) {
            if ($this->matcher->matches(substr($deny, 1), $requiredPermission)) {
                return new EvaluationTrace(
                    subject: $subjectType.':'.$subjectId,
                    required: $requiredPermission,
                    result: EvaluationResult::Deny,
                    assignments: $traceAssignments,
                    boundary: $boundaryNote,
                    cacheHit: false,
                );
            }
        }

        foreach ($allows as $allow) {
            if ($this->matcher->matches($allow, $requiredPermission)) {
                $result = EvaluationResult::Allow;
                break;
            }
        }

        $sanctumNote = null;

        if ($result === EvaluationResult::Allow && ! $this->sanctumTokenAllows($subjectType, $subjectId, $requiredPermission)) {
            $result = EvaluationResult::Deny;
            $sanctumNote = 'Denied by Sanctum token ability restriction';
        }

        return new EvaluationTrace(
            subject: $subjectType.':'.$subjectId,
            required: $requiredPermission,
            result: $result,
            assignments: $traceAssignments,
            boundary: $boundaryNote,
            cacheHit: false,
            sanctum: $sanctumNote,
        );
    }

    public function hasRole(string $subjectType, string|int $subjectId, string $role, ?string $scope = null): bool
    {
        if ($scope !== null) {
            return $this->assignments->forSubjectInScope($subjectType, $subjectId, $scope)
                ->contains('role_id', $role);
        }

        return $this->assignments->forSubjectGlobal($subjectType, $subjectId)
            ->contains('role_id', $role);
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
        $permissionsByRole = $this->permissionsByRole($allAssignments);

        foreach ($permissionsByRole as $permissions) {
            foreach ($permissions as $permission) {
                if (str_starts_with($permission, '!')) {
                    $denies[] = substr($permission, 1);
                } else {
                    $allows[] = $permission;
                }
            }
        }

        // Remove any allowed permission that is matched by a deny rule or blocked by a boundary.
        return array_values(array_filter(
            array_unique($allows),
            fn (string $allow): bool => ! $this->isDenied($allow, $denies)
                && $this->passesBoundaryCheck($scope, $allow),
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
     * @return Collection<int, Assignment>
     */
    private function gatherAssignments(string $subjectType, string|int $subjectId, ?string $scope): Collection
    {
        if ($scope === null) {
            return $this->assignments->forSubjectGlobal($subjectType, $subjectId);
        }

        return $this->assignments->forSubjectGlobalAndScope($subjectType, $subjectId, $scope);
    }

    private function passesBoundaryCheck(?string $scope, string $requiredPermission): bool
    {
        if ($scope === null) {
            if (! config('policy-engine.enforce_boundaries_on_global')) {
                return true;
            }

            return $this->passesGlobalBoundaryCheck($requiredPermission);
        }

        $boundary = $this->boundaries->find($scope);

        if ($boundary !== null) {
            return $this->matchesAny($boundary->max_permissions, $requiredPermission);
        }

        return ! config('policy-engine.deny_unbounded_scopes');
    }

    /**
     * Check whether a permission passes boundary checks across all defined boundaries.
     *
     * When enforce_boundaries_on_global is enabled, global (unscoped) checks must
     * pass at least one boundary's max_permissions. If no boundaries exist at all,
     * the check passes (no boundaries means no restrictions).
     */
    private function passesGlobalBoundaryCheck(string $requiredPermission): bool
    {
        $allBoundaries = $this->boundaries->all();

        if ($allBoundaries->isEmpty()) {
            return true;
        }

        foreach ($allBoundaries as $boundary) {
            if ($this->matchesAny($boundary->max_permissions, $requiredPermission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve permissions per role with optional batched loading.
     *
     * @param  Collection<int, Assignment>  $assignments
     * @return array<string, array<int, string>>
     */
    private function permissionsByRole(Collection $assignments): array
    {
        /** @var array<int, string> $roleIds */
        $roleIds = $assignments->pluck('role_id')->unique()->values()->all();

        if ($roleIds === []) {
            return [];
        }

        return $this->roles->permissionsForRoles($roleIds);
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
        if (! class_exists(PersonalAccessToken::class)) {
            return true;
        }

        $user = auth()->user();

        if ($user === null) {
            return true;
        }

        // Only apply token scoping if the authenticated user matches the subject being evaluated.
        /** @var int|string $userKey */
        $userKey = $user->getKey();

        if ($user->getMorphClass() !== $subjectType || (string) $userKey !== (string) $subjectId) {
            return true;
        }

        if (! method_exists($user, 'currentAccessToken')) {
            return true;
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return true;
        }

        /** @var array<int, string> $abilities */
        $abilities = $token->abilities; // @phpstan-ignore property.notFound (Sanctum model lacks @property PHPDoc)

        foreach ($abilities as $ability) {
            if ($ability === '*' || $this->matcher->matches($ability, $requiredPermission)) {
                return true;
            }
        }

        return false;
    }

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
