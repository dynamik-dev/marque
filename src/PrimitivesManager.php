<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine;

use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\Support\PathValidator;
use DynamikDev\PolicyEngine\Support\RoleBuilder;

class PrimitivesManager
{
    public function __construct(
        private readonly PermissionStore $permissions,
        private readonly RoleStore $roles,
        private readonly BoundaryStore $boundaries,
        private readonly DocumentParser $parser,
        private readonly DocumentImporter $importer,
        private readonly DocumentExporter $exporter,
    ) {}

    /**
     * Register one or more permissions.
     *
     * @param  array<int, string>  $permissions
     */
    public function permissions(array $permissions): void
    {
        $this->permissions->register($permissions);
    }

    /**
     * Create or update a role and return a fluent builder for granting permissions.
     */
    public function role(string $id, string $name, bool $system = false): RoleBuilder
    {
        $this->roles->save($id, $name, [], $system);

        return new RoleBuilder($this->roles, $id);
    }

    /**
     * Set the maximum allowed permissions for a scope.
     *
     * @param  array<int, string>  $maxPermissions
     */
    public function boundary(string $scope, array $maxPermissions): void
    {
        $this->boundaries->set($scope, $maxPermissions);
    }

    /**
     * Import a policy document from a file path or raw content string.
     */
    public function import(string $pathOrContent, ?ImportOptions $options = null): ImportResult
    {
        if (file_exists($pathOrContent)) {
            $fileContent = file_get_contents(PathValidator::validate($pathOrContent));

            if ($fileContent === false) {
                throw new \InvalidArgumentException("Could not read file [{$pathOrContent}].");
            }

            $content = $fileContent;
        } else {
            $content = $pathOrContent;
        }

        return $this->importer->import(
            $this->parser->parse($content),
            $options ?? new ImportOptions,
        );
    }

    /**
     * Export the current authorization configuration as a serialized string.
     */
    public function export(?string $scope = null): string
    {
        return $this->parser->serialize(
            $this->exporter->export($scope),
        );
    }

    public function exportToFile(string $path, ?string $scope = null): void
    {
        PathValidator::validate($path);

        file_put_contents($path, $this->export($scope));
    }
}
