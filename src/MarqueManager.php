<?php

declare(strict_types=1);

namespace DynamikDev\Marque;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\DocumentExporter;
use DynamikDev\Marque\Contracts\DocumentImporter;
use DynamikDev\Marque\Contracts\DocumentParser;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Contracts\ScopeResolver;
use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\ImportResult;
use DynamikDev\Marque\DTOs\PolicyDocument;
use DynamikDev\Marque\Exceptions\RoleNotFoundException;
use DynamikDev\Marque\Exceptions\ScopeUnresolvableException;
use DynamikDev\Marque\Models\Boundary;
use DynamikDev\Marque\Models\Permission;
use DynamikDev\Marque\Models\Role;
use DynamikDev\Marque\Support\BoundaryBuilder;
use DynamikDev\Marque\Support\PathValidator;
use DynamikDev\Marque\Support\ResourcePolicyBuilder;
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
        private readonly AssignmentStore $assignments,
        private readonly ScopeResolver $scopeResolver,
        private readonly ResourcePolicyStore $resourcePolicies,
    ) {}

    /**
     * Start a fluent builder for attaching type-level resource policies
     * to the given resource class or type string.
     */
    public function resource(string $resourceType): ResourcePolicyBuilder
    {
        return new ResourcePolicyBuilder($this->resourcePolicies, $resourceType);
    }

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
     * Retrieve a single permission by its identifier.
     */
    public function getPermission(string $id): ?Permission
    {
        return $this->permissions->find($id);
    }

    /**
     * Create or update a role and return a fluent builder for granting permissions.
     *
     * If the role already exists, its current permissions are preserved so that
     * calling createRole() a second time does not wipe previously granted perms.
     */
    public function createRole(string $id, string $name, bool $system = false): RoleBuilder
    {
        $existing = $this->roles->find($id) !== null
            ? $this->roles->permissionsFor($id)
            : [];

        $this->roles->save($id, $name, $existing, $system);

        return new RoleBuilder($this->roles, $id, $this->permissions, $this->assignments, $this->scopeResolver);
    }

    /**
     * Look up a role by its identifier.
     */
    public function getRole(string $id): ?Role
    {
        return $this->roles->find($id);
    }

    /**
     * Return a builder handle for an existing role.
     *
     * @throws RoleNotFoundException If the role does not exist.
     */
    public function role(string $id): RoleBuilder
    {
        if ($this->roles->find($id) === null) {
            throw RoleNotFoundException::forId($id);
        }

        return new RoleBuilder($this->roles, $id, $this->permissions, $this->assignments, $this->scopeResolver);
    }

    /**
     * Return a BoundaryBuilder handle for modifying a boundary.
     *
     * @param  array<int, string>|null  $maxPermissions
     */
    public function boundary(mixed $scope, ?array $maxPermissions = null): BoundaryBuilder
    {
        $builder = new BoundaryBuilder($this->boundaries, $this->resolveScope($scope));

        if ($maxPermissions !== null) {
            $builder->permits($maxPermissions);
        }

        return $builder;
    }

    /**
     * Create a new boundary and return a fluent builder for setting permissions.
     */
    public function createBoundary(mixed $scope): BoundaryBuilder
    {
        return new BoundaryBuilder($this->boundaries, $this->resolveScope($scope));
    }

    /**
     * Look up a boundary by scope, returning the Boundary model or null.
     */
    public function getBoundary(mixed $scope): ?Boundary
    {
        return $this->boundaries->find($this->resolveScope($scope));
    }

    /**
     * Resolve a Scopeable model or raw string into a canonical scope string.
     *
     * @throws ScopeUnresolvableException If the scope resolves to null.
     */
    private function resolveScope(mixed $scope): string
    {
        $resolved = $this->scopeResolver->resolve($scope);

        if ($resolved === null) {
            throw ScopeUnresolvableException::forNullScope();
        }

        return $resolved;
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
