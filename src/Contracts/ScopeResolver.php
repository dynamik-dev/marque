<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Contracts;

interface ScopeResolver
{
    /**
     * Resolve a scope value into its canonical string representation.
     *
     * Accepts a Scopeable model, a raw string, or null.
     * Returns the `type::id` string, or null if unresolvable.
     */
    public function resolve(mixed $scope): ?string;
}
