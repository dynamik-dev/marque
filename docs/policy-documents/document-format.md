# Understanding the Document Format

Policy documents are portable JSON files that describe permissions, roles, assignments, and boundaries. Use them to version-control your authorization config, sync between environments, or share role templates.

## Document structure

```json
{
    "version": "1.0",
    "permissions": [
        "posts.read",
        "posts.create",
        "posts.update.own",
        "posts.update.any",
        "posts.delete.own",
        "posts.delete.any"
    ],
    "roles": [
        {
            "id": "editor",
            "name": "Editor",
            "permissions": [
                "posts.read",
                "posts.create",
                "posts.update.any",
                "!posts.delete.any"
            ]
        },
        {
            "id": "admin",
            "name": "Admin",
            "system": true,
            "permissions": ["*.*"]
        }
    ],
    "assignments": [
        {
            "subject": "user::42",
            "role": "editor",
            "scope": "group::5"
        },
        {
            "subject": "user::7",
            "role": "admin"
        }
    ],
    "boundaries": [
        {
            "scope": "org::acme",
            "max_permissions": ["posts.*", "comments.*"]
        }
    ]
}
```

## Section by section

### `version`

Required. Currently `"1.0"`.

### `permissions`

An array of permission ID strings. These are registered in the `permissions` table when imported.

```json
"permissions": ["posts.read", "posts.create", "members.invite"]
```

### `roles`

An array of role objects. Each role has an `id`, `name`, `permissions` array, and an optional `system` flag.

```json
"roles": [
    {
        "id": "moderator",
        "name": "Moderator",
        "permissions": ["posts.*", "comments.*", "!members.remove"]
    }
]
```

Permissions within a role can include deny rules (prefixed with `!`). The `system` key defaults to `false` when omitted.

### `assignments`

An array of assignment objects linking subjects to roles, optionally within a scope.

```json
"assignments": [
    {
        "subject": "user::42",
        "role": "editor",
        "scope": "group::5"
    }
]
```

The `subject` uses the `type::id` format. The `scope` key is optional — omit it for a global assignment.

### `boundaries`

An array of boundary objects defining permission ceilings per scope.

```json
"boundaries": [
    {
        "scope": "org::acme",
        "max_permissions": ["posts.*", "comments.*"]
    }
]
```

## Every section is optional

A document containing only `roles` is valid — useful for sharing role templates without touching assignments. A document with only `permissions` is valid for registering new permissions. Mix and match sections based on what you need.

```json
{
    "version": "1.0",
    "roles": [
        {
            "id": "triage",
            "name": "Triage",
            "permissions": ["posts.read", "posts.update.any", "!posts.delete.any"]
        }
    ]
}
```

## Validating a document

Use the Artisan command to check a document before importing:

```bash
php artisan policy-engine:validate policies/community.json
```

Or validate programmatically:

```php
use DynamikDev\PolicyEngine\Contracts\DocumentParser;

$result = app(DocumentParser::class)->validate($jsonString);

$result->valid;   // bool
$result->errors;  // string[] — empty when valid
```

Validation checks: `version` is present, `permissions` are strings, roles have `id`/`name`/`permissions`, assignments have `subject`/`role`, boundaries have `scope`/`max_permissions`.

## Serialization format

When you export a document, the JSON is pretty-printed with unescaped slashes for readability:

```php
use DynamikDev\PolicyEngine\Contracts\DocumentParser;

$json = app(DocumentParser::class)->serialize($document);
```

Uses `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.
