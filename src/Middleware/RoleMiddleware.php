<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Middleware;

use Closure;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\ScopeResolver;
use Illuminate\Http\Request;
use LogicException;
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
            throw new LogicException(
                "RoleMiddleware is configured with scope parameter [{$scopeParam}], but the current route has no parameter by that name. "
                ."Either add {{$scopeParam}} to the route URI or remove the scope argument from the middleware declaration."
            );
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
