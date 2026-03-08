# Events Reference

All events live in `DynamikDev\PolicyEngine\Events\` and use readonly constructor properties. The default store implementations dispatch these events — custom stores should dispatch them too for cache invalidation and observability.

---

### `PermissionCreated`

Fired when a new permission is registered via `PermissionStore::register()`.

| Property | Type | Description |
| --- | --- | --- |
| `$permission` | `string` | The permission ID that was created |

Not fired when registering a permission that already exists (idempotent).

---

### `PermissionDeleted`

Fired when a permission is removed via `PermissionStore::remove()`.

| Property | Type | Description |
| --- | --- | --- |
| `$permission` | `string` | The permission ID that was removed |

Triggers cache invalidation.

---

### `RoleCreated`

Fired when a new role is created via `RoleStore::save()`.

| Property | Type | Description |
| --- | --- | --- |
| `$role` | `Role` | The newly created role model |

---

### `RoleUpdated`

Fired when an existing role is updated via `RoleStore::save()`.

| Property | Type | Description |
| --- | --- | --- |
| `$role` | `Role` | The updated role model |
| `$changes` | `array` | Changed attributes from `getChanges()` |

Triggers cache invalidation.

---

### `RoleDeleted`

Fired when a role is deleted via `RoleStore::remove()`.

| Property | Type | Description |
| --- | --- | --- |
| `$role` | `Role` | The deleted role model |

Triggers cache invalidation. Not fired when attempting to delete a protected system role (which throws instead).

---

### `AssignmentCreated`

Fired when a role is assigned to a subject via `AssignmentStore::assign()`.

| Property | Type | Description |
| --- | --- | --- |
| `$assignment` | `Assignment` | The newly created assignment model |

Not fired when the assignment already exists (idempotent). Triggers cache invalidation.

---

### `AssignmentRevoked`

Fired when an assignment is removed via `AssignmentStore::revoke()`.

| Property | Type | Description |
| --- | --- | --- |
| `$assignment` | `Assignment` | The revoked assignment model |

Not fired when revoking an assignment that doesn't exist (no-op). Triggers cache invalidation.

---

### `AuthorizationDenied`

Fired when a `canDo()` check returns `false`, if `log_denials` is enabled in the config.

| Property | Type | Description |
| --- | --- | --- |
| `$subject` | `string` | Subject identifier (e.g., `App\Models\User:42`) |
| `$permission` | `string` | The permission that was denied |
| `$scope` | `?string` | The scope string, or null for global checks |

Does not trigger cache invalidation.

---

### `DocumentImported`

Fired after a policy document is successfully imported (not during dry runs).

| Property | Type | Description |
| --- | --- | --- |
| `$result` | `ImportResult` | The import result with counts and warnings |

Does not trigger cache invalidation directly. The individual store operations (permission creation, role saves, assignment creation) fire their own events, which handle invalidation.

---

## Events that trigger cache invalidation

| Event | Invalidates cache? |
| --- | --- |
| `PermissionCreated` | No |
| `PermissionDeleted` | Yes |
| `RoleCreated` | No |
| `RoleUpdated` | Yes |
| `RoleDeleted` | Yes |
| `AssignmentCreated` | Yes |
| `AssignmentRevoked` | Yes |
| `AuthorizationDenied` | No |
| `DocumentImported` | No |

The `InvalidatePermissionCache` listener flushes the entire configured cache store when triggered. See [customizing the cache](../extending/customizing-the-cache.md) for details.
