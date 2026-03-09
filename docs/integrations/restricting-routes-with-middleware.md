# Restricting Routes with Middleware

The package ships two middleware aliases — `can_do` for permission checks and `role` for role membership checks. Both work with route model binding and scopes. Laravel's built-in `can` middleware also works for dot-notated permissions via the Gate hook.

## Using Laravel's built-in can middleware

```php
Route::middleware('can:posts.create')
    ->post('/posts', [PostController::class, 'store']);
```

The Gate hook intercepts `posts.create` (a dot-notated ability) and routes it through Policy Engine. This is the simplest option when you don't need scope-from-route-parameter resolution.

> Laravel's `can` middleware does not support the scope-from-route-parameter syntax that `can_do` provides. Use `can_do` when you need to resolve a scope from a route parameter.

## Requiring a permission on a route

```php
Route::middleware('can_do:posts.create')
    ->post('/posts', [PostController::class, 'store']);
```

The authenticated user must hold the `posts.create` permission. Returns 403 if denied, 401 if unauthenticated.

## Requiring a scoped permission

```php
Route::middleware('can_do:posts.create,group')
    ->post('/groups/{group}/posts', [PostController::class, 'store']);
```

The second parameter after the colon is the name of a route parameter. The middleware resolves `{group}` from the route (via route model binding or as a raw string) and passes it to the scope resolver.

### How scope resolution works in middleware

If the route uses model binding and the bound model has a `toScope()` method (i.e., uses the `Scopeable` trait), the middleware resolves it as a scope. If the route parameter is a plain string, it passes through as-is.

```php
// Model binding — Group uses Scopeable, resolves to "group::5"
Route::middleware(['SubstituteBindings', 'can_do:posts.create,group'])
    ->post('/groups/{group}/posts', [PostController::class, 'store']);

// Raw string — uses the string "group::5" directly
Route::middleware('can_do:posts.create,scope')
    ->post('/test/{scope}', [PostController::class, 'store']);
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

The user must hold the `editor` role within the resolved scope. A global `editor` assignment does not satisfy a scoped role check — the role must be assigned in the specific scope.

## Combining middleware

```php
Route::middleware(['can_do:posts.create,group', 'role:member,group'])
    ->post('/groups/{group}/posts', [PostController::class, 'store']);
```

Both checks must pass. The user needs the `posts.create` permission and the `member` role within the group.

## Response codes

| Situation | Status |
| --- | --- |
| No authenticated user | 401 |
| Authenticated but permission/role denied | 403 |
| Check passes | Request continues |
