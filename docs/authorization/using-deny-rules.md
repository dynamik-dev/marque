# Using Deny Rules

Deny rules let you explicitly block specific permissions within a role, even when a broader wildcard would otherwise allow them. A deny rule always wins over an allow rule.

## Adding a deny rule to a role

```php
use DynamikDev\PolicyEngine\Facades\Primitives;

Primitives::role('moderator', 'Moderator')
    ->grant([
        'posts.*',
        'comments.*',
        '!members.remove',
    ]);
```

The `!` prefix marks `members.remove` as denied. This moderator can do anything with posts and comments, but cannot remove members — even if another role were to grant `members.*`.

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
Primitives::role('content-creator', 'Content Creator')
    ->grant(['posts.*']);

Primitives::role('restricted', 'Restricted')
    ->grant(['!posts.delete.any']);

$user->assign('content-creator');
$user->assign('restricted');
```

The user has `posts.*` from one role and `!posts.delete.any` from another. The deny rule from the `restricted` role blocks `posts.delete.any`, even though `content-creator` would allow it.

```php
$user->can('posts.create');      // true
$user->can('posts.delete.any');  // false — denied
```

## Deny rules support wildcards

```php
Primitives::role('read-only', 'Read Only')
    ->grant([
        'posts.read',
        'comments.read',
        '!*.create',
        '!*.update.*',
        '!*.delete.*',
    ]);
```

The wildcard `!*.create` denies any creation permission across all resources.

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

> Deny rules are a powerful tool for building restrictive roles, but they make permission logic harder to reason about. Prefer specific grants (list exactly what a role can do) over broad grants with deny carve-outs. Reserve deny rules for cases where you need to override a wildcard.
