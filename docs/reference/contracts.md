# Contracts Reference

All contracts live in `DynamikDev\PolicyEngine\Contracts\`. Each has one default implementation that can be [swapped via the service container](../extending/swapping-implementations.md).

---

### `PermissionStore`

Manages the registry of permission identifiers.

| Method | Returns | Description |
| --- | --- | --- |
| `register(string\|array $permissions)` | `void` | Register one or more permissions. Idempotent. |
| `remove(string $id)` | `void` | Remove a permission. Cascades to role_permissions. |
| `all(?string $prefix = null)` | `Collection` | List all permissions, optionally filtered by prefix. |
| `exists(string $id)` | `bool` | Check if a permission is registered. |

**Default implementation:** `DynamikDev\PolicyEngine\Stores\EloquentPermissionStore`

---

### `RoleStore`

Creates, updates, and queries roles and their permissions.

| Method | Returns | Description |
| --- | --- | --- |
| `save(string $id, string $name, array $permissions, bool $system = false)` | `Role` | Create or update a role. |
| `remove(string $id)` | `void` | Delete a role. Cascades assignments. |
| `find(string $id)` | `?Role` | Get a role by ID. |
| `all()` | `Collection` | List all roles. |
| `permissionsFor(string $roleId)` | `array` | Get permission strings for a role. |
| `permissionsForRoles(array $roleIds)` | `array` | Get permissions for multiple roles in one call. Keyed by role ID. |

**Default implementation:** `DynamikDev\PolicyEngine\Stores\EloquentRoleStore`

---

### `AssignmentStore`

Links subjects to roles, optionally within a scope.

| Method | Returns | Description |
| --- | --- | --- |
| `assign(string $subjectType, string\|int $subjectId, string $roleId, ?string $scope = null)` | `void` | Assign a role to a subject. Idempotent. |
| `revoke(string $subjectType, string\|int $subjectId, string $roleId, ?string $scope = null)` | `void` | Revoke a role from a subject. No-op if not assigned. |
| `forSubject(string $subjectType, string\|int $subjectId)` | `Collection` | All assignments for a subject. |
| `forSubjectInScope(string $subjectType, string\|int $subjectId, string $scope)` | `Collection` | Assignments for a subject in a specific scope. |
| `forSubjectGlobal(string $subjectType, string\|int $subjectId)` | `Collection` | Global (unscoped) assignments for a subject. |
| `forSubjectGlobalAndScope(string $subjectType, string\|int $subjectId, string $scope)` | `Collection` | Assignments that are either global or in the given scope. |
| `subjectsInScope(string $scope, ?string $roleId = null)` | `Collection` | All subjects assigned in a scope, optionally filtered by role. |
| `all()` | `Collection` | Get all assignments. |

**Default implementation:** `DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore`

---

### `BoundaryStore`

Manages permission ceilings per scope.

| Method | Returns | Description |
| --- | --- | --- |
| `set(string $scope, array $maxPermissions)` | `void` | Set a boundary for a scope. Replaces existing. |
| `remove(string $scope)` | `void` | Remove a boundary. No-op if not set. |
| `find(string $scope)` | `?Boundary` | Get the boundary for a scope. |
| `all()` | `Collection` | Get all boundaries. |

**Default implementation:** `DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore`

---

### `Evaluator`

The core authorization engine.

| Method | Returns | Description |
| --- | --- | --- |
| `can(string $subjectType, string\|int $subjectId, string $permission)` | `bool` | Resolve assignments, roles, permissions, boundaries, deny/allow. |
| `explain(string $subjectType, string\|int $subjectId, string $permission)` | `EvaluationTrace` | Full decision trace for debugging. |
| `effectivePermissions(string $subjectType, string\|int $subjectId, ?string $scope = null)` | `array` | Net permissions after deny rules are applied. |

**Default implementation:** `DynamikDev\PolicyEngine\Evaluators\CachedEvaluator` (wraps `DefaultEvaluator`)

---

### `Matcher`

Determines whether a granted permission covers a required permission, including wildcard resolution.

| Method | Returns | Description |
| --- | --- | --- |
| `matches(string $granted, string $required)` | `bool` | Does the granted permission cover the required one? |

**Default implementation:** `DynamikDev\PolicyEngine\Matchers\WildcardMatcher`

**Matching rules:**

| Granted | Required | Result |
| --- | --- | --- |
| `posts.create` | `posts.create` | match |
| `posts.*` | `posts.create` | match |
| `*.create` | `posts.create` | match |
| `*.*` | `posts.create` | match |
| `posts.delete.*` | `posts.delete.own` | match |
| `posts.delete.*` | `posts.delete` | no match |
| `posts.create` | `posts.create:group::5` | match (unscoped covers scoped) |
| `posts.create:group::5` | `posts.create` | no match (scoped doesn't cover unscoped) |

---

### `ScopeResolver`

Converts scope arguments into `type::id` strings.

| Method | Returns | Description |
| --- | --- | --- |
| `resolve(mixed $scope)` | `?string` | Resolve a scope parameter. |

**Default implementation:** `DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver`

**Resolution rules:**

| Input | Output |
| --- | --- |
| `null` | `null` |
| `'group::5'` (string) | `'group::5'` (pass-through) |
| Scopeable model | `$model->toScope()` |
| Anything else | `InvalidArgumentException` |

---

### `DocumentParser`

Parses and serializes policy documents.

| Method | Returns | Description |
| --- | --- | --- |
| `parse(string $content)` | `PolicyDocument` | Parse a document string into a DTO. |
| `serialize(PolicyDocument $document)` | `string` | Serialize a DTO back to string format. |
| `validate(string $content)` | `ValidationResult` | Validate without importing. |

**Default implementation:** `DynamikDev\PolicyEngine\Documents\JsonDocumentParser`

---

### `DocumentImporter`

Applies a parsed policy document to the stores.

| Method | Returns | Description |
| --- | --- | --- |
| `import(PolicyDocument $document, ImportOptions $options)` | `ImportResult` | Import a document with the given options. |

**Default implementation:** `DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter`

---

### `DocumentExporter`

Reads current authorization state and builds a policy document.

| Method | Returns | Description |
| --- | --- | --- |
| `export(?string $scope = null)` | `PolicyDocument` | Export current state, optionally scoped. |

**Default implementation:** `DynamikDev\PolicyEngine\Documents\DefaultDocumentExporter`
