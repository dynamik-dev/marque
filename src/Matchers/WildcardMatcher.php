<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Matchers;

use DynamikDev\Marque\Contracts\Matcher;

class WildcardMatcher implements Matcher
{
    /**
     * Maximum number of dot-separated segments allowed in a permission pattern.
     *
     * Limits recursion depth in the backtracking algorithm to prevent
     * pathological patterns from causing excessive CPU usage.
     */
    private const int MAX_SEGMENTS = 10;

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
     *
     * Returns false if either pattern exceeds MAX_SEGMENTS dot-separated segments.
     */
    public function matches(string $granted, string $required): bool
    {
        if ($granted === '' || $required === '') {
            return false;
        }

        [$grantedPermission, $grantedScope] = $this->splitScope($granted);
        [$requiredPermission, $requiredScope] = $this->splitScope($required);

        if (! $this->scopeMatches($grantedScope, $requiredScope)) {
            return false;
        }

        $grantedSegments = explode('.', $grantedPermission);
        $requiredSegments = explode('.', $requiredPermission);

        if (count($grantedSegments) > self::MAX_SEGMENTS || count($requiredSegments) > self::MAX_SEGMENTS) {
            return false;
        }

        return $this->segmentsMatch($grantedSegments, $requiredSegments);
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
        return $this->doSegmentsMatch($granted, 0, $required, 0);
    }

    /**
     * Recursive helper that matches granted segments against required segments
     * starting from the given indices, with proper backtracking for wildcards.
     *
     * @param  array<int, string>  $granted
     * @param  array<int, string>  $required
     */
    private function doSegmentsMatch(array $granted, int $gi, array $required, int $ri): bool
    {
        $grantedCount = count($granted);
        $requiredCount = count($required);

        while ($gi < $grantedCount && $ri < $requiredCount) {
            if ($granted[$gi] === '*') {
                // If this is the last granted segment, it matches all remaining required segments.
                if ($gi === $grantedCount - 1) {
                    return true;
                }

                /*
                 * Try consuming 1, 2, 3... required segments for the wildcard,
                 * then recursively match the rest of the pattern.
                 */
                $gi++;
                for ($skip = $ri + 1; $skip <= $requiredCount; $skip++) {
                    if ($this->doSegmentsMatch($granted, $gi, $required, $skip)) {
                        return true;
                    }
                }

                return false;
            }

            if ($granted[$gi] !== $required[$ri]) {
                return false;
            }

            $gi++;
            $ri++;
        }

        // Both must be fully consumed for an exact match.
        return $gi === $grantedCount && $ri === $requiredCount;
    }
}
