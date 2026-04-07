<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Documents;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\Events\DocumentImported;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Boundary;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Models\RolePermission;
use DynamikDev\PolicyEngine\Support\SubjectParser;
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
    ) {}

    public function import(PolicyDocument $document, ImportOptions $options): ImportResult
    {
        $warnings = [];

        if ($options->validate) {
            $warnings = $this->collectValidationWarnings($document);
        }

        $isReplace = ! $options->merge;

        if ($isReplace && ! $options->dryRun) {
            $this->clearAllData();
        }

        $permissionsCreated = $this->importPermissions($document, $options, $isReplace);
        $rolesResult = $this->importRoles($document, $options, $isReplace);
        $this->importBoundaries($document, $options);
        $assignmentsCreated = $this->importAssignments($document, $options);

        $warnings = [...$warnings, ...$rolesResult['warnings']];

        $result = new ImportResult(
            permissionsCreated: $permissionsCreated,
            rolesCreated: $rolesResult['created'],
            rolesUpdated: $rolesResult['updated'],
            assignmentsCreated: $assignmentsCreated,
            warnings: $warnings,
        );

        if (! $options->dryRun) {
            Event::dispatch(new DocumentImported($result));
        }

        return $result;
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

        foreach ($document->roles as $role) {
            foreach ($role['permissions'] as $permission) {
                if (! isset($documentPermissions[$permission]) && ! $this->permissionStore->exists($permission)) {
                    $warnings[] = "Permission '{$permission}' referenced in role '{$role['id']}' is not registered";
                }
            }
        }

        return $warnings;
    }

    /**
     * Remove all existing permissions, roles, assignments, and boundaries.
     */
    private function clearAllData(): void
    {
        Assignment::query()->delete();
        RolePermission::query()->delete();
        Boundary::query()->delete();
        Role::query()->delete();
        Permission::query()->delete();
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
     * Import roles from the document.
     *
     * @return array{created: array<int, string>, updated: array<int, string>, warnings: array<int, string>}
     */
    private function importRoles(PolicyDocument $document, ImportOptions $options, bool $isReplace): array
    {
        $created = [];
        $updated = [];
        $warnings = [];

        foreach ($document->roles as $role) {
            $existing = $this->roleStore->find($role['id']);
            $isNew = $isReplace || $existing === null;

            if ($existing !== null && $existing->is_system && config('policy-engine.protect_system_roles')) {
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
                );
            }
        }

        return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings];
    }

    /**
     * Import boundaries from the document.
     */
    private function importBoundaries(PolicyDocument $document, ImportOptions $options): void
    {
        if ($options->dryRun) {
            return;
        }

        foreach ($document->boundaries as $boundary) {
            $this->boundaryStore->set($boundary['scope'], $boundary['max_permissions']);
        }
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
     * Build the set of allowed subject types from the morph map and config whitelist.
     *
     * @return array<int, string>
     */
    private function buildAllowedSubjectTypes(): array
    {
        $morphMap = Relation::morphMap();
        $whitelist = config('policy-engine.import_subject_types', []);

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
