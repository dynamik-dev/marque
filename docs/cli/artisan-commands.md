# Using the Artisan Commands


## Listing permissions

### `marque:permissions`

Display all registered permissions.

```bash
php artisan marque:permissions
```

Outputs a table with `ID` and `Description` columns.

```bash
php artisan marque:permissions
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

### `marque:roles`

Display all roles and their permissions.

```bash
php artisan marque:roles
```

Outputs a table with `ID`, `Name`, `System`, and `Permissions` columns.

## Listing assignments

### `marque:assignments`

Display role assignments for a subject or scope.

```bash
php artisan marque:assignments {subject?} {--scope=}
```

| Argument/Option | Description |
| --- | --- |
| `subject` | Subject in `type::id` format (e.g., `user::42`) |
| `--scope` | Filter to a specific scope string |

```bash
# All assignments for a user
php artisan marque:assignments user::42

# Scoped assignments for a user
php artisan marque:assignments user::42 --scope="group::5"

# All assignments in a scope (no subject)
php artisan marque:assignments --scope="group::5"
```

Outputs a table with `Subject Type`, `Subject ID`, `Role`, and `Scope` columns.

## Debugging a permission decision

### `marque:explain`

Show the evaluation result for a specific permission check.

```bash
php artisan marque:explain {subject} {permission} {--scope=}
```

| Argument/Option | Description |
| --- | --- |
| `subject` | Subject in `type::id` format (e.g., `user::42`) |
| `permission` | Permission to check (e.g., `posts.delete`) |
| `--scope` | Optional scope string |

```bash
php artisan marque:explain user::42 "posts.delete" --scope="group::5"
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

### `marque:import`

Import a policy document from a JSON file.

```bash
php artisan marque:import {path} [options]
```

| Option | Description |
| --- | --- |
| `--dry-run` | Preview changes without writing to database |
| `--skip-assignments` | Import roles and permissions only |
| `--replace` | Replace all existing data (requires `--force`) |
| `--force` | Confirm destructive operations |

```bash
# Standard import (merge)
php artisan marque:import policies/community.json

# Preview changes
php artisan marque:import policies/community.json --dry-run

# Roles and permissions only
php artisan marque:import policies/community.json --skip-assignments

# Full replace (destructive)
php artisan marque:import policies/community.json --replace --force
```

## Exporting the current state

### `marque:export`

Export the current authorization config as JSON.

```bash
php artisan marque:export {--scope=} {--path=} {--stdout}
```

| Option | Description |
| --- | --- |
| `--scope` | Export only data relevant to this scope |
| `--path` | Write to a file instead of stdout |

When `--path` is omitted, the command prints JSON to stdout. If `marque.document_path` is set, `--path` must resolve inside that directory. Otherwise the command fails with an error and exit code `1`.

```bash
# Export to file
php artisan marque:export --path=policies/backup.json

# Export scoped
php artisan marque:export --scope="group::5" --path=policies/group-5.json

# Export to stdout (pipe to jq, diff, etc.)
php artisan marque:export | jq '.roles[] | .id'
```

## Validating a document

### `marque:validate`

Check a policy document for errors without importing it.

```bash
php artisan marque:validate {path}
```

```bash
php artisan marque:validate policies/community.json
```

Prints "Policy document is valid." on success, or lists validation errors.

## Clearing the permission cache

### `marque:cache-clear`

Flush the permission evaluation cache.

```bash
php artisan marque:cache-clear
```

On tagged stores (Redis, Memcached), flushes only the `marque` tag. On non-tagged stores (file, database), increments the global generation counter so all previously cached entries become unreachable and expire via TTL.

The command reports which mechanism was used:

```
# Tagged store
Policy engine cache cleared (tagged flush).

# Non-tagged store
Policy engine cache cleared (generation counter incremented — stale entries expire via TTL).
```

## Syncing permissions from your seeder

### `marque:sync`

Re-run your `PermissionSeeder` idempotently.

```bash
php artisan marque:sync
```

Calls `db:seed --class=PermissionSeeder`. Use this after modifying your seeder to apply changes without a full database refresh.
