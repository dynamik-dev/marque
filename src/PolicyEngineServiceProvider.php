<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine;

use DynamikDev\PolicyEngine\Middleware\CanDoMiddleware;
use DynamikDev\PolicyEngine\Middleware\RoleMiddleware;
use Illuminate\Routing\Router;
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

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/policy-engine.php' => config_path('policy-engine.php'),
            ], 'policy-engine-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'policy-engine-migrations');
        }
    }
}
