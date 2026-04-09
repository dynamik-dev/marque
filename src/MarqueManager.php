<?php

declare(strict_types=1);

namespace DynamikDev\Marque;

use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\DocumentExporter;
use DynamikDev\Marque\Contracts\DocumentImporter;
use DynamikDev\Marque\Contracts\DocumentParser;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\ImportResult;
use DynamikDev\Marque\DTOs\PolicyDocument;
use DynamikDev\Marque\Support\PathValidator;
use DynamikDev\Marque\Support\RoleBuilder;

class MarqueManager
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
        $resolvedOptions = $options ?? new ImportOptions;

        if ($this->looksLikeFilePath($pathOrContent)) {
            $validatedPath = PathValidator::validate($pathOrContent);

            if (file_exists($validatedPath)) {
                $fileContent = file_get_contents($validatedPath);

                if ($fileContent === false) {
                    throw new \InvalidArgumentException("Could not read file [{$pathOrContent}].");
                }

                return $this->importer->import(
                    $this->parseAndValidate($fileContent, $resolvedOptions),
                    $resolvedOptions,
                );
            }
        }

        return $this->importer->import(
            $this->parseAndValidate($pathOrContent, $resolvedOptions),
            $resolvedOptions,
        );
    }

    /**
     * Validate raw content structurally (when enabled) then parse into a PolicyDocument.
     */
    private function parseAndValidate(string $content, ImportOptions $options): PolicyDocument
    {
        if ($options->validate) {
            $result = $this->parser->validate($content);

            if (! $result->valid) {
                throw new \InvalidArgumentException(
                    'Document validation failed: '.implode('; ', $result->errors),
                );
            }
        }

        return $this->parser->parse($content);
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
