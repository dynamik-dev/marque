<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Documents;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Boundary;
use DynamikDev\PolicyEngine\Models\Role;
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
            version: '1.0',
            permissions: $permissions,
            roles: $this->exportRoles($scope, $assignments),
            assignments: $this->serializeAssignments($assignments),
            boundaries: $this->exportBoundaries($scope),
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
     * Export roles as document arrays.
     *
     * When a scope is provided, only roles that have assignments in that scope are included.
     *
     * @param  Collection<int, Assignment>  $assignments
     * @return array<int, array{id: string, name: string, permissions: array<int, string>, system?: bool}>
     */
    private function exportRoles(?string $scope, Collection $assignments): array
    {
        $roles = $scope === null
            ? $this->roleStore->all()
            : $this->roleStore->all()->whereIn('id', $assignments->pluck('role_id')->unique());

        return $roles->map(fn (Role $role): array => $this->serializeRole($role))->values()->all();
    }

    /**
     * Serialize a single role model into a document array.
     *
     * @return array{id: string, name: string, permissions: array<int, string>, system?: bool}
     */
    private function serializeRole(Role $role): array
    {
        $data = [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $this->roleStore->permissionsFor($role->id),
        ];

        if ($role->is_system) {
            $data['system'] = true;
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
     * Export boundaries as document arrays.
     *
     * When a scope is provided, only the boundary for that scope (if it exists) is included.
     *
     * @return array<int, array{scope: string, max_permissions: array<int, string>}>
     */
    private function exportBoundaries(?string $scope): array
    {
        if ($scope === null) {
            return $this->boundaryStore->all()->map(static fn (Boundary $boundary): array => [
                'scope' => $boundary->scope,
                'max_permissions' => $boundary->max_permissions,
            ])->values()->all();
        }

        $boundary = $this->boundaryStore->find($scope);

        if ($boundary === null) {
            return [];
        }

        return [
            [
                'scope' => $boundary->scope,
                'max_permissions' => $boundary->max_permissions,
            ],
        ];
    }
}
