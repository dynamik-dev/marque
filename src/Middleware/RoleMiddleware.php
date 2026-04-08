<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Middleware;

use Closure;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function __construct(
        private readonly AssignmentStore $assignmentStore,
        private readonly ScopeResolver $scopeResolver,
    ) {}

    /**
     * Handle an incoming request by checking that the authenticated
     * user holds the required role, optionally within a scope.
     */
    public function handle(Request $request, Closure $next, string $role, ?string $scopeParam = null): Response
    {
        if (! $request->user()) {
            abort(401);
        }

        $user = $request->user();

        if ($scopeParam !== null && $request->route($scopeParam) === null) {
            abort(403, "Scope parameter [{$scopeParam}] not found in route.");
        }

        $resolvedScope = $scopeParam !== null
            ? $this->scopeResolver->resolve($request->route($scopeParam))
            : null;

        /** @var int|string $subjectId */
        $subjectId = $user->getKey();

        if ($resolvedScope !== null) {
            $assignments = $this->assignmentStore->forSubjectGlobalAndScope(
                $user->getMorphClass(),
                $subjectId,
                $resolvedScope,
            );
        } else {
            $assignments = $this->assignmentStore->forSubjectGlobal(
                $user->getMorphClass(),
                $subjectId,
            );
        }

        if (! $assignments->contains('role_id', $role)) {
            abort(403);
        }

        return $next($request);
    }
}
