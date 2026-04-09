<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Resolvers;

use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\Enums\Effect;
use DynamikDev\PolicyEngine\Models\Permission;
use Illuminate\Support\Collection;

class BoundaryPolicyResolver implements PolicyResolver
{
    public function __construct(
        private readonly BoundaryStore $boundaries,
        private readonly Matcher $matcher,
        private readonly PermissionStore $permissionStore,
        private readonly bool $denyUnboundedScopes,
        private readonly bool $enforceOnGlobal,
    ) {}

    /**
     * @return Collection<int, PolicyStatement>
     */
    public function resolve(EvaluationRequest $request): Collection
    {
        $scope = $request->context->scope;

        if ($scope === null) {
            return $this->resolveGlobal();
        }

        return $this->resolveScoped($scope);
    }

    /**
     * @return Collection<int, PolicyStatement>
     */
    private function resolveGlobal(): Collection
    {
        if (! $this->enforceOnGlobal) {
            return collect();
        }

        $allBoundaries = $this->boundaries->all();
        $ceilingPatterns = $allBoundaries
            ->flatMap(fn ($boundary) => $boundary->max_permissions)
            ->unique()
            ->values()
            ->all();

        return $this->denyPermissionsOutsideCeiling($ceilingPatterns, 'boundary:global');
    }

    /**
     * @return Collection<int, PolicyStatement>
     */
    private function resolveScoped(string $scope): Collection
    {
        $boundary = $this->boundaries->find($scope);

        if ($boundary !== null) {
            return $this->denyPermissionsOutsideCeiling(
                ceilingPatterns: $boundary->max_permissions,
                source: "boundary:{$scope}",
            );
        }

        if ($this->denyUnboundedScopes) {
            return $this->denyAllPermissions("boundary:unbounded:{$scope}");
        }

        return collect();
    }

    /**
     * Produce Deny statements for all registered permissions that do not match
     * any of the given ceiling patterns.
     *
     * @param  array<int, string>  $ceilingPatterns
     * @return Collection<int, PolicyStatement>
     */
    private function denyPermissionsOutsideCeiling(array $ceilingPatterns, string $source): Collection
    {
        return $this->permissionStore->all()
            ->reject(fn (Permission $permission) => $this->matchesCeiling($permission->id, $ceilingPatterns))
            ->map(fn (Permission $permission) => new PolicyStatement(
                effect: Effect::Deny,
                action: $permission->id,
                principalPattern: null,
                resourcePattern: null,
                conditions: [],
                source: $source,
            ))
            ->values();
    }

    /**
     * Produce Deny statements for ALL registered permissions.
     *
     * @return Collection<int, PolicyStatement>
     */
    private function denyAllPermissions(string $source): Collection
    {
        return $this->permissionStore->all()
            ->map(fn (Permission $permission) => new PolicyStatement(
                effect: Effect::Deny,
                action: $permission->id,
                principalPattern: null,
                resourcePattern: null,
                conditions: [],
                source: $source,
            ))
            ->values();
    }

    /**
     * Check whether the given permission matches any of the ceiling patterns.
     *
     * @param  array<int, string>  $ceilingPatterns
     */
    private function matchesCeiling(string $permissionId, array $ceilingPatterns): bool
    {
        foreach ($ceilingPatterns as $pattern) {
            if ($this->matcher->matches($pattern, $permissionId)) {
                return true;
            }
        }

        return false;
    }
}
