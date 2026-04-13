# Naming Permissions

Permissions in Marque are dot-notated strings. A consistent naming scheme keeps your authorization rules readable and makes wildcard matching predictable.

## Choosing a format

The recommended format is `resource.verb`, with an optional third segment for qualifiers.

```php
Marque::permissions([
    'posts.create',
    'posts.read',
    'posts.update',
    'posts.delete',
]);
```

When a permission needs finer granularity, add a qualifier as the third segment:

```php
Marque::permissions([
    'posts.update.own',
    'posts.update.any',
    'posts.delete.own',
    'posts.delete.any',
]);
```

The convention is `resource.verb.qualifier`. Resource first, then the action, then the scope of the action. This order matters for wildcards: `posts.*` grants all verbs on posts, which is the most common pattern you want to express.

## Understanding segments and the dot separator

Permissions are split on `.` into segments. The matcher uses these segments for wildcard resolution and exact matching.

```
posts.delete.own
  |     |     |
  |     |     └── qualifier segment
  |     └── verb segment
  └── resource segment
```

You can use up to 10 segments. Permissions with more than 10 segments are rejected by the matcher and will never match anything.

```php
// Valid — 3 segments
'teams.members.invite'

// Valid — 4 segments
'teams.members.invite.external'

// Invalid — 11 segments, will never match
'a.b.c.d.e.f.g.h.i.j.k'
```

In practice, two or three segments cover nearly every use case.

## Using wildcards

A `*` in any segment position matches one or more segments. Wildcards are used in role grants and deny rules, not when registering permissions.

```php
Marque::createRole('editor', 'Editor')
    ->grant(['posts.*', 'comments.*']);
```

### Matching all verbs on a resource

`posts.*` matches any permission that starts with `posts.`, regardless of how many segments follow.

```php
// posts.* matches all of these:
'posts.create'
'posts.delete'
'posts.delete.own'
'posts.update.any'
```

### Matching a verb across all resources

`*.read` matches any two-segment permission ending in `read`.

```php
// *.read matches:
'posts.read'
'comments.read'
'users.read'

// *.read does NOT match (3 segments):
'posts.read.own'
```

### Matching everything

`*.*` matches any permission with two or more segments. A single `*` matches any permission with one or more segments.

```php
Marque::createRole('admin', 'Admin', system: true)
    ->grant(['*.*']);
```

### Matching qualified verbs

`posts.delete.*` matches permissions that start with `posts.delete.` and have at least one more segment. It does not match `posts.delete` alone.

```php
// posts.delete.* matches:
'posts.delete.own'
'posts.delete.any'

// posts.delete.* does NOT match:
'posts.delete'
```

### Matching across positions

A `*` can appear in any segment position, including the middle of a pattern.

```php
// resources.*.admin matches:
'resources.billing.admin'
'resources.users.admin'

// *.*.own matches:
'posts.delete.own'
'comments.update.own'

// *.*.own does NOT match:
'posts.delete'
'posts.delete.any'
```

> Wildcards are greedy. A `*` consumes one or more segments, so `a.*.c` matches both `a.b.c` and `a.b.d.c`. This is by design, but it means broad patterns can match more than you expect. Prefer specific grants when possible.

## Prefixing deny rules

The `!` prefix marks a permission as denied. It is not part of the permission name -- it is a modifier that the evaluator interprets.

```php
Marque::createRole('moderator', 'Moderator')
    ->grant(['posts.*'])
    ->deny(['posts.delete.any']);
```

The `deny()` method adds the `!` prefix for you. You can also pass it directly to `grant()`:

```php
->grant(['posts.*', '!posts.delete.any'])
```

Both forms are equivalent. See [using deny rules](../authorization/using-deny-rules.md) for how deny resolution works.

> You cannot register a permission with a `!` prefix. The store rejects IDs starting with `!`, whitespace, or colons. The `!` prefix only appears in role permission grants.

## Knowing which characters are valid

Permission IDs accept alphanumeric characters, dots, hyphens, underscores, and asterisks (for wildcards).

| Allowed          | Examples                                      |
| ---------------- | --------------------------------------------- |
| Letters          | `posts.create`, `Teams.Read`                  |
| Numbers          | `v2.posts.create`, `feature-123.enable`       |
| Dots             | `posts.create` (segment separator)            |
| Hyphens          | `audit-log.read`                              |
| Underscores      | `audit_log.read`                              |
| Asterisks        | `posts.*` (wildcard, in grants only)          |

| Rejected         | Reason                                        |
| ---------------- | --------------------------------------------- |
| Spaces           | `post create` -- throws `InvalidArgumentException` |
| Colons           | `posts:create` -- colons are reserved for scope suffixes |
| `!` prefix       | `!posts.delete` -- rejected by the store; use `deny()` on a role |
| Empty string     | Throws `InvalidArgumentException`             |
| Over 255 chars   | Throws `InvalidArgumentException`             |

> Stick to lowercase. While the store accepts mixed case, permission matching is case-sensitive. `Posts.Create` and `posts.create` are two different permissions.

## Following common patterns

### CRUD permissions

```php
'posts.create'
'posts.read'
'posts.update'
'posts.delete'
```

### Ownership qualifiers

```php
'posts.update.own'    // User can update their own posts
'posts.update.any'    // User can update anyone's posts
'posts.delete.own'
'posts.delete.any'
```

### Administrative actions

```php
'users.impersonate'
'users.ban'
'settings.manage'
'audit-log.export'
```

### Nested resources

```php
'teams.members.invite'
'teams.members.remove'
'projects.tasks.assign'
```

### Feature flags

```php
'beta.dashboard-v2.access'
'beta.api-v3.access'
```

## Avoiding common anti-patterns

### Using role names as permissions

```php
// Bad — these describe who, not what
'admin'
'moderator'
'super-user'

// Good — these describe the action
'users.manage'
'posts.moderate'
'settings.update'
```

Roles and permissions serve different purposes. A role is a named collection of permissions. A permission is a specific action on a specific resource.

### Overly generic names

```php
// Bad — too vague to be useful
'manage'
'access'
'admin'

// Good — specific resource and verb
'users.manage'
'dashboard.access'
'settings.admin'
```

### Mixing naming conventions

```php
// Bad — inconsistent style
'posts.create'
'create_comment'
'deleteUser'
'MANAGE_SETTINGS'

// Good — consistent resource.verb format
'posts.create'
'comments.create'
'users.delete'
'settings.manage'
```

Pick a style and stick with it. The `resource.verb` convention aligns with how wildcards work and how the rest of the Marque documentation structures examples.

### Encoding scope in the permission name

```php
// Bad — scope belongs in the assignment, not the permission
'group-5.posts.create'
'team-admin.manage'

// Good — use scoped assignments instead
$user->assign('editor', scope: $group);
```

Scopes are a first-class concept in Marque. See [scoping permissions](../authorization/scoping-permissions.md) for how to assign roles within a specific scope.

Next: [seeding permissions and roles](seeding-permissions-and-roles.md).
