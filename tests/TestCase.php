<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Tests;

use DynamikDev\PolicyEngine\PolicyEngineServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PolicyEngineServiceProvider::class,
        ];
    }
}
