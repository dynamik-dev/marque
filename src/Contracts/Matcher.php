<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Contracts;

interface Matcher
{
    /**
     * Determine whether a granted permission pattern matches a required permission.
     */
    public function matches(string $granted, string $required): bool;
}
