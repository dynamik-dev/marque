<?php

declare(strict_types=1);

namespace DynamikDev\Marque;

use DynamikDev\Marque\Conditions\AttributeEqualsEvaluator;
use DynamikDev\Marque\Conditions\AttributeInEvaluator;
use DynamikDev\Marque\Conditions\DefaultConditionRegistry;
use DynamikDev\Marque\Conditions\EnvironmentEqualsEvaluator;
use DynamikDev\Marque\Conditions\IpRangeEvaluator;
use DynamikDev\Marque\Conditions\TimeBetweenEvaluator;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\ConditionRegistry;
use DynamikDev\Marque\Contracts\DocumentExporter;
use DynamikDev\Marque\Contracts\DocumentImporter;
use DynamikDev\Marque\Contracts\DocumentParser;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\PolicyResolver;
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Contracts\ScopeResolver;
use DynamikDev\Marque\Documents\DefaultDocumentExporter;
use DynamikDev\Marque\Documents\DefaultDocumentImporter;
use DynamikDev\Marque\Documents\JsonDocumentParser;
use DynamikDev\Marque\DTOs\Resource;
use DynamikDev\Marque\Evaluators\CachedEvaluator;
use DynamikDev\Marque\Evaluators\DefaultEvaluator;
use DynamikDev\Marque\Events\AssignmentCreated;
use DynamikDev\Marque\Events\AssignmentRevoked;
use DynamikDev\Marque\Events\BoundaryRemoved;
use DynamikDev\Marque\Events\BoundarySet;
use DynamikDev\Marque\Events\DocumentImported;
use DynamikDev\Marque\Events\PermissionCreated;
use DynamikDev\Marque\Events\PermissionDeleted;
use DynamikDev\Marque\Events\ResourcePolicyAttached;
use DynamikDev\Marque\Events\ResourcePolicyDetached;
use DynamikDev\Marque\Events\RoleDeleted;
use DynamikDev\Marque\Events\RoleUpdated;
use DynamikDev\Marque\Listeners\InvalidatePermissionCache;
use DynamikDev\Marque\Matchers\WildcardMatcher;
use DynamikDev\Marque\Middleware\RoleMiddleware;
use DynamikDev\Marque\Resolvers\BoundaryPolicyResolver;
use DynamikDev\Marque\Resolvers\ModelScopeResolver;
use DynamikDev\Marque\Resolvers\SanctumPolicyResolver;
use DynamikDev\Marque\Stores\CachingBoundaryStore;
use DynamikDev\Marque\Stores\CachingPermissionStore;
use DynamikDev\Marque\Stores\EloquentAssignmentStore;
use DynamikDev\Marque\Stores\EloquentBoundaryStore;
use DynamikDev\Marque\Stores\EloquentPermissionStore;
use DynamikDev\Marque\Stores\EloquentResourcePolicyStore;
use DynamikDev\Marque\Stores\EloquentRoleStore;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;

class MarqueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/marque.php', 'marque');

        // Reset memoized cache store when the app is re-bootstrapped (testing).
        CacheStoreResolver::reset();

        $this->app->singleton(PermissionStore::class, function ($app): CachingPermissionStore {
            return new CachingPermissionStore(
                inner: new EloquentPermissionStore,
                cache: $app->make(CacheManager::class),
            );
        });
        $this->app->singleton(RoleStore::class, EloquentRoleStore::class);
        $this->app->singleton(AssignmentStore::class, EloquentAssignmentStore::class);
        $this->app->singleton(BoundaryStore::class, function ($app): CachingBoundaryStore {
            return new CachingBoundaryStore(
                inner: new EloquentBoundaryStore,
                cache: $app->make(CacheManager::class),
            );
        });
        $this->app->singleton(ResourcePolicyStore::class, EloquentResourcePolicyStore::class);
        $this->app->singleton(Matcher::class, WildcardMatcher::class);
        $this->app->singleton(ScopeResolver::class, ModelScopeResolver::class);
        $this->app->singleton(DocumentParser::class, function ($app): JsonDocumentParser {
            return new JsonDocumentParser(
                conditionRegistry: $app->make(ConditionRegistry::class),
            );
        });
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

        $this->app->singleton(SanctumPolicyResolver::class, function ($app): SanctumPolicyResolver {
            return new SanctumPolicyResolver(
                matcher: $app->make(Matcher::class),
                permissionStore: $app->make(PermissionStore::class),
            );
        });

        $this->app->singleton(BoundaryPolicyResolver::class, function ($app): BoundaryPolicyResolver {
            return new BoundaryPolicyResolver(
                boundaries: $app->make(BoundaryStore::class),
                matcher: $app->make(Matcher::class),
                permissionStore: $app->make(PermissionStore::class),
                denyUnboundedScopes: (bool) config('marque.deny_unbounded_scopes', false),
                enforceOnGlobal: (bool) config('marque.enforce_boundaries_on_global', false),
            );
        });

        $this->app->singleton(Evaluator::class, function ($app): CachedEvaluator {
            /** @var array<int, string> $resolverClasses */
            $resolverClasses = config('marque.resolvers', []);
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

        $this->app->singleton(MarqueManager::class);
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
                Commands\CacheClearAliasCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/marque.php' => config_path('marque.php'),
            ], 'marque-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'marque-migrations');
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

            /** @var array<int, string> $passthrough */
            $passthrough = config('marque.gate_passthrough', []);
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
     * @return array{0: mixed, 1: ?DTOs\Resource}
     */
    private static function resolveGateArguments(array $arguments): array
    {
        $first = $arguments[0] ?? null;
        $second = $arguments[1] ?? null;

        if ($first === null) {
            $resource = match (true) {
                $second instanceof Resource => $second,
                is_object($second) && method_exists($second, 'toPolicyResource') => $second->toPolicyResource(),
                default => null,
            };

            return [null, $resource];
        }

        if ($second === null) {
            if ($first instanceof Resource) {
                return [null, $first];
            }
            if (is_object($first) && method_exists($first, 'toPolicyResource')) {
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
        Event::listen(ResourcePolicyAttached::class, InvalidatePermissionCache::class);
        Event::listen(ResourcePolicyDetached::class, InvalidatePermissionCache::class);

        // Octane: reset memoized cache store between requests to prevent stale state.
        if (class_exists(RequestTerminated::class)) {
            Event::listen(
                RequestTerminated::class,
                static fn () => CacheStoreResolver::reset(),
            );
        }
    }
}
