<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Documents;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\DocumentImporter;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\ImportResult;
use DynamikDev\Marque\DTOs\PolicyDocument;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Events\DocumentImported;
use DynamikDev\Marque\Models\Permission;
use DynamikDev\Marque\Models\ResourcePolicy;
use DynamikDev\Marque\Support\SubjectParser;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

class DefaultDocumentImporter implements DocumentImporter
{
    public function __construct(
        private readonly PermissionStore $permissionStore,
        private readonly RoleStore $roleStore,
        private readonly AssignmentStore $assignmentStore,
        private readonly BoundaryStore $boundaryStore,
        private readonly ResourcePolicyStore $resourcePolicyStore,
    ) {}

    public function import(PolicyDocument $document, ImportOptions $options): ImportResult
    {
        $warnings = [];

        if ($options->validate) {
            $warnings = $this->collectValidationWarnings($document);
        }

        $isReplace = ! $options->merge;

        $connection = Permission::query()->getConnection();
        assert($connection instanceof Connection);

        /** @var ImportResult */
        return $connection->transaction(function () use ($connection, $document, $options, $warnings, $isReplace): ImportResult {
            if ($isReplace && ! $options->dryRun) {
                $this->clearAllData();
            }

            $permissionsCreated = $this->importPermissions($document, $options, $isReplace);
            $rolesResult = $this->importRoles($document, $options, $isReplace);
            $this->importBoundaries($document, $options);
            $assignmentsCreated = $this->importAssignments($document, $options);
            $this->importResourcePolicies($document, $options);

            $warnings = [...$warnings, ...$rolesResult['warnings']];

            $result = new ImportResult(
                permissionsCreated: $permissionsCreated,
                rolesCreated: $rolesResult['created'],
                rolesUpdated: $rolesResult['updated'],
                assignmentsCreated: $assignmentsCreated,
                warnings: $warnings,
            );

            if (! $options->dryRun) {
                $connection->afterCommit(static function () use ($result): void {
                    Event::dispatch(new DocumentImported($result));
                });
            }

            return $result;
        });
    }

    /**
     * Validate that permission strings referenced in roles exist in the document or the store.
     *
     * @return array<int, string>
     */
    private function collectValidationWarnings(PolicyDocument $document): array
    {
        $warnings = [];
        $documentPermissions = array_flip($document->permissions);

        $normalizedRoles = $this->normalizeRoles($document->roles);

        foreach ($normalizedRoles as $role) {
            foreach ($role['permissions'] as $permission) {
                if (! isset($documentPermissions[$permission]) && ! $this->permissionStore->exists($permission)) {
                    $warnings[] = "Permission '{$permission}' referenced in role '{$role['id']}' is not registered";
                }
            }
        }

        return $warnings;
    }

    /**
     * Remove all existing permissions, roles, assignments, boundaries, and resource policies via store contracts.
     *
     * Dispatches removal events for each entity so cache invalidation listeners fire.
     */
    private function clearAllData(): void
    {
        ResourcePolicy::query()->delete();
        $this->assignmentStore->removeAll();
        $this->boundaryStore->removeAll();
        $this->roleStore->removeAll();
        $this->permissionStore->removeAll();
    }

    /**
     * Import permissions from the document.
     *
     * @return array<int, string>
     */
    private function importPermissions(PolicyDocument $document, ImportOptions $options, bool $isReplace): array
    {
        $created = [];

        foreach ($document->permissions as $permission) {
            $isNew = $isReplace || ! $this->permissionStore->exists($permission);

            if ($isNew) {
                $created[] = $permission;
            }

            if (! $options->dryRun) {
                $this->permissionStore->register($permission);
            }
        }

        return $created;
    }

