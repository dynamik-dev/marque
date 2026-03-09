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

        return new PolicyDocument(
            version: $version,
            permissions: $data['permissions'] ?? [],
            roles: $data['roles'] ?? [],
            assignments: $data['assignments'] ?? [],
            boundaries: $data['boundaries'] ?? [],
        );
    }

    public function serialize(PolicyDocument $document): string
    {
        return json_encode([
            'version' => $document->version,
            'permissions' => $document->permissions,
            'roles' => $document->roles,
            'assignments' => $document->assignments,
            'boundaries' => $document->boundaries,
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
            $this->validateRoles($data['roles'], $errors);
        }

        if (array_key_exists('assignments', $data)) {
            $this->validateAssignments($data['assignments'], $errors);
        }

        if (array_key_exists('boundaries', $data)) {
            $this->validateBoundaries($data['boundaries'], $errors);
        }

        return new ValidationResult(
            valid: $errors === [],
            errors: $errors,
        );
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
        }
    }

    /**
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
        }
    }
}
