<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Facades;

use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\PrimitivesManager;
use DynamikDev\PolicyEngine\Support\RoleBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void permissions(array $permissions)
 * @method static RoleBuilder role(string $id, string $name, bool $system = false)
 * @method static void boundary(string $scope, array $maxPermissions)
 * @method static ImportResult import(string $pathOrContent, ?ImportOptions $options = null)
 * @method static string export(?string $scope = null)
 * @method static void exportToFile(string $path, ?string $scope = null)
 *
 * @see PrimitivesManager
 */
class Primitives extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PrimitivesManager::class;
    }
}
