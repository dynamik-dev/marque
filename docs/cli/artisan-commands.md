# Using the Artisan Commands


## Listing permissions

### `policy-engine:permissions`

Display all registered permissions.

```bash
php artisan policy-engine:permissions
```

Outputs a table with `ID` and `Description` columns.

```bash
php artisan policy-engine:permissions
```

```
+--------------------+-------------+
| ID                 | Description |
+--------------------+-------------+
| posts.read         |             |
| posts.create       |             |
| posts.update.own   |             |
| posts.delete.any   |             |
+--------------------+-------------+
```

## Listing roles

### `policy-engine:roles`

Display all roles and their permissions.

```bash
php artisan policy-engine:roles
```

Outputs a table with `ID`, `Name`, `System`, and `Permissions` columns.

## Listing assignments

### `policy-engine:assignments`

Display role assignments for a subject or scope.

```bash
php artisan policy-engine:assignments {subject?} {--scope=}
```

| Argument/Option | Description |
| --- | --- |
| `subject` | Subject in `type::id` format (e.g., `user::42`) |
| `--scope` | Filter to a specific scope string |

```bash
# All assignments for a user
php artisan policy-engine:assignments user::42

# Scoped assignments for a user
php artisan policy-engine:assignments user::42 --scope="group::5"

# All assignments in a scope (no subject)
php artisan policy-engine:assignments --scope="group::5"
```

Outputs a table with `Subject Type`, `Subject ID`, `Role`, and `Scope` columns.

## Debugging a permission decision

### `policy-engine:explain`

Show the evaluation result for a specific permission check.

```bash
php artisan policy-engine:explain {subject} {permission} {--scope=}
```

| Argument/Option | Description |
| --- | --- |
| `subject` | Subject in `type::id` format (e.g., `user::42`) |
| `permission` | Permission to check (e.g., `posts.delete`) |
| `--scope` | Optional scope string |

```bash
php artisan policy-engine:explain user::42 "posts.delete" --scope="group::5"
```

Displays the decision (`ALLOW` or `DENY`), the resolver that decided it (`decidedBy`), and any matched policy statements with their effect, action, and source.

```
  Subject:    user:42
  Permission: posts.delete
  Result:     DENY
  Decided by: role:moderator
  Scope:      group::5

  Matched statements:
    [ALLOW] posts.* (source: role:moderator)
    [DENY] posts.delete (source: boundary:group::5)

  Cache hit:  N/A
```

> The `trace` config option must be set to `true` for this command to work. If disabled, the command prints an error message.

## Importing a policy document

### `policy-engine:import`

Import a policy document from a JSON file.

```bash
php artisan policy-engine:import {path} [options]
```

| Option | Description |
| --- | --- |
| `--dry-run` | Preview changes without writing to database |
| `--skip-assignments` | Import roles and permissions only |
| `--replace` | Replace all existing data (requires `--force`) |
| `--force` | Confirm destructive operations |

```bash
# Standard import (merge)
php artisan policy-engine:import policies/community.json

# Preview changes
php artisan policy-engine:import policies/community.json --dry-run

# Roles and permissions only
php artisan policy-engine:import policies/community.json --skip-assignments

# Full replace (destructive)
php artisan policy-engine:import policies/community.json --replace --force
```

## Exporting the current state

### `policy-engine:export`

Export the current authorization config as JSON.

```bash
php artisan policy-engine:export {--scope=} {--path=} {--stdout}
```

| Option | Description |
| --- | --- |
| `--scope` | Export only data relevant to this scope |
| `--path` | Write to a file instead of stdout |

When `--path` is omitted, the command prints JSON to stdout. If `policy-engine.document_path` is set, `--path` must resolve inside that directory. Otherwise the command fails with an error and exit code `1`.

```bash
# Export to file
php artisan policy-engine:export --path=policies/backup.json

# Export scoped
php artisan policy-engine:export --scope="group::5" --path=policies/group-5.json

# Export to stdout (pipe to jq, diff, etc.)
php artisan policy-engine:export | jq '.roles[] | .id'
```

## Validating a document

### `policy-engine:validate`

Check a policy document for errors without importing it.

```bash
php artisan policy-engine:validate {path}
```

```bash
php artisan policy-engine:validate policies/community.json
```

Prints "Policy document is valid." on success, or lists validation errors.

## Clearing the permission cache

### `policy-engine:cache-clear`

Flush the permission evaluation cache.

```bash
php artisan policy-engine:cache-clear
```

On tagged stores (Redis, Memcached), flushes only the `policy-engine` tag. On non-tagged stores (file, database), increments the global generation counter so all previously cached entries become unreachable and expire via TTL.

The command reports which mechanism was used:

```
# Tagged store
Policy engine cache cleared (tagged flush).

# Non-tagged store
Policy engine cache cleared (generation counter incremented — stale entries expire via TTL).
```

## Syncing permissions from your seeder

### `policy-engine:sync`

Re-run your `PermissionSeeder` idempotently.

```bash
php artisan policy-engine:sync
```

Calls `db:seed --class=PermissionSeeder`. Use this after modifying your seeder to apply changes without a full database refresh.
