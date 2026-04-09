<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine;

use DynamikDev\PolicyEngine\Conditions\AttributeEqualsEvaluator;
use DynamikDev\PolicyEngine\Conditions\AttributeInEvaluator;
use DynamikDev\PolicyEngine\Conditions\DefaultConditionRegistry;
use DynamikDev\PolicyEngine\Conditions\EnvironmentEqualsEvaluator;
use DynamikDev\PolicyEngine\Conditions\IpRangeEvaluator;
use DynamikDev\PolicyEngine\Conditions\TimeBetweenEvaluator;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\ConditionRegistry;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentExporter;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter;
use DynamikDev\PolicyEngine\Documents\JsonDocumentParser;
use DynamikDev\PolicyEngine\DTOs\Resource;
use DynamikDev\PolicyEngine\Evaluators\CachedEvaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Events\BoundaryRemoved;
use DynamikDev\PolicyEngine\Events\BoundarySet;
use DynamikDev\PolicyEngine\Events\DocumentImported;
use DynamikDev\PolicyEngine\Events\PermissionCreated;
use DynamikDev\PolicyEngine\Events\PermissionDeleted;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use DynamikDev\PolicyEngine\Listeners\InvalidatePermissionCache;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Middleware\RoleMiddleware;
use DynamikDev\PolicyEngine\Resolvers\BoundaryPolicyResolver;
use DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver;
use DynamikDev\PolicyEngine\Stores\CachingBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;

class PolicyEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/policy-engine.php', 'policy-engine');

        // Reset memoized cache store when the app is re-bootstrapped (testing).
        CacheStoreResolver::reset();

        $this->app->singleton(PermissionStore::class, EloquentPermissionStore::class);
        $this->app->singleton(RoleStore::class, EloquentRoleStore::class);
        $this->app->singleton(AssignmentStore::class, EloquentAssignmentStore::class);
        $this->app->singleton(BoundaryStore::class, EloquentBoundaryStore::class);
        $this->app->singleton(Matcher::class, WildcardMatcher::class);
        $this->app->singleton(ScopeResolver::class, ModelScopeResolver::class);
        $this->app->singleton(DocumentParser::class, JsonDocumentParser::class);
        $this->app->singleton(DocumentImporter::class, DefaultDocumentImporter::class);
        $this->app->singleton(DocumentExporter::class, DefaultDocumentExporter::class);

        $this->app->singleton(ConditionRegistry::class, function (): DefaultConditionRegistry {
            $registry = new DefaultConditionRegistry;
            $registry->register('attribute_equals', AttributeEqualsEvaluator::class);
            $registry->register('attribute_in', AttributeInEvaluator::class);
            $registry->register('environment_equals', EnvironmentEqualsEvaluator::class);
            $registry->register('ip_range', IpRangeEvaluator::class);
            $registry->register('time_between', TimeBetweenEvaluator::class);

            return $registry;
        });

        $this->app->singleton(BoundaryPolicyResolver::class, function ($app) {
            return new BoundaryPolicyResolver(
                boundaries: new CachingBoundaryStore(
                    inner: $app->make(BoundaryStore::class),
                    cache: $app->make(CacheManager::class),
                ),
                matcher: $app->make(Matcher::class),
                permissionStore: $app->make(PermissionStore::class),
                denyUnboundedScopes: (bool) config('policy-engine.deny_unbounded_scopes', false),
                enforceOnGlobal: (bool) config('policy-engine.enforce_boundaries_on_global', false),
            );
        });

        $this->app->singleton(Evaluator::class, function ($app): CachedEvaluator {
            $resolverClasses = (array) config('policy-engine.resolvers', []);
            $resolvers = array_map(
                fn (string $class): PolicyResolver => $app->make($class),
                $resolverClasses,
            );

            return new CachedEvaluator(
                inner: new DefaultEvaluator(
                    resolvers: $resolvers,
                    matcher: $app->make(Matcher::class),
                    conditionRegistry: $app->make(ConditionRegistry::class),
                ),
                cache: $app->make(CacheManager::class),
            );
        });

        $this->app->singleton(PolicyEngineManager::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('role', RoleMiddleware::class);

        $this->registerGateHook();
        $this->registerBladeDirectives();
        $this->registerEventListeners();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ListPermissionsCommand::class,
                Commands\ListRolesCommand::class,
                Commands\ListAssignmentsCommand::class,
                Commands\ExplainCommand::class,
                Commands\ImportCommand::class,
                Commands\ExportCommand::class,
                Commands\ValidateCommand::class,
                Commands\SyncCommand::class,
                Commands\CacheClearCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/policy-engine.php' => config_path('policy-engine.php'),
            ], 'policy-engine-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'policy-engine-migrations');
        }
    }

    private function registerBladeDirectives(): void
    {
        Blade::if('hasRole', static function (string $role, mixed $scope = null, ?string $guard = null): bool {
            $user = auth($guard)->user();

            if ($user === null) {
                return false;
            }

            return method_exists($user, 'hasRole') && $user->hasRole($role, $scope);
        });
    }

    private function registerGateHook(): void
    {
        Gate::before(static function (Authenticatable $user, string $ability, array $arguments): ?bool {
            if (! str_contains($ability, '.')) {
                return null;
            }

            $passthrough = config('policy-engine.gate_passthrough', []);
            if (in_array($ability, $passthrough, true)) {
                return null;
            }

            if (! method_exists($user, 'canDo')) {
                return null;
            }

            [$scope, $resource] = self::resolveGateArguments($arguments);

            return $user->canDo($ability, $scope, $resource);
        });
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return array{0: mixed, 1: ?resource}
     */
    private static function resolveGateArguments(array $arguments): array
    {
        $first = $arguments[0] ?? null;
        $second = $arguments[1] ?? null;

        if ($first === null) {
            return [null, null];
        }

        if ($second === null) {
            if ($first instanceof Resource) {
                return [null, $first];
            }
            if (method_exists($first, 'toPolicyResource')) {
                return [null, $first->toPolicyResource()];
            }

            return [$first, null];
        }

        $resource = match (true) {
            $second instanceof Resource => $second,
            is_object($second) && method_exists($second, 'toPolicyResource') => $second->toPolicyResource(),
            default => null,
        };

        return [$first, $resource];
    }

    private function registerEventListeners(): void
    {
        Event::listen(AssignmentCreated::class, InvalidatePermissionCache::class);
        Event::listen(AssignmentRevoked::class, InvalidatePermissionCache::class);
        Event::listen(RoleUpdated::class, InvalidatePermissionCache::class);
        Event::listen(RoleDeleted::class, InvalidatePermissionCache::class);
        Event::listen(PermissionDeleted::class, InvalidatePermissionCache::class);
        Event::listen(BoundarySet::class, InvalidatePermissionCache::class);
        Event::listen(BoundaryRemoved::class, InvalidatePermissionCache::class);
        Event::listen(PermissionCreated::class, InvalidatePermissionCache::class);
        Event::listen(DocumentImported::class, InvalidatePermissionCache::class);

        // Octane: reset memoized cache store between requests to prevent stale state.
        if (class_exists(RequestTerminated::class)) {
            Event::listen(
                RequestTerminated::class,
                static fn () => CacheStoreResolver::reset(),
            );
        }
    }
}
