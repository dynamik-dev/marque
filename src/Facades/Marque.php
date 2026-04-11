<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Facades;

use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\ImportResult;
use DynamikDev\Marque\MarqueManager;
use DynamikDev\Marque\Support\BoundaryBuilder;
use DynamikDev\Marque\Support\ResourcePolicyBuilder;
use DynamikDev\Marque\Support\RoleBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void permissions(array<int, string> $permissions)
 * @method static RoleBuilder role(string $id, string $name, bool $system = false)
 * @method static ResourcePolicyBuilder resource(string $resourceType)
 * @method static BoundaryBuilder boundary(string $scope, ?array<int, string> $maxPermissions = null)
 * @method static ImportResult import(string $pathOrContent, ?ImportOptions $options = null)
 * @method static string export(?string $scope = null)
 * @method static void exportToFile(string $path, ?string $scope = null)
 *
 * @see MarqueManager
 */
class Marque extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MarqueManager::class;
    }
}
