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

class PolicyEngineManager
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
        if ($this->looksLikeFilePath($pathOrContent)) {
            $validatedPath = PathValidator::validate($pathOrContent);

            if (file_exists($validatedPath)) {
                $fileContent = file_get_contents($validatedPath);

                if ($fileContent === false) {
                    throw new \InvalidArgumentException("Could not read file [{$pathOrContent}].");
                }

                return $this->importer->import(
                    $this->parser->parse($fileContent),
                    $options ?? new ImportOptions,
                );
            }
        }

        return $this->importer->import(
            $this->parser->parse($pathOrContent),
            $options ?? new ImportOptions,
        );
    }

    /**
     * Determine whether the input looks like a file path rather than raw content.
     */
    private function looksLikeFilePath(string $input): bool
    {
        $trimmed = ltrim($input);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return false;
        }

        return str_contains($input, DIRECTORY_SEPARATOR) || str_ends_with($input, '.json');
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
        $safePath = PathValidator::validate($path);

        file_put_contents($safePath, $this->export($scope));
    }
}
