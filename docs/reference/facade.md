# Using the Marque Facade

The `Marque` facade is the primary entry point for registering permissions, defining roles, setting boundaries, building resource policies, and importing or exporting policy documents.

```php
use DynamikDev\Marque\Facades\Marque;
```

The facade proxies to `DynamikDev\Marque\MarqueManager`, which delegates to the underlying store contracts. Every method works with any driver that implements the contracts -- swapping the Eloquent stores for a custom implementation requires no changes to facade calls.

---

## Registering permissions

### `permissions()`

Register one or more permission identifiers in bulk.

```php
Marque::permissions(array $permissions): void
```

| Parameter      | Type              | Description                                   |
| -------------- | ----------------- | --------------------------------------------- |
| `$permissions` | `array<int, string>` | Dot-notated permission strings to register |

```php
Marque::permissions(['posts.create', 'posts.read', 'posts.delete']);
```

Registration is idempotent. Calling it with an already-registered permission is a no-op for that identifier.

### `getPermission()`

Look up a single permission by its identifier.

```php
Marque::getPermission(string $id): ?Permission
```

| Parameter | Type     | Description                           |
| --------- | -------- | ------------------------------------- |
| `$id`     | `string` | The dot-notated permission identifier |

**Returns:** `DynamikDev\Marque\Models\Permission` or `null` if the permission is not registered.

```php
$permission = Marque::getPermission('posts.create');
```

---

## Creating and managing roles

### `createRole()`

Create or update a role and return a fluent builder for granting permissions.

```php
Marque::createRole(string $id, string $name, bool $system = false): RoleBuilder
```

| Parameter | Type     | Description                                          |
| --------- | -------- | ---------------------------------------------------- |
| `$id`     | `string` | Unique role identifier (e.g. `editor`, `admin`)      |
| `$name`   | `string` | Human-readable name                                  |
| `$system` | `bool`   | Mark as system role (protected from casual deletion)  |

**Returns:** `DynamikDev\Marque\Support\RoleBuilder` for chaining `grant()`, `deny()`, and assignment calls.

```php
Marque::createRole('editor', 'Editor')
    ->grant(['posts.create', 'posts.read', 'posts.update']);
```

Literal (non-wildcard) permissions passed to `grant()` are auto-registered if they do not already exist. You do not need a separate `Marque::permissions()` call.

#### Creating a system role

```php
Marque::createRole('super-admin', 'Super Admin', system: true)
    ->grant(['*.*']);
```

