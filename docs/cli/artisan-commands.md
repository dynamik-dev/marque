# Using the Artisan Commands

The package registers nine Artisan commands for inspecting, managing, and troubleshooting your authorization config from the terminal.

## Listing permissions

### `primitives:permissions`

Display all registered permissions.

```bash
php artisan primitives:permissions
```

Outputs a table with `ID` and `Description` columns.

```bash
php artisan primitives:permissions
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

### `primitives:roles`

Display all roles and their permissions.

```bash
php artisan primitives:roles
```

Outputs a table with `ID`, `Name`, `System`, and `Permissions` columns.

## Listing assignments

### `primitives:assignments`

Display role assignments for a subject or scope.

```bash
php artisan primitives:assignments {subject?} {--scope=}
```

| Argument/Option | Description |
| --- | --- |
| `subject` | Subject in `type::id` format (e.g., `user::42`) |
| `--scope` | Filter to a specific scope string |

```bash
# All assignments for a user
php artisan primitives:assignments user::42

# Scoped assignments for a user
php artisan primitives:assignments user::42 --scope="group::5"

# All assignments in a scope (no subject)
php artisan primitives:assignments --scope="group::5"
```

Outputs a table with `Subject Type`, `Subject ID`, `Role`, and `Scope` columns.

## Debugging a permission decision

### `primitives:explain`

Show the full evaluation trace for a specific permission check.

```bash
php artisan primitives:explain {subject} {permission} {--scope=}
```

| Argument/Option | Description |
| --- | --- |
| `subject` | Subject in `type::id` format (e.g., `user::42`) |
| `permission` | Permission to check (e.g., `posts.delete`) |
| `--scope` | Optional scope string |

```bash
php artisan primitives:explain user::42 "posts.delete" --scope="group::5"
```

Displays the evaluation trace: assignments found, permissions checked per role, boundary status, and the final `ALLOW` or `DENY` result.

> The `explain` config option must be set to `true` for this command to work. If disabled, the command prints an error message.

## Importing a policy document

### `primitives:import`

Import a policy document from a JSON file.

```bash
php artisan primitives:import {path} [options]
```

| Option | Description |
| --- | --- |
| `--dry-run` | Preview changes without writing to database |
| `--skip-assignments` | Import roles and permissions only |
| `--replace` | Replace all existing data (requires `--force`) |
| `--force` | Confirm destructive operations |

```bash
# Standard import (merge)
php artisan primitives:import policies/community.json

# Preview changes
php artisan primitives:import policies/community.json --dry-run

# Roles and permissions only
php artisan primitives:import policies/community.json --skip-assignments

# Full replace (destructive)
php artisan primitives:import policies/community.json --replace --force
```

## Exporting the current state

### `primitives:export`

Export the current authorization config as JSON.

```bash
php artisan primitives:export {--scope=} {--path=} {--stdout}
```

| Option | Description |
| --- | --- |
| `--scope` | Export only data relevant to this scope |
| `--path` | Write to a file instead of stdout |
| `--stdout` | Print to stdout (default when no `--path`) |

```bash
# Export to file
php artisan primitives:export --path=policies/backup.json

# Export scoped
php artisan primitives:export --scope="group::5" --path=policies/group-5.json

# Export to stdout (pipe to jq, diff, etc.)
php artisan primitives:export | jq '.roles[] | .id'
```

## Validating a document

### `primitives:validate`

Check a policy document for errors without importing it.

```bash
php artisan primitives:validate {path}
```

```bash
php artisan primitives:validate policies/community.json
```

Prints "Policy document is valid." on success, or lists validation errors.

## Clearing the permission cache

### `primitives:cache-clear`

Flush the permission evaluation cache.

```bash
php artisan primitives:cache-clear
```

Clears all cached `canDo()` results. The cache rebuilds on the next permission check.

## Syncing permissions from your seeder

### `primitives:sync`

Re-run your `PermissionSeeder` idempotently.

```bash
php artisan primitives:sync
```

Calls `db:seed --class=PermissionSeeder`. Use this after modifying your seeder to apply changes without a full database refresh.
