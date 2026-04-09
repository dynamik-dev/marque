# Importing and Exporting Policy Documents

Import JSON documents to apply authorization config. Export your current state to a portable document for version control, environment sync, or backup. Export always produces v2 format; import accepts both v1 and v2. See [document format](document-format.md) for the JSON structure.

## Importing a document from a file

```php
use DynamikDev\PolicyEngine\Facades\PolicyEngine;

$result = PolicyEngine::import(storage_path('policies/community.json'));
```

If the argument is a file path that exists on disk, the content is read from the file. Otherwise it's treated as a raw JSON string.

## Importing from a JSON string

```php
$result = PolicyEngine::import($jsonString);
```

Returns an `ImportResult` with details on what was created or changed.

```php
$result->permissionsCreated;   // ['posts.report']
$result->rolesCreated;         // ['triage']
$result->rolesUpdated;         // ['community-lead']
$result->assignmentsCreated;   // 2
$result->warnings;             // ['Permission "posts.archive" not registered']
```

## Importing with options

```php
use DynamikDev\PolicyEngine\DTOs\ImportOptions;

$result = PolicyEngine::import($jsonString, new ImportOptions(
    validate: true,
    merge: true,
    dryRun: false,
    skipAssignments: false,
));
```

| Option | Default | Description |
| --- | --- | --- |
| `validate` | `true` | Collect warnings for unregistered permission references |
| `merge` | `true` | Merge with existing data. When `false`, replaces all existing data |
| `dryRun` | `false` | Preview changes without writing to the database |
| `skipAssignments` | `false` | Import roles and permissions only, skip assignment rows |

## Previewing changes with a dry run

```php
$result = PolicyEngine::import($jsonString, new ImportOptions(dryRun: true));

$result->permissionsCreated;   // what would be created
$result->rolesCreated;         // what would be created
$result->assignmentsCreated;   // how many would be created
```

No data is written. Use this to preview the impact before a real import.

## Replacing all existing data

```php
$result = PolicyEngine::import($jsonString, new ImportOptions(merge: false));
```

When `merge` is `false`, the importer deletes all existing assignments, role permissions, boundaries, roles, and permissions before importing the document. This is destructive — use `dryRun: true` first.

## Skipping assignments

```php
$result = PolicyEngine::import($jsonString, new ImportOptions(skipAssignments: true));
```

Imports permissions, roles, and boundaries but ignores the `assignments` section. Useful when sharing role templates between environments where the users differ.

## Exporting the full state

```php
$json = PolicyEngine::export();
```

Returns a JSON string containing all permissions, roles, assignments, boundaries, and resource policies. Export always produces v2 format (`"version": "2.0"`), even when the data was originally imported from a v1 document.

## Exporting to a file

```php
PolicyEngine::exportToFile(storage_path('policies/backup.json'));
```

## Exporting a specific scope

```php
$json = PolicyEngine::export(scope: 'group::5');
```

Scoped export includes:
- **Permissions** — all registered permissions (not filtered)
- **Roles** — only roles with at least one assignment in the scope
- **Assignments** — only those matching the scope
- **Boundaries** — only the boundary for that exact scope
- **Resource policies** — excluded from scoped exports (only included in full exports)

```php
PolicyEngine::exportToFile(
    storage_path('policies/group-alpha.json'),
    scope: 'group::alpha',
);
```

## Importing and exporting via Artisan

```bash
# Import
php artisan policy-engine:import policies/community.json

# Dry run
php artisan policy-engine:import policies/community.json --dry-run

# Import roles/permissions only
php artisan policy-engine:import policies/community.json --skip-assignments

# Replace mode (destructive — requires --force)
php artisan policy-engine:import policies/community.json --replace --force

# Export to file
php artisan policy-engine:export --path=policies/backup.json

# Export scoped
php artisan policy-engine:export --scope="group::alpha" --path=policies/group-alpha.json

# Export to stdout
php artisan policy-engine:export

# Validate without importing
php artisan policy-engine:validate policies/community.json
```

See [Artisan commands](../cli/artisan-commands.md) for the full command reference.

## Workflow examples

| Workflow | How |
| --- | --- |
| Version control | Commit JSON documents to git alongside code |
| CI/CD deploy | `php artisan policy-engine:import` in your deploy pipeline |
| Environment sync | Export from staging, import to production |
| Tenant onboarding | Per-plan JSON templates applied on org creation |
| Role sharing | Share a role document as a gist or in docs |
| Auditing | Diff two exported documents to see what changed |
| Approval flow | Custom `DocumentImporter` that queues for admin review |

## Importing v1 documents

The importer accepts v1-format documents without any changes. V1 roles (array of objects) and boundaries (array of objects) are normalized internally during parsing.

```json
{
    "version": "1.0",
    "roles": [
        {"id": "editor", "name": "Editor", "permissions": ["posts.create"]}
    ],
    "boundaries": [
        {"scope": "org::acme", "max_permissions": ["posts.*"]}
    ]
}
```

```php
$result = PolicyEngine::import($v1JsonString);
```

Missing v2 sections like `resource_policies` default to empty. When you re-export after importing a v1 document, the output is v2 format.

> You do not need to migrate existing v1 documents. Keep them as-is and let the parser handle the conversion.
