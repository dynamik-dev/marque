<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Resolvers;

use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\Enums\Effect;
use DynamikDev\PolicyEngine\Models\Permission;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

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

        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        /** @var string|int $authId */
        $authId = $user->getAuthIdentifier();

        if (
            $user->getMorphClass() !== $request->principal->type
            || (string) $authId !== (string) $request->principal->id
        ) {
            return collect();
        }

        if (! method_exists($user, 'currentAccessToken')) {
            return collect();
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return collect();
        }

        $abilities = $token->abilities ?? [];

        if (in_array('*', $abilities, true)) {
            return collect();
        }

        return $this->permissionStore->all()
            ->reject(fn (Permission $permission) => $this->matchesAnyAbility($permission->id, $abilities))
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
