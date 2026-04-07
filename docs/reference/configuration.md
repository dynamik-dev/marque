# Configuration Reference

Publish the config file with:

```bash
php artisan vendor:publish --tag=policy-engine-config
```

This creates `config/policy-engine.php`.

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
- **Default:** `env('POLICY_ENGINE_CACHE_STORE', 'default')`

Maps to a store defined in `config/cache.php`. The value `'default'` uses your application's default cache driver.

---

### `cache.ttl`

How long cached evaluation results are stored, in seconds.

- **Type:** `int`
- **Default:** `3600` (1 hour)

Cached results are also invalidated by events (assignment changes, role updates, permission deletions, boundary changes), so this TTL is a safety net, not the primary invalidation mechanism.

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

### `explain`

Enable the `explain()` evaluation trace.

- **Type:** `bool`
- **Default:** `env('POLICY_ENGINE_EXPLAIN', false)`

When disabled, calling `explain()` throws a `RuntimeException`. Enable in development and staging for debugging. Disable in production — the trace adds overhead and may expose internal authorization structure.

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

Prefix prepended to all policy-engine database table names.

- **Type:** `string`
- **Default:** `''` (empty string)

Useful when another package (e.g. Spatie Permission, Bouncer) already uses generic table names like `permissions` or `roles`. Set to `'pe_'` to produce tables named `pe_permissions`, `pe_roles`, `pe_assignments`, `pe_boundaries`, and `pe_role_permissions`.

```php
'table_prefix' => 'pe_',
```

After changing the prefix, you must re-run migrations. If you are adding the prefix to an existing installation, rename the existing tables first or create a migration to rename them.

---

### `document_path`

Restrict import/export file paths to a specific directory.

- **Type:** `?string`
- **Default:** `null`

When set, paths used by `PrimitivesManager::import()`, `PrimitivesManager::exportToFile()`, and `php artisan primitives:export --path=...` must resolve inside this directory. Paths outside it throw `InvalidArgumentException`.

---

### `gate_passthrough`

Dot-notated abilities that the Gate hook should skip.

- **Type:** `array`
- **Default:** `[]`

The Gate hook intercepts all dot-notated abilities (anything containing a `.`) and routes them through Policy Engine. Abilities listed here are excluded from that interception, allowing other Gate definitions or model policies to handle them instead.

```php
'gate_passthrough' => [
    'admin.panel',
    'stripe.webhook',
],
```

Use this when you have dot-notated abilities that are not managed by Policy Engine.

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
// config/policy-engine.php

return [
    'cache' => [
        'enabled' => true,
        'store' => env('POLICY_ENGINE_CACHE_STORE', 'default'),
        'ttl' => 60 * 60,
    ],
    'protect_system_roles' => true,
    'log_denials' => true,
    'explain' => env('POLICY_ENGINE_EXPLAIN', false),
    'deny_unbounded_scopes' => false,
    'enforce_boundaries_on_global' => false,
    'table_prefix' => '',
    'document_path' => null,
    'gate_passthrough' => [],
    'import_subject_types' => [],
];
```
