# Configuration Reference

Publish the config file with:

```bash
php artisan vendor:publish --tag=marque-config
```

This creates `config/marque.php`.

---

### `cache.enabled`

Enable or disable evaluation caching.

- **Type:** `bool`
- **Default:** `true`

When disabled, every permission check queries the database directly. Disable in testing or when using a custom `Evaluator` that handles its own caching.

---

### `cache.store`

The Laravel cache store to use for permission caching.

- **Type:** `string`
- **Default:** `env('MARQUE_CACHE_STORE', 'default')`

Maps to a store defined in `config/cache.php`. The value `'default'` uses your application's default cache driver.

---

### `cache.ttl`

How long cached evaluation results are stored, in seconds.

- **Type:** `int`
- **Default:** `300` (5 minutes)

Cached results are also invalidated by events (assignment changes, role updates, permission deletions, boundary changes), so this TTL is a safety net, not the primary invalidation mechanism. A shorter TTL reduces the window for stale cached permissions after revocation.

---

### `protect_system_roles`

Prevent runtime deletion of roles marked as system.

- **Type:** `bool`
- **Default:** `true`

When enabled, calling `remove()` on a role where `is_system` is `true` throws a `RuntimeException`. Disable this if you need to programmatically manage system roles outside of seeders.

---

### `log_denials`

Dispatch an `AuthorizationDenied` event when a permission check fails.

- **Type:** `bool`
- **Default:** `true`

Useful for audit logging and monitoring. Disable if denial events create too much noise in high-traffic applications.

---

### `trace`

Enable populated trace data in `EvaluationResult`.

- **Type:** `bool`
- **Default:** `env('MARQUE_TRACE', false)`

When enabled, `explain()` returns `matchedStatements` and `trace` arrays in the `EvaluationResult`. When disabled, `explain()` still returns the `decision` and `decidedBy` fields, but the arrays are empty. Enable in development and staging for debugging. Disable in production — the trace adds overhead and may expose internal authorization structure.

> **Security:** When enabled, trace data exposes the full authorization decision tree — roles, permissions, boundaries, resolver sources, and scope context. Never enable this in production unless access is restricted. The `marque:explain` Artisan command reads this config at runtime; lock down Artisan access in production environments.

---

### `deny_unbounded_scopes`

Deny scoped permission checks when the evaluated scope does not have a boundary record.

- **Type:** `bool`
- **Default:** `false`

When enabled, scoped permission checks fail closed if no boundary exists for that scope. Global (unscoped) checks are unaffected.

---

### `enforce_boundaries_on_global`

Apply boundary checks to unscoped (global) permission evaluations.

- **Type:** `bool`
- **Default:** `false`

By default, global assignments are inherently unbounded — boundaries only restrict scoped checks. When enabled, the evaluator applies boundary filtering even when no scope is present. This means a user with global `*.*` can still be restricted by boundaries defined on scopes they access.

Enable this in multi-tenant applications where global roles should not bypass scope-level permission ceilings. Be aware that enabling this requires boundaries to exist for every scope the subject might access; missing boundaries may cause unexpected denials (unless `deny_unbounded_scopes` is also `false`).

---

### `table_prefix`

Prefix prepended to all marque database table names.

- **Type:** `string`
- **Default:** `''` (empty string)

Useful when another package (e.g. Spatie Permission, Bouncer) already uses generic table names like `permissions` or `roles`. Set to `'pe_'` to produce tables named `pe_permissions`, `pe_roles`, `pe_assignments`, `pe_boundaries`, and `pe_role_permissions`.

```php
'table_prefix' => 'pe_',
```

After changing the prefix, you must re-run migrations. If you are adding the prefix to an existing installation, rename the existing tables first or create a migration to rename them.

---

### `resolvers`

The ordered list of `PolicyResolver` classes that form the evaluation chain.

- **Type:** `array`
- **Default:**
```php
'resolvers' => [
    \DynamikDev\Marque\Resolvers\IdentityPolicyResolver::class,
    \DynamikDev\Marque\Resolvers\BoundaryPolicyResolver::class,
    \DynamikDev\Marque\Resolvers\ResourcePolicyResolver::class,
    \DynamikDev\Marque\Resolvers\SanctumPolicyResolver::class,
],
```

Each resolver receives an `EvaluationRequest` and returns a collection of `PolicyStatement` objects (Allow or Deny). The evaluator merges statements from all resolvers and applies deny-wins logic.

| Resolver | Purpose |
| --- | --- |
| `IdentityPolicyResolver` | Resolves role assignments into Allow/Deny statements |
| `BoundaryPolicyResolver` | Emits Deny statements for permissions outside scope boundaries |
| `ResourcePolicyResolver` | Returns statements attached to a specific resource model |
| `SanctumPolicyResolver` | Emits Deny statements for permissions not in the Sanctum token's abilities |

Add custom resolvers to this array. Remove `SanctumPolicyResolver` if you do not use Sanctum. See [Adding a custom PolicyResolver](../extending/swapping-implementations.md#adding-a-custom-policyresolver).

---

### `seeder_class`

The seeder class invoked by `marque:sync`.

- **Type:** `string`
- **Default:** `'PermissionSeeder'`

Change this if your permission seeder uses a different class name.

---

### `document_path`

Restrict import/export file paths to a specific directory.

- **Type:** `?string`
- **Default:** `null`

When set, paths used by `MarqueManager::import()`, `MarqueManager::exportToFile()`, and `php artisan marque:export --path=...` must resolve inside this directory. Paths outside it throw `InvalidArgumentException`.

---

### `gate_passthrough`

Dot-notated abilities that the Gate hook should skip.

- **Type:** `array`
- **Default:** `[]`

The Gate hook intercepts all dot-notated abilities (anything containing a `.`) and routes them through Marque. Abilities listed here are excluded from that interception, allowing other Gate definitions or model policies to handle them instead.

```php
'gate_passthrough' => [
    'admin.panel',
    'stripe.webhook',
],
```

Use this when you have dot-notated abilities that are not managed by Marque.

---

### `import_subject_types`

Additional subject types allowed during document import.

- **Type:** `array`
- **Default:** `[]`

During import, subject types in the `assignments` section are validated against Laravel's morph map. If you use subject types in your policy documents that are not in the morph map (e.g., `user` when your morph map uses `App\Models\User`), add them here.

```php
'import_subject_types' => [
    'user',
    'team',
],
```

When both the morph map and this config are empty, subject type validation is skipped entirely.

---

## Full default config

```php
// config/marque.php

use DynamikDev\Marque\Resolvers\BoundaryPolicyResolver;
use DynamikDev\Marque\Resolvers\IdentityPolicyResolver;
use DynamikDev\Marque\Resolvers\ResourcePolicyResolver;
use DynamikDev\Marque\Resolvers\SanctumPolicyResolver;

return [
    'cache' => [
        'enabled' => true,
        'store' => env('MARQUE_CACHE_STORE', 'default'),
        'ttl' => 60 * 5,
    ],
    'protect_system_roles' => true,
    'log_denials' => true,
    'trace' => env('MARQUE_TRACE', false),
    'deny_unbounded_scopes' => false,
    'enforce_boundaries_on_global' => false,
    'table_prefix' => '',
    'seeder_class' => 'PermissionSeeder',
    'document_path' => null,
    'gate_passthrough' => [],
    'import_subject_types' => [],

    'resolvers' => [
        IdentityPolicyResolver::class,
        BoundaryPolicyResolver::class,
        ResourcePolicyResolver::class,
        SanctumPolicyResolver::class,
    ],
];
```
