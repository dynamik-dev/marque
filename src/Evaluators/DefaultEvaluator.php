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
        $trace = $this->evaluate($subjectType, $subjectId, $permission);

        if ($trace->result === EvaluationResult::Deny) {
            [$requiredPermission, $scope] = $this->parseScope($permission);
            $this->dispatchDenialIfEnabled($subjectType, $subjectId, $requiredPermission, $scope);
        }

        return $trace->result === EvaluationResult::Allow;
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

        return $this->evaluate($subjectType, $subjectId, $permission);
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

        $boundaryMaxPermissions = $this->resolveBoundaryMaxPermissions($scope);

        // Remove any allowed permission that is matched by a deny rule or blocked by a boundary.
        return array_values(array_filter(
            array_unique($allows),
            fn (string $allow): bool => ! $this->isDenied($allow, $denies)
                && $this->passesResolvedBoundary($boundaryMaxPermissions, $allow),
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

    /**
     * Run the full evaluation pipeline and return a trace of the decision.
     */
    private function evaluate(string $subjectType, string|int $subjectId, string $permission): EvaluationTrace
    {
        [$requiredPermission, $scope] = $this->parseScope($permission);
        $subject = $subjectType.':'.$subjectId;

        $allAssignments = $this->gatherAssignments($subjectType, $subjectId, $scope);

        if ($allAssignments->isEmpty()) {
            return new EvaluationTrace(
                subject: $subject,
                required: $requiredPermission,
                result: EvaluationResult::Deny,
                assignments: [],
                boundary: null,
                cacheHit: false,
            );
        }

        [$passesBoundary, $boundaryNote] = $this->evaluateBoundary($scope, $requiredPermission);

        if (! $passesBoundary) {
            return new EvaluationTrace(
                subject: $subject,
                required: $requiredPermission,
                result: EvaluationResult::Deny,
                assignments: [],
                boundary: $boundaryNote,
                cacheHit: false,
            );
        }

        $permissionsByRole = $this->permissionsByRole($allAssignments);

        $traceAssignments = [];
        foreach ($allAssignments as $assignment) {
            $traceAssignments[] = [
                'role' => $assignment->role_id,
                'scope' => $assignment->scope,
                'permissions_checked' => $permissionsByRole[$assignment->role_id] ?? [],
            ];
        }

        $allows = [];
        $denies = [];

        foreach ($permissionsByRole as $permissions) {
            foreach ($permissions as $perm) {
                if (str_starts_with($perm, '!')) {
                    $denies[] = $perm;
                } else {
                    $allows[] = $perm;
                }
            }
        }

        foreach ($denies as $deny) {
            if ($this->matcher->matches(substr($deny, 1), $requiredPermission)) {
                return new EvaluationTrace(
                    subject: $subject,
                    required: $requiredPermission,
                    result: EvaluationResult::Deny,
                    assignments: $traceAssignments,
                    boundary: $boundaryNote,
                    cacheHit: false,
                );
            }
        }

        $result = EvaluationResult::Deny;
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
            subject: $subject,
            required: $requiredPermission,
            result: $result,
            assignments: $traceAssignments,
            boundary: $boundaryNote,
            cacheHit: false,
            sanctum: $sanctumNote,
        );
    }

    /**
     * Evaluate boundary constraints and return [passes, note].
     *
     * @return array{0: bool, 1: ?string}
     */
    private function evaluateBoundary(?string $scope, string $requiredPermission): array
    {
        if ($scope !== null) {
            $boundary = $this->boundaries->find($scope);

            if ($boundary !== null) {
                $passes = $this->matchesAny($boundary->max_permissions, $requiredPermission);

                return [
                    $passes,
                    $passes
                        ? "Passed boundary on scope [{$scope}]"
                        : "Denied by boundary on scope [{$scope}]",
                ];
            }

            if (config('policy-engine.deny_unbounded_scopes')) {
                return [false, "Denied by missing boundary on scope [{$scope}] (deny_unbounded_scopes enabled)"];
            }

            return [true, null];
        }

        if (! config('policy-engine.enforce_boundaries_on_global')) {
            return [true, null];
        }

        $allBoundaries = $this->boundaries->all();

        if ($allBoundaries->isEmpty()) {
            return [true, null];
        }

        foreach ($allBoundaries as $boundary) {
            if ($this->matchesAny($boundary->max_permissions, $requiredPermission)) {
                return [true, 'Passed global boundary enforcement'];
            }
        }

        return [false, 'Denied by global boundary enforcement (enforce_boundaries_on_global enabled)'];
    }

    /**
     * Pre-fetch boundary max_permissions for a scope, returning null if no boundary applies.
     *
     * Returns null when boundaries don't restrict (no boundary check needed),
     * an empty array when boundaries block everything, or the max_permissions arrays to match against.
     *
     * @return array<int, array<int, string>>|null
     */
    private function resolveBoundaryMaxPermissions(?string $scope): ?array
    {
        if ($scope === null) {
            if (! config('policy-engine.enforce_boundaries_on_global')) {
                return null;
            }

            $allBoundaries = $this->boundaries->all();

            if ($allBoundaries->isEmpty()) {
                return null;
            }

            return $allBoundaries->map(fn ($b): array => $b->max_permissions)->values()->all();
        }

        $boundary = $this->boundaries->find($scope);

        if ($boundary !== null) {
            return [$boundary->max_permissions];
        }

        // No boundary defined for this scope — deny everything if deny_unbounded_scopes is enabled.
        return config('policy-engine.deny_unbounded_scopes') ? [[]] : null;
    }

    /**
     * Check a permission against pre-resolved boundary max_permissions.
     *
     * @param  array<int, array<int, string>>|null  $boundaryGroups  null means no restriction.
     */
    private function passesResolvedBoundary(?array $boundaryGroups, string $permission): bool
    {
        if ($boundaryGroups === null) {
            return true;
        }

        foreach ($boundaryGroups as $maxPermissions) {
            if ($this->matchesAny($maxPermissions, $permission)) {
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
