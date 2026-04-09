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

## How token scoping works

Token scoping is handled by the `SanctumPolicyResolver` in the resolver chain. When the current request is authenticated via a Sanctum `PersonalAccessToken`, the resolver emits Deny statements for any registered permission not covered by the token's abilities. The evaluator then merges these with statements from other resolvers.

The effective behavior is:

1. Other resolvers produce Allow/Deny statements from roles, boundaries, and resource policies
2. The `SanctumPolicyResolver` checks the token's `abilities` array
3. Any permission not matched by a token ability gets a Deny statement
4. The evaluator applies deny-wins logic across all statements

The token narrows the user's permissions. It can never expand them.

> Token abilities are matched against the permission string only, not the scope. A token with `posts.create` allows `posts.create` in any scope where the user's roles grant it. Scope restrictions are handled by role assignments and boundaries, not by token abilities.

## Creating a wildcard token

```php
$token = $user->createToken('full-access', abilities: ['*']);
```

The `*` ability matches any permission. This token can exercise all permissions the user's roles allow — equivalent to session-based authentication.

## Token scoping with session auth

When a user authenticates via session (not a token), token scoping is skipped entirely. The user's full role-based permissions apply. This means:

- Web routes with session auth are unaffected by token logic
- API routes with Sanctum token auth get the additional token ability check
- The same permission check works in both contexts — the evaluator detects the auth method automatically

## Behavior when Sanctum is not installed

If `laravel/sanctum` is not installed, the `SanctumPolicyResolver` returns an empty statement collection and has no effect on evaluation. No configuration needed to opt out. You can also remove it from the `resolvers` config array entirely.

> Token scoping is a final gate, not a replacement for roles. Prefer specific abilities (`posts.read`, `posts.create`) over broad wildcards.
