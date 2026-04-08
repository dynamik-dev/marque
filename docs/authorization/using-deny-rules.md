# Using Deny Rules

Deny rules let you explicitly block specific permissions within a role, even when a broader wildcard would otherwise allow them. A deny rule always wins over an allow rule.

## Adding a deny rule to a role

```php
use DynamikDev\PolicyEngine\Facades\PolicyEngine;

PolicyEngine::role('moderator', 'Moderator')
    ->grant(['posts.*', 'comments.*'])
    ->deny(['members.remove']);
```

This moderator can do anything with posts and comments, but cannot remove members — even if another role were to grant `members.*`.

Under the hood, `deny()` stores permissions with a `!` prefix (`!members.remove`). You can also pass deny rules directly to `grant()` with the `!` prefix if you prefer — `deny()` is syntactic sugar.

## How deny resolution works

The evaluator collects all permissions from all of a user's assigned roles, then splits them into two lists:

1. **Allows** — permissions without a `!` prefix
2. **Denies** — permissions with the `!` prefix (the prefix is stripped before matching)

A permission is granted only if:
- At least one allow rule matches the required permission
- No deny rule matches the required permission

Deny wins. If both `posts.*` and `!posts.delete` are present, `posts.delete` is denied.

## Deny rules work across roles

```php
PolicyEngine::role('content-creator', 'Content Creator')
    ->grant(['posts.*']);

PolicyEngine::role('restricted', 'Restricted')
    ->deny(['posts.delete.any']);

$user->assign('content-creator');
$user->assign('restricted');
```

The user has `posts.*` from one role and a deny on `posts.delete.any` from another. The deny rule blocks `posts.delete.any`, even though `content-creator` would allow it.

```php
$user->can('posts.create');      // true
$user->can('posts.delete.any');  // false — denied
```

## Deny rules support wildcards

```php
PolicyEngine::role('read-only', 'Read Only')
    ->grant(['posts.read', 'comments.read'])
    ->deny(['*.create', '*.update.*', '*.delete.*']);
```

The wildcard `*.create` in `deny()` blocks any creation permission across all resources.

## Viewing effective permissions after deny rules

```php
$permissions = $user->effectivePermissions();
```

This returns only the permissions that survive deny filtering. Denied permissions are excluded from the result.

## Debugging denied permissions

```php
$trace = $user->explain('posts.delete.any', scope: $group);
```

The evaluation trace shows which deny rule matched and which role it came from. See [checking permissions](checking-permissions.md#debugging-a-permission-decision) for trace details.

> Prefer specific grants over broad grants with deny carve-outs. Deny rules make permission logic harder to reason about — reserve them for cases where you need to override a wildcard.
