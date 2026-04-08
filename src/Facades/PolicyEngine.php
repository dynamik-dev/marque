<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Facades;

use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\PolicyEngineManager;
use DynamikDev\PolicyEngine\Support\RoleBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void permissions(array<int, string> $permissions)
 * @method static RoleBuilder role(string $id, string $name, bool $system = false)
 * @method static void boundary(string $scope, array<int, string> $maxPermissions)
 * @method static ImportResult import(string $pathOrContent, ?ImportOptions $options = null)
 * @method static string export(?string $scope = null)
 * @method static void exportToFile(string $path, ?string $scope = null)
 *
 * @see PolicyEngineManager
 */
class PolicyEngine extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PolicyEngineManager::class;
    }
}
