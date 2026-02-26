<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\Middleware\CanDoMiddleware;
use DynamikDev\PolicyEngine\Middleware\RoleMiddleware;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class PolicyEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/policy-engine.php', 'policy-engine');
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('can_do', CanDoMiddleware::class);
        $router->aliasMiddleware('role', RoleMiddleware::class);

        $this->registerBladeDirectives();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ListPermissionsCommand::class,
                Commands\ListRolesCommand::class,
                Commands\ListAssignmentsCommand::class,
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
}
