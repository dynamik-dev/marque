# Scoping Sanctum Tokens

When your app uses Laravel Sanctum for API authentication, Policy Engine automatically intersects token abilities with role-based permissions. A token can only exercise permissions that both the token and the user's roles allow.

## Creating a scoped token

```php
$token = $user->createToken('deploy-bot', abilities: [
    'posts.read',
    'posts.create',
]);
```

This token can only use `posts.read` and `posts.create`, even if the user's roles grant broader permissions.

### Scoping a token to a specific scope

```php
$token = $user->createToken('group-bot', abilities: [
    'posts.read:group::5',
    'posts.create:group::5',
]);
```

Append the scope string after a colon to limit the token to a specific scope.

## How token scoping works

When the evaluator processes a `canDo()` check, it detects whether the current request is authenticated via a Sanctum `PersonalAccessToken`. If so, it applies an additional gate after the normal role/boundary/deny evaluation:

1. Normal evaluation runs (assignments, roles, permissions, boundaries, deny rules)
2. If the normal evaluation returns `deny`, the result is `deny` (token doesn't override)
3. If the normal evaluation returns `allow`, the evaluator checks the token's `abilities` array
4. The token must have an ability that matches the required permission (using the same wildcard matcher)
5. If the token lacks the ability, the result is `deny`

The token narrows the user's permissions. It can never expand them.

## Creating a wildcard token

```php
$token = $user->createToken('full-access', abilities: ['*']);
```

The `*` ability matches any permission. This token can exercise all permissions the user's roles allow — equivalent to session-based authentication.

## Token scoping with session auth

When a user authenticates via session (not a token), token scoping is skipped entirely. The user's full role-based permissions apply. This means:

- Web routes with session auth are unaffected by token logic
- API routes with Sanctum token auth get the additional token ability check
- The same `canDo()` call works in both contexts — the evaluator detects the auth method automatically

## Behavior when Sanctum is not installed

If `laravel/sanctum` is not installed, token scoping is silently skipped. The evaluator only checks role-based permissions. No configuration needed to opt out.

> Token scoping is a final gate, not a replacement for roles. Design your token abilities to match the specific API actions the token should perform. Use specific permissions (`posts.read`, `posts.create`) rather than broad wildcards when possible.
