# Restricting Routes with Middleware

Policy Engine hooks into Laravel's Gate, so the built-in `can` middleware works out of the box for permission checks. The package also ships a `role` middleware for role membership checks. Both support route model binding and scopes.

## Requiring a permission on a route

```php
Route::middleware('can:posts.create')
    ->post('/posts', [PostController::class, 'store']);
```

The Gate hook intercepts `posts.create` (a dot-notated ability) and routes it through Policy Engine. Returns 403 if denied, 401 if unauthenticated.

If the user model does not have the `HasPermissions` trait, the Gate hook passes through and Laravel denies by default — fail-closed behavior with no custom middleware required.

## Requiring a scoped permission

```php
Route::middleware('can:posts.create,group')
    ->post('/groups/{group}/posts', [PostController::class, 'store']);
```

The second parameter is the name of a route parameter. Laravel's `can` middleware resolves `{group}` from the route (via route model binding) and passes it to the Gate hook, which feeds it to the scope resolver.

### How scope resolution works

If the route uses model binding and the bound model has a `toScope()` method (i.e., uses the `Scopeable` trait), the scope resolver converts it to a `type::id` string. The Gate hook passes the resolved scope to `canDo()` automatically.

```php
// Model binding — Group uses Scopeable, resolves to "group::5"
Route::middleware([SubstituteBindings::class, 'can:posts.create,group'])
    ->post('/groups/{group}/posts', [PostController::class, 'store']);
```

## Requiring a role on a route

```php
Route::middleware('role:admin')
    ->prefix('/admin')
    ->group(function () {
        // admin-only routes
    });
```

The authenticated user must have the `admin` role assigned (globally).

## Requiring a scoped role

```php
Route::middleware('role:editor,group')
    ->prefix('/groups/{group}/manage')
    ->group(function () {
        // group editor routes
    });
```

The user must hold the `editor` role within the resolved scope or globally. Both global and scoped assignments satisfy a scoped role check — a user with a global `editor` role passes `role:editor,group` for any group.

## Combining middleware

```php
Route::middleware(['can:posts.create,group', 'role:member,group'])
    ->post('/groups/{group}/posts', [PostController::class, 'store']);
```

Both checks must pass. The user needs the `posts.create` permission and the `member` role within the group.

## Response codes

| Situation | Status |
| --- | --- |
| No authenticated user | 401 |
| Authenticated but permission/role denied | 403 |
| Check passes | Request continues |
