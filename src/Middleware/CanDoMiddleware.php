<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Middleware;

use Closure;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanDoMiddleware
{
    public function __construct(
        private readonly ScopeResolver $scopeResolver,
    ) {}

    /**
     * Handle an incoming request by checking that the authenticated
     * user holds the required permission, optionally within a scope.
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $scopeParam = null): Response
    {
        if (! $request->user()) {
            abort(401);
        }

        $resolvedScope = $scopeParam !== null
            ? $this->scopeResolver->resolve($request->route($scopeParam))
            : null;

        $user = $request->user();

        if (method_exists($user, 'cannotDo') && $user->cannotDo($permission, $resolvedScope)) {
            abort(403);
        }

        return $next($request);
    }
}
