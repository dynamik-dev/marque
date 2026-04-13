<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Facades;

use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\ImportResult;
use DynamikDev\Marque\MarqueManager;
use DynamikDev\Marque\Models\Boundary;
use DynamikDev\Marque\Models\Permission;
use DynamikDev\Marque\Models\Role;
use DynamikDev\Marque\Support\BoundaryBuilder;
use DynamikDev\Marque\Support\RoleBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void permissions(array<int, string> $permissions)
 * @method static Permission|null getPermission(string $id)
 * @method static RoleBuilder createRole(string $id, string $name, bool $system = false)
 * @method static Role|null getRole(string $id)
 * @method static RoleBuilder role(string $id)
 * @method static BoundaryBuilder boundary(mixed $scope)
 * @method static BoundaryBuilder createBoundary(mixed $scope)
 * @method static Boundary|null getBoundary(mixed $scope)
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
