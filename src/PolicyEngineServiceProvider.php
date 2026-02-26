<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentExporter;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter;
use DynamikDev\PolicyEngine\Documents\JsonDocumentParser;
use DynamikDev\PolicyEngine\Evaluators\CachedEvaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Events\PermissionDeleted;
use DynamikDev\PolicyEngine\Events\RoleDeleted;
use DynamikDev\PolicyEngine\Events\RoleUpdated;
use DynamikDev\PolicyEngine\Listeners\InvalidatePermissionCache;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Middleware\CanDoMiddleware;
use DynamikDev\PolicyEngine\Middleware\RoleMiddleware;
use DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PolicyEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/policy-engine.php', 'policy-engine');

        $this->app->bind(PermissionStore::class, EloquentPermissionStore::class);
        $this->app->bind(RoleStore::class, EloquentRoleStore::class);
        $this->app->bind(AssignmentStore::class, EloquentAssignmentStore::class);
        $this->app->bind(BoundaryStore::class, EloquentBoundaryStore::class);
        $this->app->bind(Matcher::class, WildcardMatcher::class);
        $this->app->bind(ScopeResolver::class, ModelScopeResolver::class);
        $this->app->bind(DocumentParser::class, JsonDocumentParser::class);
        $this->app->bind(DocumentImporter::class, DefaultDocumentImporter::class);
        $this->app->bind(DocumentExporter::class, DefaultDocumentExporter::class);

        $this->app->bind(Evaluator::class, function ($app): CachedEvaluator {
            return new CachedEvaluator(
                inner: new DefaultEvaluator(
                    assignments: $app->make(AssignmentStore::class),
                    roles: $app->make(RoleStore::class),
                    boundaries: $app->make(BoundaryStore::class),
                    matcher: $app->make(Matcher::class),
                ),
                cache: $app->make(CacheManager::class),
            );
        });

        $this->app->singleton(PrimitivesManager::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('can_do', CanDoMiddleware::class);
        $router->aliasMiddleware('role', RoleMiddleware::class);

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

    /**
     * Register Blade conditional directives for permission and role checks.
     */
    private function registerBladeDirectives(): void
    {
        Blade::if('canDo', function (string $permission, mixed $scope = null): bool {
            $user = auth()->user();

            if ($user === null) {
                return false;
            }

            return $user->canDo($permission, $scope);
        });

        Blade::if('cannotDo', function (string $permission, mixed $scope = null): bool {
            $user = auth()->user();

            if ($user === null) {
                return false;
            }

            return $user->cannotDo($permission, $scope);
        });

        Blade::if('hasRole', function (string $role, mixed $scope = null): bool {
            $user = auth()->user();

            if ($user === null) {
                return false;
            }

            $assignmentStore = app(AssignmentStore::class);

            if ($scope !== null) {
                $resolvedScope = app(ScopeResolver::class)->resolve($scope);

                return $assignmentStore->forSubjectInScope(
                    $user->getMorphClass(),
                    $user->getKey(),
                    $resolvedScope,
                )->contains('role_id', $role);
            }

            return $assignmentStore->forSubject(
                $user->getMorphClass(),
                $user->getKey(),
            )->contains('role_id', $role);
        });
    }

    /**
     * Register event listeners for cache invalidation.
     */
    private function registerEventListeners(): void
    {
        Event::listen(AssignmentCreated::class, InvalidatePermissionCache::class);
        Event::listen(AssignmentRevoked::class, InvalidatePermissionCache::class);
        Event::listen(RoleUpdated::class, InvalidatePermissionCache::class);
        Event::listen(RoleDeleted::class, InvalidatePermissionCache::class);
        Event::listen(PermissionDeleted::class, InvalidatePermissionCache::class);
    }
}
