<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Middleware;

use Closure;
use DynamikDev\PolicyEngine\Concerns\HasPermissions;
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

        if (! in_array(HasPermissions::class, class_uses_recursive($user), true)) {
            abort(403);
        }

        if ($user->cannotDo($permission, $resolvedScope)) {
            abort(403);
        }

        return $next($request);
    }
}
