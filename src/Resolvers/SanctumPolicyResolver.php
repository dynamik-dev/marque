<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Resolvers;

use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\PolicyResolver;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Models\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class SanctumPolicyResolver implements PolicyResolver
{
    public function __construct(
        private readonly Matcher $matcher,
        private readonly PermissionStore $permissionStore,
    ) {}

    /**
     * @return Collection<int, PolicyStatement>
     */
    public function resolve(EvaluationRequest $request): Collection
    {
        if (! class_exists(PersonalAccessToken::class)) {
            return collect();
        }

        $user = $this->resolvePrincipalUser($request);

        if ($user === null) {
            return collect();
        }

        if (! method_exists($user, 'currentAccessToken')) {
            return collect();
        }

        /** @var PersonalAccessToken|TransientToken|null $token */
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return collect();
        }

        $abilities = $token->abilities ?? [];

        if (in_array('*', $abilities, true)) {
            return collect();
        }

        $normalizedAbilities = array_map(
            fn (string $ability): string => $this->normalizeAbility($ability),
            $abilities,
        );

        return $this->permissionStore->all()
            ->reject(fn (Permission $permission) => $this->matchesAnyAbility($permission->id, $normalizedAbilities))
            ->map(fn (Permission $permission) => new PolicyStatement(
                effect: Effect::Deny,
                action: $permission->id,
                principalPattern: null,
                resourcePattern: null,
                conditions: [],
                source: 'sanctum-token',
            ))
            ->values();
    }

    /**
     * Resolve the user that the evaluation is being performed for.
     *
     * Sanctum tokens are tied to the active HTTP request: a personal access
     * token is hydrated onto the User model only by Sanctum's guard during the
     * request that authenticated with it. There is no clean way to look up
     * "the currently active token" for an arbitrary user — the token lives on
     * the in-memory model instance, not in a query-able state on the database
     * row.
     *
     * As a result, this resolver can only apply Sanctum filtering when the
     * request principal is the same identity as the authenticated user. For
     * out-of-band evaluations (e.g. `marque:explain` for user X while the CLI
     * runs as user Y, or batch processing), Sanctum context is not available
     * and we fall back gracefully by returning null — the resolver then emits
     * no Deny statements, and other resolvers (Identity, Boundary, etc.) make
     * the decision.
     */
    private function resolvePrincipalUser(EvaluationRequest $request): ?object
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        /** @var string|int $authId */
        $authId = $user->getAuthIdentifier();

        if (
            $user->getMorphClass() !== $request->principal->type
            || (string) $authId !== (string) $request->principal->id
        ) {
            return null;
        }

        return $user;
    }

    /**
     * Normalize a Sanctum ability for matching against marque permission IDs.
     *
     * Sanctum operators commonly use colon syntax (e.g. `server:read`), but marque
     * permission IDs are dot-notated (e.g. `server.read`) and the Matcher treats
     * `::` as a scope delimiter. To prevent a silent deny-all when colon abilities
     * are present, we map any single `:` to `.` so colon syntax matches its
     * dot-notation equivalent. The marque scope delimiter `::` is preserved.
     *
     * Supported ability formats:
     *   - dot-notation matching marque permissions: `posts.create`
     *   - colon syntax (normalized): `server:read` -> `server.read`
     *   - wildcards: `posts.*`, `*.create`
     *   - all-abilities: `*` (handled earlier, never reaches this method)
     */
    private function normalizeAbility(string $ability): string
    {
        if (! str_contains($ability, ':')) {
            return $ability;
        }

        // Preserve the marque scope delimiter `::` while normalizing single `:`.
        $placeholder = "\0SCOPE\0";
        $withPlaceholder = str_replace('::', $placeholder, $ability);
        $normalized = str_replace(':', '.', $withPlaceholder);

        return str_replace($placeholder, '::', $normalized);
    }

    /**
     * Check whether the given permission matches any of the token abilities.
     *
     * @param  array<int, string>  $abilities
     */
    private function matchesAnyAbility(string $permissionId, array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if ($this->matcher->matches($ability, $permissionId)) {
                return true;
            }
        }

        return false;
    }
}
