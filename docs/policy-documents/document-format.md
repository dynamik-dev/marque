# Understanding the Document Format

Policy documents are portable JSON files that describe permissions, roles, assignments, boundaries, and resource policies. Use them to version-control your authorization config, sync between environments, or share role templates.

## Document structure

```json
{
    "version": "2.0",
    "permissions": [
        "posts.read",
        "posts.create",
        "posts.update.own",
        "posts.update.any",
        "posts.delete.own",
        "posts.delete.any"
    ],
    "roles": {
        "editor": {
            "permissions": [
                "posts.read",
                "posts.create",
                "posts.update.any",
                "!posts.delete.any"
            ]
        },
        "admin": {
            "permissions": ["*.*"],
            "system": true
        }
    },
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
    "boundaries": {
        "org::acme": {
            "max_permissions": ["posts.*", "comments.*"]
        }
    },
    "resource_policies": [
        {
            "resource_type": "post",
            "resource_id": null,
            "effect": "Allow",
            "action": "posts.read",
            "principal_pattern": "*",
            "conditions": []
        }
    ]
}
```

## Section by section

### `version`

Required. Export always produces `"2.0"`. The parser still accepts `"1.0"` documents for backward compatibility.

### `permissions`

An array of permission ID strings. These are registered in the `permissions` table when imported.

```json
"permissions": ["posts.read", "posts.create", "members.invite"]
```

### `roles`

An object keyed by role ID. Each role has a `permissions` array and optional `system` and `conditions` keys.

```json
"roles": {
    "moderator": {
        "permissions": ["posts.*", "comments.*", "!members.remove"]
    },
    "admin": {
        "permissions": ["*.*"],
        "system": true
    }
}
```

Permissions within a role can include deny rules (prefixed with `!`). The `system` key defaults to `false` when omitted.

### Adding conditions to role permissions

Attach conditions to specific permissions within a role. The `conditions` key maps a permission string to an array of condition objects.

```json
"roles": {
    "editor": {
        "permissions": ["posts.create", "posts.read", "posts.update"],
        "conditions": {
            "posts.update": [
                {
                    "type": "attribute_equals",
                    "parameters": {
                        "subject_key": "department_id",
                        "resource_key": "department_id"
                    }
                }
            ]
        }
    }
}
```

Each condition object has a `type` string and a `parameters` object. In this example, `posts.update` is only allowed when the subject's `department_id` matches the resource's `department_id`. The `conditions` key is optional — omit it when a role has no conditional permissions.

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

An object keyed by scope, defining permission ceilings per scope.

```json
"boundaries": {
    "org::acme": {
        "max_permissions": ["posts.*", "comments.*"]
    },
    "free-tier": {
        "max_permissions": ["posts.read"]
    }
}
```

### `resource_policies`

An array of resource policy objects that define access rules attached to resource types or specific resource instances.

```json
"resource_policies": [
    {
        "resource_type": "post",
        "resource_id": null,
        "effect": "Allow",
        "action": "posts.read",
        "principal_pattern": "*",
        "conditions": []
    }
]
```

| Field               | Required | Description                                                   |
| -------------------- | -------- | ------------------------------------------------------------- |
| `resource_type`      | Yes      | The resource type this policy applies to (e.g., `"post"`)     |
| `resource_id`        | No       | A specific resource ID, or `null` for all of the type         |
| `effect`             | Yes      | `"Allow"` or `"Deny"`                                         |
| `action`             | Yes      | The permission string this policy governs                     |
| `principal_pattern`  | No       | A pattern matching subjects, or `"*"` for all                 |
| `conditions`         | No       | An array of condition objects to evaluate at runtime           |

## Every section is optional

A document containing only `roles` is valid — useful for sharing role templates without touching assignments. A document with only `permissions` is valid for registering new permissions. Mix and match sections based on what you need.

```json
{
    "version": "2.0",
    "roles": {
        "triage": {
            "permissions": ["posts.read", "posts.update.any", "!posts.delete.any"]
        }
    }
}
```

## Importing v1 documents

The parser auto-detects v1 format and normalizes it internally. A v1 document with array-of-objects roles and boundaries imports without changes.

```json
{
    "version": "1.0",
    "roles": [
        {
            "id": "triage",
            "name": "Triage",
            "permissions": ["posts.read", "posts.update.any", "!posts.delete.any"]
        }
    ],
    "boundaries": [
        {
            "scope": "org::acme",
            "max_permissions": ["posts.*"]
        }
    ]
}
```

Missing fields default to empty arrays. A v1 document without `resource_policies` imports the other sections normally and produces no resource policies.

> Export always produces v2 format (`"version": "2.0"`), even when the original data was imported from a v1 document.

## Validating a document

Use the Artisan command to check a document before importing:

```bash
php artisan marque:validate policies/community.json
```

Or validate programmatically:

```php
use DynamikDev\Marque\Contracts\DocumentParser;

$result = app(DocumentParser::class)->validate($jsonString);

$result->valid;   // bool
$result->errors;  // string[] — empty when valid
```

Validation checks: `version` is present, `permissions` are strings, roles have `permissions` arrays, assignments have `subject`/`role`, boundaries have `max_permissions`, and resource policies have `resource_type`/`effect`/`action`. Both v1 and v2 formats are accepted.

## Serialization format

When you export a document, the JSON is pretty-printed with unescaped slashes for readability:

```php
use DynamikDev\Marque\Contracts\DocumentParser;

$json = app(DocumentParser::class)->serialize($document);
```

Uses `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`. Serialization always outputs v2 format regardless of the input version.
