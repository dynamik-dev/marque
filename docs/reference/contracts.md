# Contracts Reference

All contracts live in `DynamikDev\Marque\Contracts\`. Each has one default implementation that can be [swapped via the service container](../extending/swapping-implementations.md).

---

### `PermissionStore`

Manages the registry of permission identifiers.

| Method | Returns | Description |
| --- | --- | --- |
| `register(string\|array $permissions)` | `void` | Register one or more permissions. Idempotent. |
| `remove(string $id)` | `void` | Remove a permission. Cascades to role_permissions. |
| `all(?string $prefix = null)` | `Collection` | List all permissions, optionally filtered by prefix. |
| `exists(string $id)` | `bool` | Check if a permission is registered. |

**Default implementation:** `DynamikDev\Marque\Stores\EloquentPermissionStore`

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

**Default implementation:** `DynamikDev\Marque\Stores\EloquentRoleStore`

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

**Default implementation:** `DynamikDev\Marque\Stores\EloquentAssignmentStore`

---

### `BoundaryStore`

Manages permission ceilings per scope.

| Method | Returns | Description |
| --- | --- | --- |
| `set(string $scope, array $maxPermissions)` | `void` | Set a boundary for a scope. Replaces existing. |
| `remove(string $scope)` | `void` | Remove a boundary. No-op if not set. |
| `find(string $scope)` | `?Boundary` | Get the boundary for a scope. |
| `all()` | `Collection` | Get all boundaries. |

**Default implementation:** `DynamikDev\Marque\Stores\EloquentBoundaryStore`

---

### `Evaluator`

The core authorization engine. Accepts a single `EvaluationRequest` DTO and returns an `EvaluationResult`.

| Method | Returns | Description |
| --- | --- | --- |
| `evaluate(EvaluationRequest $request)` | `EvaluationResult` | Run the full evaluation pipeline and return the decision. |

The `EvaluationRequest` bundles a `Principal`, an action string, an optional `Resource`, and a `Context` (scope + environment). The `EvaluationResult` contains the `Decision` enum, a `decidedBy` string, and optional `matchedStatements` and `trace` arrays (populated when the `trace` config is enabled).

**Default implementation:** `DynamikDev\Marque\Evaluators\CachedEvaluator` (wraps `DefaultEvaluator`)

---

### `PolicyResolver`

Produces `PolicyStatement` collections for an evaluation request. The evaluator calls every registered resolver and merges their results.

| Method | Returns | Description |
| --- | --- | --- |
| `resolve(EvaluationRequest $request)` | `Collection<PolicyStatement>` | Return Allow/Deny statements relevant to the request. |

Each resolver is registered in the `resolvers` config array. See [Adding a custom PolicyResolver](../extending/swapping-implementations.md#adding-a-custom-policyresolver).

**Default implementations:** `IdentityPolicyResolver`, `BoundaryPolicyResolver`, `ResourcePolicyResolver`, `SanctumPolicyResolver`

---

### `ConditionEvaluator`

Evaluates a single condition attached to a `PolicyStatement`.

| Method | Returns | Description |
| --- | --- | --- |
| `passes(Condition $condition, EvaluationRequest $request)` | `bool` | Return whether the condition is satisfied for the request. |

Condition evaluators are registered in the `ConditionRegistry` by type string. Built-in types: `attribute_equals`, `attribute_in`, `environment_equals`, `ip_range`, `time_between`.

---

### `ConditionRegistry`

Maps condition type strings to their `ConditionEvaluator` implementations.

| Method | Returns | Description |
| --- | --- | --- |
| `register(string $type, string $evaluatorClass)` | `void` | Register an evaluator class for a condition type. |
| `evaluatorFor(string $type)` | `ConditionEvaluator` | Retrieve the evaluator for a condition type. |

**Default implementation:** `DynamikDev\Marque\Conditions\DefaultConditionRegistry`

---

### `ResourcePolicyStore`

Manages policy statements attached directly to resource models.

| Method | Returns | Description |
| --- | --- | --- |
| `forResource(string $type, string\|int\|null $id)` | `Collection<PolicyStatement>` | Get all statements for a resource. |
| `attach(string $resourceType, string\|int\|null $resourceId, PolicyStatement $statement)` | `void` | Attach a statement to a resource. |
| `detach(string $resourceType, string\|int\|null $resourceId, string $action)` | `void` | Detach statements matching an action from a resource. |

**Default implementation:** `DynamikDev\Marque\Stores\EloquentResourcePolicyStore`

---

### `Matcher`

Determines whether a granted permission covers a required permission, including wildcard resolution.

| Method | Returns | Description |
| --- | --- | --- |
| `matches(string $granted, string $required)` | `bool` | Does the granted permission cover the required one? |

**Default implementation:** `DynamikDev\Marque\Matchers\WildcardMatcher`

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

**Default implementation:** `DynamikDev\Marque\Resolvers\ModelScopeResolver`

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

**Default implementation:** `DynamikDev\Marque\Documents\JsonDocumentParser`

---

### `DocumentImporter`

Applies a parsed policy document to the stores.

| Method | Returns | Description |
| --- | --- | --- |
| `import(PolicyDocument $document, ImportOptions $options)` | `ImportResult` | Import a document with the given options. |

**Default implementation:** `DynamikDev\Marque\Documents\DefaultDocumentImporter`

---

### `DocumentExporter`

Reads current authorization state and builds a policy document.

| Method | Returns | Description |
| --- | --- | --- |
| `export(?string $scope = null)` | `PolicyDocument` | Export current state, optionally scoped. |

**Default implementation:** `DynamikDev\Marque\Documents\DefaultDocumentExporter`
