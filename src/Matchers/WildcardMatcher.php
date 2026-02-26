<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Matchers;

use DynamikDev\PolicyEngine\Contracts\Matcher;

class WildcardMatcher implements Matcher
{
    /**
     * Determine whether a granted permission pattern matches a required permission.
     *
     * Permissions are dot-notated (`resource.verb.qualifier`) with an optional
     * colon-separated scope suffix (`permission:scope`). A `*` segment in the
     * granted pattern matches one or more segments at that position.
     *
     * Scope rules:
     *  - Unscoped grants cover any scope (including scoped checks).
     *  - Scoped grants must match the required scope exactly.
     */
    public function matches(string $granted, string $required): bool
    {
        [$grantedPermission, $grantedScope] = $this->splitScope($granted);
        [$requiredPermission, $requiredScope] = $this->splitScope($required);

        if (! $this->scopeMatches($grantedScope, $requiredScope)) {
            return false;
        }

        return $this->segmentsMatch(
            explode('.', $grantedPermission),
            explode('.', $requiredPermission),
        );
    }

    /**
     * Split a permission string into its permission and scope parts.
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitScope(string $permission): array
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
     * Check whether a granted scope matches a required scope.
     *
     * An unscoped grant (null) covers any scope. A scoped grant must
     * match the required scope exactly.
     */
    private function scopeMatches(?string $grantedScope, ?string $requiredScope): bool
    {
        if ($grantedScope === null) {
            return true;
        }

        return $grantedScope === $requiredScope;
    }

    /**
     * Match dot-separated permission segments, supporting wildcards.
     *
     * A `*` segment in the granted pattern matches one or more remaining
     * segments at that position. Literal segments must match exactly.
     *
     * @param  array<int, string>  $granted
     * @param  array<int, string>  $required
     */
    private function segmentsMatch(array $granted, array $required): bool
    {
        $grantedCount = count($granted);
        $requiredCount = count($required);

        $gi = 0;
        $ri = 0;

        while ($gi < $grantedCount && $ri < $requiredCount) {
            if ($granted[$gi] === '*') {
                // If this is the last granted segment, it matches all remaining required segments.
                if ($gi === $grantedCount - 1) {
                    return true;
                }

                // Advance to the next granted segment and try to find it in the remaining required segments.
                $gi++;
                while ($ri < $requiredCount && $granted[$gi] !== $required[$ri] && $granted[$gi] !== '*') {
                    $ri++;
                }

                continue;
            }

            if ($granted[$gi] !== $required[$ri]) {
                return false;
            }

            $gi++;
            $ri++;
        }

        // Both must be fully consumed for an exact match.
        // Remaining granted segments that are all `*` can match zero-or-more, but
        // per spec a `*` matches "one or more" — so leftover wildcards mean no match
        // unless the required side is also exhausted naturally.
        return $gi === $grantedCount && $ri === $requiredCount;
    }
}
