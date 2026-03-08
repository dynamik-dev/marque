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
        $content = file_exists($pathOrContent)
            ? file_get_contents($this->validatePath($pathOrContent))
            : $pathOrContent;

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
        $this->validatePath($path);

        file_put_contents($path, $this->export($scope));
    }

    /**
     * Validate that a file path is within the configured allowed directory.
     *
     * When `policy-engine.document_path` is set, paths are restricted to that directory.
     * When null (default), no restriction is applied.
     *
     * @throws \InvalidArgumentException If the path is outside the allowed directory.
     */
    private function validatePath(string $path): string
    {
        $allowedBase = config('policy-engine.document_path');

        if ($allowedBase === null) {
            return $path;
        }

        $allowedBaseReal = realpath($allowedBase);
        $targetDirectoryReal = realpath(dirname($path));

        if ($allowedBaseReal === false || $targetDirectoryReal === false) {
            throw new \InvalidArgumentException("Path must be within the allowed directory [{$allowedBase}].");
        }

        $allowedPrefix = rtrim($allowedBaseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $targetPrefix = rtrim($targetDirectoryReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($targetPrefix, $allowedPrefix)) {
            throw new \InvalidArgumentException("Path must be within the allowed directory [{$allowedBase}].");
        }

        return $targetDirectoryReal.DIRECTORY_SEPARATOR.basename($path);
    }
}
