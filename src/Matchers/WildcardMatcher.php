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
     * scope suffix joined by `::` (`permission::type::id`, e.g. `posts.create::group::5`).
     * A `*` segment in the granted pattern matches zero or more segments at that
     * position (see segmentsMatch for the rationale and BC impact).
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
     * The canonical delimiter between a permission ID and its scope is `::`,
     * matching the convention used by SubjectParser and Scopeable::toScope.
     * For input `posts.create::group::5`, the first `::` separates the
     * permission (`posts.create`) from the scope (`group::5`).
     *
     * Backward compatibility: the legacy single-colon form (`permission:scope`)
     * is intentionally NOT supported. Permission IDs are dot-notated and never
     * contain a `:`, so a string containing only a single colon (e.g.
     * `posts.create:group::5`) is treated as an unsplittable permission ID with
     * no scope. It will fail to match any real granted permission rather than
     * silently producing a malformed scope (the prior behavior split on the
     * first `:` and returned `:group::5` as the scope, leaking a leading colon).
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitScope(string $permission): array
    {
        $separatorPos = strpos($permission, '::');

        if ($separatorPos === false) {
            return [$permission, null];
        }

        return [
            substr($permission, 0, $separatorPos),
            substr($permission, $separatorPos + 2),
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
     * Wildcard semantics: `*` matches ZERO or more segments. Concretely, this
     * means a trailing `*` consumes the empty tail, so:
     *  - `posts.*` matches both `posts` and `posts.create`
     *  - `posts.delete.*` matches both `posts.delete` and `posts.delete.own`
     *  - `*` matches the empty pattern as well as any non-empty permission
     *    (note: `matches()` rejects empty inputs upstream, so the empty case
     *    is unreachable from the public API).
     *
     * Rationale: operators write `posts.*` intuitively expecting it to cover
     * "all posts permissions, including the bare `posts` action." The previous
     * one-or-more semantics violated that expectation and, worse, caused
     * boundary ceilings of `posts.delete.*` to silently deny the exact
     * permission `posts.delete`.
     *
     * BC impact: this widens the set of permissions matched by any pattern
     * containing `*`. Concretely:
     *  - Role grants become more permissive (a grant of `posts.*` now also
     *    grants the bare `posts` permission).
     *  - Boundary ceilings become more permissive in the same way (a ceiling
     *    of `posts.*` no longer excludes the bare `posts` permission from the
     *    boundary).
     *  - Deny rules (permissions prefixed with `!`) become broader, since the
     *    deny pattern now matches more permissions. This is generally the safer
     *    direction, but operators with deny lists like `!posts.delete.*` should
     *    be aware that the bare `posts.delete` is now included in the deny.
     * Mid-pattern wildcard behavior is preserved: a `*` between two literals
     * still consumes one or more segments in the most natural reading
     * (e.g. `a.*.c` continues to match `a.b.c`, `a.b.d.c`, etc.). The
     * zero-or-more change applies only when the wildcard would otherwise
     * leave required segments unmatched OR when the trailing wildcard would
     * fail because required is exhausted.
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

        while ($gi < $grantedCount) {
            if ($granted[$gi] === '*') {
                // If this is the last granted segment, it matches all remaining
                // required segments — including zero, which is the key
                // zero-or-more semantic that lets `posts.*` match bare `posts`.
                if ($gi === $grantedCount - 1) {
                    return true;
                }

                /*
                 * Mid-pattern wildcard: try consuming 0, 1, 2... required
                 * segments, then recursively match the rest of the pattern.
                 * Starting at $skip = $ri preserves the zero-or-more semantic
                 * for non-trailing wildcards as well.
                 */
                $gi++;
                for ($skip = $ri; $skip <= $requiredCount; $skip++) {
                    if ($this->doSegmentsMatch($granted, $gi, $required, $skip)) {
                        return true;
                    }
                }

                return false;
            }

            // Literal segment requires a corresponding required segment to compare against.
            if ($ri >= $requiredCount || $granted[$gi] !== $required[$ri]) {
                return false;
            }

            $gi++;
            $ri++;
        }

        // Granted is fully consumed; required must also be fully consumed.
        return $ri === $requiredCount;
    }
}
