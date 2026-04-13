<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Documents;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\DocumentExporter;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\PolicyDocument;
use DynamikDev\Marque\Models\Assignment;
use DynamikDev\Marque\Models\ResourcePolicy;
use DynamikDev\Marque\Models\Role;
use DynamikDev\Marque\Models\RolePermission;
use Illuminate\Support\Collection;

class DefaultDocumentExporter implements DocumentExporter
{
    public function __construct(
        private readonly PermissionStore $permissionStore,
        private readonly RoleStore $roleStore,
        private readonly AssignmentStore $assignmentStore,
        private readonly BoundaryStore $boundaryStore,
    ) {}

    public function export(?string $scope = null): PolicyDocument
    {
        $permissions = $this->exportPermissions();
        $assignments = $this->exportAssignments($scope);

        return new PolicyDocument(
            version: '2.0',
            permissions: $permissions,
            roles: $this->exportRoles($scope, $assignments),
            assignments: $this->serializeAssignments($assignments),
            boundaries: $this->exportBoundaries($scope),
            resourcePolicies: $scope === null ? $this->exportResourcePolicies() : [],
        );
    }

    /**
     * Export all registered permission identifiers.
     *
     * @return array<int, string>
     */
    private function exportPermissions(): array
    {
        /** @var array<int, string> */
        return $this->permissionStore->all()->pluck('id')->all();
    }

    /**
     * Retrieve assignments, filtered by scope when provided.
     *
     * @return Collection<int, Assignment>
     */
    private function exportAssignments(?string $scope): Collection
    {
        if ($scope === null) {
            return $this->assignmentStore->all();
        }

        return $this->assignmentStore->subjectsInScope($scope);
    }

    /**
     * Export roles in keyed format.
     *
     * When a scope is provided, only roles that have assignments in that scope are included.
     *
     * @param  Collection<int, Assignment>  $assignments
     * @return array<string, array{permissions: array<int, string>, system?: bool}>
     */
    private function exportRoles(?string $scope, Collection $assignments): array
    {
        $roles = $scope === null
            ? $this->roleStore->all()
            : $this->roleStore->all()->whereIn('id', $assignments->pluck('role_id')->unique());

        $result = [];

        foreach ($roles as $role) {
            $result[$role->id] = $this->serializeKeyedRole($role);
        }

        return $result;
    }

    /**
     * Serialize a single role model into keyed format.
     *
     * @return array{permissions: array<int, string>, system?: bool, conditions?: array<string, array<int, array{type: string, parameters: array<string, mixed>}>>}
     */
    private function serializeKeyedRole(Role $role): array
    {
        $rows = RolePermission::query()
            ->where('role_id', $role->id)
            ->get(['permission_id', 'conditions']);

        /** @var array<int, string> $permissions */
        $permissions = $rows->pluck('permission_id')->all();
        /** @var array<string, array<int, array{type: string, parameters: array<string, mixed>}>> $conditions */
        $conditions = [];

        foreach ($rows as $row) {
            if (is_array($row->conditions) && $row->conditions !== []) {
                $conditions[$row->permission_id] = $row->conditions;
            }
        }

        $data = ['permissions' => $permissions];

        if ($role->is_system) {
            $data['system'] = true;
        }

        if ($conditions !== []) {
            $data['conditions'] = $conditions;
        }

        return $data;
    }

    /**
     * Serialize assignments into document arrays.
     *
     * @param  Collection<int, Assignment>  $assignments
     * @return array<int, array{subject: string, role: string, scope?: string}>
     */
    private function serializeAssignments(Collection $assignments): array
    {
        return $assignments->map(static function (Assignment $assignment): array {
            $data = [
                'subject' => $assignment->subject_type.'::'.$assignment->subject_id,
                'role' => $assignment->role_id,
            ];

            if ($assignment->scope !== null) {
                $data['scope'] = $assignment->scope;
            }

            return $data;
        })->values()->all();
    }

    /**
     * Export boundaries in keyed format.
     *
     * When a scope is provided, only the boundary for that scope (if it exists) is included.
     *
     * @return array<string, array{max_permissions: array<int, string>}>
     */
    private function exportBoundaries(?string $scope): array
    {
        if ($scope === null) {
            $result = [];

            foreach ($this->boundaryStore->all() as $boundary) {
                $result[$boundary->scope] = [
                    'max_permissions' => $boundary->max_permissions,
                ];
            }

            return $result;
        }

        $boundary = $this->boundaryStore->find($scope);

        if ($boundary === null) {
            return [];
        }

        return [
            $boundary->scope => [
                'max_permissions' => $boundary->max_permissions,
            ],
        ];
    }

    /**
     * Export all resource policies as document arrays.
     *
     * @return array<int, array{resource_type: string, resource_id: string|null, effect: string, action: string, principal_pattern: string|null, conditions: array<int, mixed>}>
     */
    private function exportResourcePolicies(): array
    {
        return ResourcePolicy::query()
            ->get()
            ->map(static fn (ResourcePolicy $rp): array => [
                'resource_type' => $rp->resource_type,
                'resource_id' => $rp->resource_id,
                'effect' => $rp->effect,
                'action' => $rp->action,
                'principal_pattern' => $rp->principal_pattern,
                'conditions' => $rp->conditions ?? [],
            ])
            ->values()
            ->all();
    }
}
