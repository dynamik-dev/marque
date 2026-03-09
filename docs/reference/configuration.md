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

### `document_path`

Restrict import/export file paths to a specific directory.

- **Type:** `?string`
- **Default:** `null`

When set, paths used by `PrimitivesManager::import()`, `PrimitivesManager::exportToFile()`, and `php artisan primitives:export --path=...` must resolve inside this directory. Paths outside it throw `InvalidArgumentException`.

---

### `document_format`

The format used for policy document parsing.

- **Type:** `string`
- **Default:** `'json'`

Currently only `'json'` is supported out of the box. Swap the `DocumentParser` binding to support other formats like YAML or TOML.

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
    'document_path' => null,
    'document_format' => 'json',
    'gate_passthrough' => [],
];
```
