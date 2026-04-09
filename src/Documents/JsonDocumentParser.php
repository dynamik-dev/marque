<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Documents;

use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\DTOs\ValidationResult;

class JsonDocumentParser implements DocumentParser
{
    public function parse(string $content): PolicyDocument
    {
        $data = json_decode($content, associative: true);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON: '.json_last_error_msg());
        }

        /** @var string $version */
        $version = $data['version'] ?? '1.0';

        $rawRoles = is_array($data['roles'] ?? null) ? $data['roles'] : [];
        $rawBoundaries = is_array($data['boundaries'] ?? null) ? $data['boundaries'] : [];

        $isKeyed = $this->isAssociativeArray($rawRoles) && $rawRoles !== [];

        /** @var array<int|string, array{id: string, name: string, permissions: array<int, string>, system?: bool}|array{permissions: array<int, string>}> $roles */
        $roles = $isKeyed
            ? $this->parseKeyedRoles($rawRoles)
            : $rawRoles;

        /** @var array<int|string, array{scope?: string, max_permissions: array<int, string>}> $boundaries */
        $boundaries = ($this->isAssociativeArray($rawBoundaries) && $rawBoundaries !== [])
            ? $this->parseKeyedBoundaries($rawBoundaries)
            : $rawBoundaries;

        $resourcePolicies = $this->parseResourcePolicies($data['resource_policies'] ?? []);

        /** @var array<int, string> $permissions */
        $permissions = $data['permissions'] ?? [];
        /** @var array<int, array{subject: string, role: string, scope?: string}> $assignments */
        $assignments = $data['assignments'] ?? [];

