<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Resolvers;

use DynamikDev\Marque\Contracts\ScopeResolver;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ModelScopeResolver implements ScopeResolver
{
    /**
     * Resolve a scope value into its canonical `type::id` string.
     *
     * Accepts null (pass-through), a raw string (returned as-is), or an
     * Eloquent Model that exposes a `toScope()` method via the Scopeable trait.
     *
     * @throws InvalidArgumentException If the scope is an unsupported type.
     */
    public function resolve(mixed $scope): ?string
    {
        if ($scope === null) {
            return null;
        }

        if (is_string($scope)) {
            return $scope;
        }

        if ($scope instanceof Model && method_exists($scope, 'toScope')) {
            return $scope->toScope();
        }

        throw new InvalidArgumentException(
            'Scope must be null, a string, or an Eloquent Model with a toScope() method. Got: '.get_debug_type($scope)
        );
    }
}
