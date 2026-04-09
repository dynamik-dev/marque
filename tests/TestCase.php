<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Tests;

use DynamikDev\Marque\MarqueServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private const array DATABASE_CONNECTIONS = [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'marque_test',
            'username' => 'test',
            'password' => 'test',
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'marque_test',
            'username' => 'test',
            'password' => 'test',
        ],
    ];

    protected function getPackageProviders($app): array
    {
        return [
            MarqueServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $connection = env('DB_CONNECTION', 'sqlite');

        $config = self::DATABASE_CONNECTIONS[$connection]
            ?? throw new \RuntimeException("Unknown test DB connection: {$connection}");

        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}", $config);
    }
}
