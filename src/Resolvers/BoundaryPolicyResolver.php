<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Resolvers;

use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\PolicyResolver;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Models\Permission;
use Illuminate\Support\Collection;

class BoundaryPolicyResolver implements PolicyResolver
{
    /**
     * Sentinel scope that operators register a boundary against to constrain
     * unscoped (global) authorization requests. Looked up via
     * `BoundaryStore::find(self::GLOBAL_SCOPE)` only when `enforceOnGlobal`
     * is true and the request has no scope.
     */
    public const GLOBAL_SCOPE = 'global';

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
     * Resolve the ceiling for an unscoped (global) request.
     *
     * When `enforceOnGlobal` is enabled, we look up a single dedicated
     * boundary stored at `self::GLOBAL_SCOPE` and treat its `max_permissions`
     * as the ceiling. We deliberately do NOT union per-scope boundaries here:
     * a global request must not inherit `group::5`'s ceiling just because
     * that scope happens to define one. If no global boundary is registered,
     * this resolver is a no-op for the global request.
     *
     * @return Collection<int, PolicyStatement>
     */
    private function resolveGlobal(): Collection
    {
        if (! $this->enforceOnGlobal) {
            return collect();
        }

        $boundary = $this->boundaries->find(self::GLOBAL_SCOPE);

        if ($boundary === null) {
            return collect();
        }

        return $this->denyPermissionsOutsideCeiling(
            ceilingPatterns: $boundary->max_permissions,
            source: 'boundary:global',
        );
    }

    /**
     * Resolve the ceiling for a scoped request.
     *
     * If a boundary exists for the scope, generate Deny statements for every
     * permission outside the ceiling. If no boundary exists and
     * `denyUnboundedScopes` is enabled, deny ALL permissions for the scope.
     *
     * WARNING: `denyUnboundedScopes` is a coarse, "scope-must-be-pre-registered"
     * toggle. When enabled it deny-locks every permission for any unbounded
     * scope, INCLUDING permissions granted via direct-permission assignments
     * (the synthetic `__dp.*` roles produced by `HasPermissions::assign()`).
     * This resolver has no role context and cannot distinguish a direct grant
     * from a role-based grant, so a deny here will override a user's
     * explicitly granted direct permission. Operators should only enable
     * `denyUnboundedScopes` when the intended policy is "no authorization
     * may occur on any scope an operator has not explicitly bounded" —
     * otherwise leave it disabled and let unbounded scopes pass through.
     *
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