    /**
     * Import roles from the document. Supports both indexed (array of {id, name, permissions}) and
     * keyed (by ID) formats via normalization.
     *
     * @return array{created: array<int, string>, updated: array<int, string>, warnings: array<int, string>}
     */
    private function importRoles(PolicyDocument $document, ImportOptions $options, bool $isReplace): array
    {
        $created = [];
        $updated = [];
        $warnings = [];

        $normalizedRoles = $this->normalizeRoles($document->roles);

        foreach ($normalizedRoles as $role) {
            $existing = $this->roleStore->find($role['id']);
            $isNew = $isReplace || $existing === null;

            if ($existing !== null && $existing->is_system && config('marque.protect_system_roles')) {
                $warnings[] = "Skipped protected system role '{$role['id']}' during import";

                continue;
            }

            if ($isNew) {
                $created[] = $role['id'];
            } else {
                $updated[] = $role['id'];
            }

            if (! $options->dryRun) {
                $this->roleStore->save(
                    id: $role['id'],
                    name: $role['name'],
                    permissions: $role['permissions'],
                    system: $role['system'] ?? false,
                    conditions: $role['conditions'] ?? [],
                );
            }
        }

        return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings];
    }

    /**
     * Import boundaries from the document. Supports both indexed (array of {scope, max_permissions}) and
     * keyed (by scope string) formats via normalization.
     */
    private function importBoundaries(PolicyDocument $document, ImportOptions $options): void
    {
        if ($options->dryRun) {
            return;
        }

        foreach ($this->normalizeBoundaries($document->boundaries) as $boundary) {
            $this->boundaryStore->set($boundary['scope'], $boundary['max_permissions']);
        }
    }

    /**
     * Normalize boundaries from either indexed (array of objects) or keyed (by scope) format
     * into a canonical array of {scope, max_permissions}.
     *
     * @param  array<mixed>  $boundaries
     * @return array<int, array{scope: string, max_permissions: array<int, string>}>
     */
    private function normalizeBoundaries(array $boundaries): array
    {
        if ($boundaries === []) {
            return [];
        }

        // Keyed: associative array keyed by scope string
        if (array_keys($boundaries) !== range(0, count($boundaries) - 1)) {
            $result = [];

            foreach ($boundaries as $scope => $boundaryData) {
                $data = is_array($boundaryData) ? $boundaryData : [];
                /** @var array<int, string> $maxPermissions */
                $maxPermissions = $data['max_permissions'] ?? [];
                $result[] = [
                    'scope' => (string) $scope,
                    'max_permissions' => $maxPermissions,
                ];
            }

            return $result;
        }

        // Indexed array of boundary objects — return as-is
        /** @var array<int, array{scope: string, max_permissions: array<int, string>}> */
        return $boundaries;
    }

    /**
     * Import assignments from the document.
     */
    private function importAssignments(PolicyDocument $document, ImportOptions $options): int
    {
        if ($options->skipAssignments) {
            return 0;
        }

        if ($options->dryRun) {
            return count($document->assignments);
        }

        $allowedTypes = $this->buildAllowedSubjectTypes();

        foreach ($document->assignments as $assignment) {
            [$subjectType, $subjectId] = SubjectParser::parse($assignment['subject']);

            $this->validateSubjectType($subjectType, $allowedTypes);

            $this->assignmentStore->assign(
                subjectType: $subjectType,
                subjectId: $subjectId,
                roleId: $assignment['role'],
                scope: $assignment['scope'] ?? null,
            );
        }

        return count($document->assignments);
    }

    /**
     * Import resource policies from the document.
     */
    private function importResourcePolicies(PolicyDocument $document, ImportOptions $options): void
    {
        if ($options->dryRun || $document->resourcePolicies === []) {
            return;
        }

        foreach ($document->resourcePolicies as $entry) {
            $conditions = Condition::hydrateMany($entry['conditions']);

            $statement = new PolicyStatement(
                effect: Effect::{$entry['effect']},
                action: $entry['action'],
                principalPattern: $entry['principal_pattern'] ?? null,
                resourcePattern: null,
                conditions: $conditions,
                source: 'document',
            );

            $this->resourcePolicyStore->attach(
                resourceType: $entry['resource_type'],
                resourceId: $entry['resource_id'] ?? null,
                statement: $statement,
            );
        }
    }

    /**
     * Normalize roles from either indexed (array of objects) or keyed (by ID) format
     * into a canonical array of {id, name, permissions, system?, conditions?}.
     *
     * @param  array<mixed>  $roles
     * @return array<int, array{id: string, name: string, permissions: array<int, string>, system?: bool, conditions?: array<string, array<int, array{type: string, parameters: array<string, mixed>}>>}>
     */
    private function normalizeRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        // Keyed: associative array keyed by role ID
        if (array_keys($roles) !== range(0, count($roles) - 1)) {
            $result = [];

            foreach ($roles as $roleId => $roleData) {
                $data = is_array($roleData) ? $roleData : [];
                /** @var array<int, string> $permissions */
                $permissions = $data['permissions'] ?? [];
                $entry = [
                    'id' => (string) $roleId,
                    'name' => is_string($data['name'] ?? null) ? $data['name'] : (string) $roleId,
                    'permissions' => $permissions,
                ];

                if (! empty($data['system'])) {
                    $entry['system'] = true;
                }

                if (! empty($data['conditions']) && is_array($data['conditions'])) {
                    /** @var array<string, array<int, array{type: string, parameters: array<string, mixed>}>> $conditions */
                    $conditions = $data['conditions'];
                    $entry['conditions'] = $conditions;
                }

                $result[] = $entry;
            }

            return $result;
        }

        // Indexed array of role objects -- return as-is
        /** @var array<int, array{id: string, name: string, permissions: array<int, string>, system?: bool, conditions?: array<string, array<int, array{type: string, parameters: array<string, mixed>}>>}> */
        return $roles;
    }

    /**
     * Build the set of allowed subject types from the morph map and config whitelist.
     *
     * @return array<int, string>
     */
    private function buildAllowedSubjectTypes(): array
    {
        $morphMap = Relation::morphMap();
        /** @var array<int, string> $whitelist */
        $whitelist = config('marque.import_subject_types', []);

        return [
            ...array_keys($morphMap),
            ...array_values($morphMap),
            ...$whitelist,
        ];
    }

    /**
     * Validate that a subject type is in the allowed set.
     *
     * Skips validation when no morph map or whitelist is configured (backward compatibility).
     *
     * @param  array<int, string>  $allowedTypes
     */
    private function validateSubjectType(string $subjectType, array $allowedTypes): void
    {
        if ($allowedTypes === []) {
            return;
        }

        if (! in_array($subjectType, $allowedTypes, strict: true)) {
            throw new InvalidArgumentException("Subject type [{$subjectType}] is not in the morph map or import_subject_types whitelist.");
        }
    }
}