System roles cannot be deleted through the `RoleStore::remove()` method. Use `removeAll()` (the import pipeline's nuclear option) to bypass this protection.

### `getRole()`

Look up a role by its identifier.

```php
Marque::getRole(string $id): ?Role
```

| Parameter | Type     | Description       |
| --------- | -------- | ----------------- |
| `$id`     | `string` | The role identifier |

**Returns:** `DynamikDev\Marque\Models\Role` or `null` if the role does not exist.

```php
$role = Marque::getRole('editor');
```

### `role()`

Get a builder handle for an existing role. Throws `RuntimeException` if the role does not exist.

```php
Marque::role(string $id): RoleBuilder
```

| Parameter | Type     | Description       |
| --------- | -------- | ----------------- |
| `$id`     | `string` | The role identifier |

**Returns:** `DynamikDev\Marque\Support\RoleBuilder` for chaining permission and assignment calls.

```php
Marque::role('editor')->grant(['comments.create']);
```

Use `role()` when the role already exists and you want to modify it. Use `createRole()` when you need to create the role first.

> `role()` throws a `RuntimeException` if the role does not exist. Use `getRole()` first if you need to check.

---

## Setting permission boundaries

### `boundary()`

Get a builder handle for the boundary on a scope, optionally setting its maximum permissions in one call.

```php
Marque::boundary(mixed $scope, ?array $maxPermissions = null): BoundaryBuilder
```

| Parameter         | Type                      | Description                                              |
| ----------------- | ------------------------- | -------------------------------------------------------- |
| `$scope`          | `mixed`                   | A `Scopeable` model or raw scope string (e.g. `team::5`) |
| `$maxPermissions` | `array<int, string>\|null` | If provided, immediately calls `permits()` on the builder |

**Returns:** `DynamikDev\Marque\Support\BoundaryBuilder` for chaining `permits()` and `remove()`.

```php
Marque::boundary('team::5')->permits(['posts.create', 'posts.read']);
```

#### Setting permissions inline

Pass the permissions array as the second argument to skip the separate `permits()` call:

```php
Marque::boundary('team::5', ['posts.create', 'posts.read']);
```

#### Using a Scopeable model

```php
Marque::boundary($team)->permits(['posts.*', 'comments.*']);
```

The scope resolver converts the model to its canonical `type::id` string.

> `boundary()` throws `InvalidArgumentException` if the scope resolves to `null`.

### `createBoundary()`

Create a boundary builder for a scope. Functionally identical to `boundary()` without the inline `$maxPermissions` shorthand.

```php
Marque::createBoundary(mixed $scope): BoundaryBuilder
```

| Parameter | Type    | Description                                              |
| --------- | ------- | -------------------------------------------------------- |
| `$scope`  | `mixed` | A `Scopeable` model or raw scope string (e.g. `team::5`) |

**Returns:** `DynamikDev\Marque\Support\BoundaryBuilder`.

```php
Marque::createBoundary($team)->permits(['posts.create', 'posts.read']);
```

### `getBoundary()`

Look up the boundary for a scope.

```php
Marque::getBoundary(mixed $scope): ?Boundary
```

| Parameter | Type    | Description                                              |
| --------- | ------- | -------------------------------------------------------- |
| `$scope`  | `mixed` | A `Scopeable` model or raw scope string (e.g. `team::5`) |

**Returns:** `DynamikDev\Marque\Models\Boundary` or `null` if no boundary is set for the scope.

```php
$boundary = Marque::getBoundary('team::5');
```

---

## Building resource policies

### `resource()`

Start a fluent builder for attaching type-level resource policies to a resource class.

```php
Marque::resource(string $resourceType): ResourcePolicyBuilder
```

| Parameter       | Type     | Description                                             |
| --------------- | -------- | ------------------------------------------------------- |
| `$resourceType` | `string` | Fully qualified class name or type string for the resource |

**Returns:** `DynamikDev\Marque\Support\ResourcePolicyBuilder` for chaining `ownerCan()`, `anyoneCan()`, `when()`, `ownedBy()`, and `detach()`.

```php
use App\Models\Post;

Marque::resource(Post::class)
    ->ownerCan(['posts.update', 'posts.delete']);
```

See [Declaring resource policies with the fluent builder](../authorization/using-resource-policies.md#declaring-resource-policies-with-the-fluent-builder) for full usage.

---

## Importing policy documents

### `import()`

Import a policy document from a file path or raw JSON string.

```php
Marque::import(string $pathOrContent, ?ImportOptions $options = null): ImportResult
```

| Parameter       | Type                                         | Description                                          |
| --------------- | -------------------------------------------- | ---------------------------------------------------- |
| `$pathOrContent` | `string`                                    | File path to a `.json` file, or raw JSON content     |
| `$options`      | `DynamikDev\Marque\DTOs\ImportOptions\|null` | Import behavior flags (defaults to validate + merge) |

**Returns:** `DynamikDev\Marque\DTOs\ImportResult` containing arrays of created/updated identifiers and warnings.

#### Importing from a file

```php
$result = Marque::import('policies/community.json');
```

#### Importing from a raw JSON string

```php
$json = json_encode([
    'version' => '1.0',
    'permissions' => ['posts.create', 'posts.read'],
    'roles' => [
        ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.read']],
    ],
]);

$result = Marque::import($json);
```

#### Importing with options

```php
use DynamikDev\Marque\DTOs\ImportOptions;

$result = Marque::import('policies/community.json', new ImportOptions(
    dryRun: true,
    skipAssignments: true,
));
```

#### Inspecting the result

```php
$result->permissionsCreated; // ['posts.create', 'posts.read']
$result->rolesCreated;       // ['editor']
$result->rolesUpdated;       // []
$result->assignmentsCreated; // 0
$result->warnings;           // []
```

The facade auto-detects whether the string is a file path or raw content. Strings starting with `{` or `[` are treated as raw JSON. Strings containing a directory separator or ending in `.json` are treated as file paths.

---

## Exporting policy documents

### `export()`

Export the current authorization configuration as a serialized JSON string.

```php
Marque::export(?string $scope = null): string
```

| Parameter | Type            | Description                               |
| --------- | --------------- | ----------------------------------------- |
| `$scope`  | `string\|null`  | Scope to filter the export, or `null` for all |

**Returns:** JSON string representing the full policy document.

```php
$json = Marque::export();
```

### `exportToFile()`

Export the current authorization configuration directly to a file.

```php
Marque::exportToFile(string $path, ?string $scope = null): void
```

| Parameter | Type            | Description                               |
| --------- | --------------- | ----------------------------------------- |
| `$path`   | `string`        | File path to write the JSON output        |
| `$scope`  | `string\|null`  | Scope to filter the export, or `null` for all |

```php
Marque::exportToFile(storage_path('app/policies/backup.json'));
```

The path is validated through `PathValidator` before writing.

---

## Builder reference

The facade returns three builder types. Each provides a fluent, chainable API.

### `RoleBuilder`

Returned by `createRole()` and `role()`.

| Method                                      | Returns       | Description                                                |
| ------------------------------------------- | ------------- | ---------------------------------------------------------- |
| `grant(array $permissions)`                 | `self`        | Add permissions to the role. Auto-registers literal permissions. |
| `deny(array $permissions)`                  | `self`        | Add deny rules (prefixed with `!`).                        |
| `ungrant(array $permissions)`               | `self`        | Remove specific permissions from the role.                 |
| `permissions()`                             | `array`       | Get the role's current permission strings.                 |
| `assignTo(Model $subject, mixed $scope = null)` | `self`   | Assign the role to a subject, optionally scoped.           |
| `revokeFrom(Model $subject, mixed $scope = null)` | `self` | Revoke the role from a subject, optionally scoped.         |
| `remove()`                                  | `void`        | Delete the role entirely.                                  |

```php
Marque::createRole('moderator', 'Moderator')
    ->grant(['posts.read', 'posts.update', 'comments.delete'])
    ->deny(['posts.delete'])
    ->assignTo($user, scope: $team);
```

#### Revoking a role from a subject

```php
Marque::role('moderator')->revokeFrom($user, scope: $team);
```

#### Checking a role's permissions

```php
$permissions = Marque::role('editor')->permissions();
// ['posts.create', 'posts.read', 'posts.update']
```

#### Removing a role

```php
Marque::role('editor')->remove();
```

### `BoundaryBuilder`

Returned by `boundary()` and `createBoundary()`.

| Method                    | Returns | Description                                          |
| ------------------------- | ------- | ---------------------------------------------------- |
| `permits(array $permissions)` | `self`  | Set the maximum allowed permissions for this scope.  |
| `remove()`                | `void`  | Delete the boundary for this scope entirely.         |

Each call to `permits()` replaces the entire permission set rather than merging.

```php
Marque::boundary('org::acme')
    ->permits(['posts.*', 'comments.*']);
```

#### Removing a boundary

```php
Marque::boundary('org::acme')->remove();
```

### `ResourcePolicyBuilder`

Returned by `resource()`.

| Method                                                  | Returns | Description                                                       |
| ------------------------------------------------------- | ------- | ----------------------------------------------------------------- |
| `ownedBy(string $resourceKey, string $subjectKey = 'id')` | `self` | Set the ownership field mapping before calling `ownerCan()`.      |
| `ownerCan(array\|string $actions)`                      | `self`  | Attach allow statements gated by the ownership condition.         |
| `anyoneCan(array\|string $actions)`                     | `self`  | Attach unconditional allow statements (or conditional inside `when()`). |
| `when(array $conditions, Closure $scope)`               | `self`  | Scope allow statements to resource attribute conditions.          |
| `detach(string $action)`                                | `self`  | Remove type-level statements for the given action.                |

```php
use App\Models\Post;

Marque::resource(Post::class)
    ->ownedBy('author_id')
    ->ownerCan(['posts.update', 'posts.delete'])
    ->when(['status' => 'published'], function ($policy) {
        $policy->anyoneCan('posts.view');
    });
```

The default ownership mapping compares the subject's `id` to the resource's `user_id`. Call `ownedBy()` before `ownerCan()` to override either field.

---

## `ImportOptions` reference

| Property           | Type   | Default | Description                                           |
| ------------------ | ------ | ------- | ----------------------------------------------------- |
| `$validate`        | `bool` | `true`  | Validate the document structure before importing       |
| `$merge`           | `bool` | `true`  | Merge with existing data instead of replacing          |
| `$dryRun`          | `bool` | `false` | Preview changes without writing to the database        |
| `$skipAssignments` | `bool` | `false` | Import permissions and roles only, skip assignments    |

```php
use DynamikDev\Marque\DTOs\ImportOptions;

$options = new ImportOptions(
    validate: true,
    dryRun: true,
    skipAssignments: true,
);
```

## `ImportResult` reference

| Property              | Type              | Description                           |
| --------------------- | ----------------- | ------------------------------------- |
| `$permissionsCreated` | `array<int, string>` | Permission identifiers that were created |
| `$rolesCreated`       | `array<int, string>` | Role identifiers that were created    |
| `$rolesUpdated`       | `array<int, string>` | Role identifiers that were updated    |
| `$assignmentsCreated` | `int`             | Number of new assignments created     |
| `$warnings`           | `array<int, string>` | Warning messages from the import      |