        return new PolicyDocument(
            version: $version,
            permissions: $permissions,
            roles: $roles,
            assignments: $assignments,
            boundaries: $boundaries,
            resourcePolicies: $resourcePolicies,
        );
    }

    public function serialize(PolicyDocument $document): string
    {
        $roles = $this->serializeRolesToKeyed($document->roles);
        $boundaries = $this->serializeBoundariesToKeyed($document->boundaries);

        return json_encode([
            'version' => '2.0',
            'permissions' => $document->permissions,
            'roles' => $roles,
            'assignments' => $document->assignments,
            'boundaries' => $boundaries,
            'resource_policies' => $document->resourcePolicies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function validate(string $content): ValidationResult
    {
        $data = json_decode($content, associative: true);

        if (! is_array($data)) {
            return new ValidationResult(valid: false, errors: ['Invalid JSON: '.json_last_error_msg()]);
        }

        $errors = [];

        if (! array_key_exists('version', $data)) {
            $errors[] = 'Missing required field: version';
        }

        if (array_key_exists('permissions', $data)) {
            $this->validatePermissions($data['permissions'], $errors);
        }

        if (array_key_exists('roles', $data)) {
            $rawRoles = $data['roles'];
            if (is_array($rawRoles) && $this->isAssociativeArray($rawRoles) && $rawRoles !== []) {
                /** @var array<string, mixed> $rawRoles */
                $this->validateKeyedRoles($rawRoles, $errors);
            } else {
                $this->validateRoles($rawRoles, $errors);
            }
        }

        if (array_key_exists('assignments', $data)) {
            $this->validateAssignments($data['assignments'], $errors);
        }

        if (array_key_exists('boundaries', $data)) {
            $rawBoundaries = $data['boundaries'];
            if (is_array($rawBoundaries) && $this->isAssociativeArray($rawBoundaries) && $rawBoundaries !== []) {
                /** @var array<string, mixed> $rawBoundaries */
                $this->validateKeyedBoundaries($rawBoundaries, $errors);
            } else {
                $this->validateBoundaries($rawBoundaries, $errors);
            }
        }

        if (array_key_exists('resource_policies', $data)) {
            $this->validateResourcePolicies($data['resource_policies'], $errors);
        }

        return new ValidationResult(
            valid: $errors === [],
            errors: $errors,
        );
    }

    /**
     * Determine whether an array is associative (string keys) vs indexed.
     *
     * @param  array<mixed>  $array
     */
    private function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Parse keyed roles (associative: id => data) into indexed array-of-objects format.
     *
     * @param  array<mixed>  $rawRoles
     * @return array<int, array{id: string, name: string, permissions: array<int, string>, system?: bool}>
     */
    private function parseKeyedRoles(array $rawRoles): array
    {
        $result = [];

        foreach ($rawRoles as $roleId => $roleData) {
            if (! is_array($roleData)) {
                continue;
            }

            /** @var array<int, string> $permissions */
            $permissions = $roleData['permissions'] ?? [];
            $id = (string) $roleId;
            $entry = [
                'id' => $id,
                'name' => is_string($roleData['name'] ?? null) ? $roleData['name'] : $id,
                'permissions' => $permissions,
            ];

            if (! empty($roleData['system'])) {
                $entry['system'] = true;
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Parse keyed boundaries (associative: scope => data) into indexed array-of-objects format.
     *
     * @param  array<mixed>  $rawBoundaries
     * @return array<int, array{scope: string, max_permissions: array<int, string>}>
     */
    private function parseKeyedBoundaries(array $rawBoundaries): array
    {
        $result = [];

        foreach ($rawBoundaries as $scope => $boundaryData) {
            if (! is_array($boundaryData)) {
                continue;
            }

            /** @var array<int, string> $maxPermissions */
            $maxPermissions = $boundaryData['max_permissions'] ?? [];
            $result[] = [
                'scope' => (string) $scope,
                'max_permissions' => $maxPermissions,
            ];
        }

        return $result;
    }

    /**
     * Parse resource_policies array from raw document data.
     *
     * @return array<int, array{resource_type: string, resource_id: string|null, effect: string, action: string, principal_pattern: string|null, conditions: array<int, mixed>}>
     */
    private function parseResourcePolicies(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $result[] = [
                'resource_type' => is_string($entry['resource_type'] ?? null) ? $entry['resource_type'] : '',
                'resource_id' => isset($entry['resource_id']) && is_scalar($entry['resource_id']) ? (string) $entry['resource_id'] : null,
                'effect' => is_string($entry['effect'] ?? null) ? $entry['effect'] : 'Allow',
                'action' => is_string($entry['action'] ?? null) ? $entry['action'] : '',
                'principal_pattern' => isset($entry['principal_pattern']) && is_string($entry['principal_pattern']) ? $entry['principal_pattern'] : null,
                'conditions' => is_array($entry['conditions'] ?? null) ? $entry['conditions'] : [],
            ];
        }

        return $result;
    }

    /**
     * Convert indexed roles to keyed format for serialization.
     *
     * @param  array<mixed>  $roles
     * @return array<string, array{permissions: array<int, string>, system?: bool}>
     */
    private function serializeRolesToKeyed(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        // Already in keyed format (associative with string keys pointing to arrays without 'id')
        if ($this->isAssociativeArray($roles)) {
            /** @var array<string, array{permissions: array<int, string>, system?: bool}> */
            return $roles;
        }

        // Indexed format — convert to keyed
        $result = [];

        foreach ($roles as $role) {
            if (! is_array($role) || ! isset($role['id'])) {
                continue;
            }

            /** @var array<int, string> $permissions */
            $permissions = $role['permissions'] ?? [];
            $entry = [
                'permissions' => $permissions,
            ];

            if (! empty($role['system'])) {
                $entry['system'] = true;
            }

            /** @var string $roleId */
            $roleId = $role['id'];
            $result[$roleId] = $entry;
        }

        return $result;
    }

    /**
     * Convert indexed boundaries to keyed format for serialization.
     *
     * @param  array<mixed>  $boundaries
     * @return array<string, array{max_permissions: array<int, string>}>
     */
    private function serializeBoundariesToKeyed(array $boundaries): array
    {
        if ($boundaries === []) {
            return [];
        }

        // Already in keyed format
        if ($this->isAssociativeArray($boundaries)) {
            /** @var array<string, array{max_permissions: array<int, string>}> */
            return $boundaries;
        }

        // Indexed format — convert to keyed
        $result = [];

        foreach ($boundaries as $boundary) {
            if (! is_array($boundary) || ! isset($boundary['scope'])) {
                continue;
            }

            /** @var string $scopeKey */
            $scopeKey = $boundary['scope'];
            /** @var array<int, string> $maxPerms */
            $maxPerms = $boundary['max_permissions'] ?? [];
            $result[$scopeKey] = [
                'max_permissions' => $maxPerms,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validatePermissions(mixed $permissions, array &$errors): void
    {
        if (! is_array($permissions)) {
            $errors[] = 'permissions must be an array';

            return;
        }

        foreach ($permissions as $index => $permission) {
            if (! is_string($permission)) {
                $errors[] = "permissions[{$index}] must be a string";
            }
        }
    }

    /**
     * Validate indexed roles (array of objects with id, name, permissions).
     *
     * @param  array<int, string>  $errors
     */
    private function validateRoles(mixed $roles, array &$errors): void
    {
        if (! is_array($roles)) {
            $errors[] = 'roles must be an array';

            return;
        }

        foreach ($roles as $index => $role) {
            if (! is_array($role)) {
                $errors[] = "roles[{$index}] must be an object";

                continue;
            }

            foreach (['id', 'name', 'permissions'] as $key) {
                if (! array_key_exists($key, $role)) {
                    $errors[] = "roles[{$index}] is missing required key: {$key}";
                }
            }

            if (array_key_exists('id', $role) && ! is_string($role['id'])) {
                $errors[] = "roles[{$index}].id must be a string";
            }

            if (array_key_exists('name', $role) && ! is_string($role['name'])) {
                $errors[] = "roles[{$index}].name must be a string";
            }

            if (array_key_exists('permissions', $role)) {
                if (! is_array($role['permissions'])) {
                    $errors[] = "roles[{$index}].permissions must be an array";
                } else {
                    foreach ($role['permissions'] as $pIndex => $permission) {
                        if (! is_string($permission)) {
                            $errors[] = "roles[{$index}].permissions[{$pIndex}] must be a string";
                        }
                    }
                }
            }
        }
    }

    /**
     * Validate keyed roles (associative: id => {permissions, conditions?}).
     *
     * @param  array<string, mixed>  $roles
     * @param  array<int, string>  $errors
     */
    private function validateKeyedRoles(array $roles, array &$errors): void
    {
        foreach ($roles as $roleId => $roleData) {
            if (! is_array($roleData)) {
                $errors[] = "roles[{$roleId}] must be an object";

                continue;
            }

            if (! array_key_exists('permissions', $roleData)) {
                $errors[] = "roles[{$roleId}] is missing required key: permissions";

                continue;
            }

            if (! is_array($roleData['permissions'])) {
                $errors[] = "roles[{$roleId}].permissions must be an array";
            } else {
                foreach ($roleData['permissions'] as $pIndex => $permission) {
                    if (! is_string($permission)) {
                        $errors[] = "roles[{$roleId}].permissions[{$pIndex}] must be a string";
                    }
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateAssignments(mixed $assignments, array &$errors): void
    {
        if (! is_array($assignments)) {
            $errors[] = 'assignments must be an array';

            return;
        }

        foreach ($assignments as $index => $assignment) {
            if (! is_array($assignment)) {
                $errors[] = "assignments[{$index}] must be an object";

                continue;
            }

            foreach (['subject', 'role'] as $key) {
                if (! array_key_exists($key, $assignment)) {
                    $errors[] = "assignments[{$index}] is missing required key: {$key}";
                }
            }

            if (array_key_exists('subject', $assignment) && ! is_string($assignment['subject'])) {
                $errors[] = "assignments[{$index}].subject must be a string";
            }

            if (array_key_exists('role', $assignment) && ! is_string($assignment['role'])) {
                $errors[] = "assignments[{$index}].role must be a string";
            }
        }
    }

    /**
     * Validate indexed boundaries (array of objects with scope, max_permissions).
     *
     * @param  array<int, string>  $errors
     */
    private function validateBoundaries(mixed $boundaries, array &$errors): void
    {
        if (! is_array($boundaries)) {
            $errors[] = 'boundaries must be an array';

            return;
        }

        foreach ($boundaries as $index => $boundary) {
            if (! is_array($boundary)) {
                $errors[] = "boundaries[{$index}] must be an object";

                continue;
            }

            foreach (['scope', 'max_permissions'] as $key) {
                if (! array_key_exists($key, $boundary)) {
                    $errors[] = "boundaries[{$index}] is missing required key: {$key}";
                }
            }

            if (array_key_exists('scope', $boundary) && ! is_string($boundary['scope'])) {
                $errors[] = "boundaries[{$index}].scope must be a string";
            }

            if (array_key_exists('max_permissions', $boundary)) {
                if (! is_array($boundary['max_permissions'])) {
                    $errors[] = "boundaries[{$index}].max_permissions must be an array";
                } else {
                    foreach ($boundary['max_permissions'] as $pIndex => $permission) {
                        if (! is_string($permission)) {
                            $errors[] = "boundaries[{$index}].max_permissions[{$pIndex}] must be a string";
                        }
                    }
                }
            }
        }
    }

    /**
     * Validate keyed boundaries (associative: scope => {max_permissions}).
     *
     * @param  array<string, mixed>  $boundaries
     * @param  array<int, string>  $errors
     */
    private function validateKeyedBoundaries(array $boundaries, array &$errors): void
    {
        foreach ($boundaries as $scope => $boundaryData) {
            if (! is_array($boundaryData)) {
                $errors[] = "boundaries[{$scope}] must be an object";

                continue;
            }

            if (! array_key_exists('max_permissions', $boundaryData)) {
                $errors[] = "boundaries[{$scope}] is missing required key: max_permissions";

                continue;
            }

            if (! is_array($boundaryData['max_permissions'])) {
                $errors[] = "boundaries[{$scope}].max_permissions must be an array";
            } else {
                foreach ($boundaryData['max_permissions'] as $pIndex => $permission) {
                    if (! is_string($permission)) {
                        $errors[] = "boundaries[{$scope}].max_permissions[{$pIndex}] must be a string";
                    }
                }
            }
        }
    }

    /**
     * Validate resource_policies array.
     *
     * @param  array<int, string>  $errors
     */
    private function validateResourcePolicies(mixed $resourcePolicies, array &$errors): void
    {
        if (! is_array($resourcePolicies)) {
            $errors[] = 'resource_policies must be an array';

            return;
        }

        foreach ($resourcePolicies as $index => $entry) {
            if (! is_array($entry)) {
                $errors[] = "resource_policies[{$index}] must be an object";

                continue;
            }

            foreach (['resource_type', 'effect', 'action'] as $key) {
                if (! array_key_exists($key, $entry)) {
                    $errors[] = "resource_policies[{$index}] is missing required key: {$key}";
                }
            }
        }
    }
}
