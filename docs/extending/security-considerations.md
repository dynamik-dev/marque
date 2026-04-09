# Security Considerations

## Protecting the explain() trace

The `explain()` method returns an `EvaluationTrace` with the full authorization decision path: which assignments were found, which roles were checked, which permissions matched, boundary status, and the final result.

This is valuable for debugging but dangerous in production. If exposed via an API endpoint, it reveals:

- Every role assigned to the subject
- All permissions granted through those roles
- Boundary ceilings for the evaluated scope
- Whether Sanctum token scoping was a factor

### Hardening explain() for production

Keep `marque.explain` set to `false` (the default) in production:

```php
// config/marque.php
'explain' => env('MARQUE_EXPLAIN', false),
```

When disabled, calling `explain()` throws a `RuntimeException` immediately.

If you need runtime explain access in production, gate it behind an admin-only policy or middleware:

```php
Route::get('/debug/permissions/{user}/{permission}', function (User $user, string $permission) {
    $this->authorize('viewAny', Permission::class);

    return response()->json(
        $user->explain($permission)->toArray()
    );
})->middleware('auth:sanctum');
```

The `marque:explain` Artisan command reads the config at runtime. Restrict Artisan access in production using environment guards or deployment tooling.

See [the `explain` config reference](../reference/configuration.md#explain) for details on the config option.

## Understanding the removeAll() system role bypass

The `removeAll()` methods on `RoleStore`, `AssignmentStore`, `BoundaryStore`, and `PermissionStore` delete all records without checking system role protection.

```php
use DynamikDev\Marque\Contracts\RoleStore;

// This deletes ALL roles, including system-locked ones
app(RoleStore::class)->removeAll();
```

This is by design. `removeAll()` powers the replace-mode import pipeline, which needs to clear all data before importing a fresh policy document:

```bash
php artisan marque:import policies/fresh.json --replace --force
```

### Guarding removeAll() in application code

If your application exposes store contracts directly (through an admin API or service class), be aware that `removeAll()` bypasses `protect_system_roles`. Add your own authorization checks before calling it:

```php
class PolicyAdminController extends Controller
{
    public function reset(RoleStore $roleStore)
    {
        $this->authorize('admin.policy.reset');

        $roleStore->removeAll();
    }
}
```

For standard usage through the import command or `MarqueManager::import()`, this is safe — the command handles confirmation prompts and the `--force` flag.

> The `remove()` method on `RoleStore` does respect `protect_system_roles`. Only `removeAll()` bypasses it.

## Understanding the HasPermissions service location pattern

The `HasPermissions` trait resolves dependencies through `app()` calls (~15 service locations across the trait). This is a conscious tradeoff, not technical debt.

### Why service location instead of constructor injection

Laravel traits cannot use constructor injection. The alternative would be passing dependencies explicitly to every method call, which degrades the DX that makes the trait useful:

```php
// Current (service location)
$user->canDo('posts.create');

// Alternative (explicit deps) — not how this works
$user->canDo('posts.create', $evaluator, $scopeResolver);
```

The service container is the correct abstraction here. The trait delegates to contracts, the container resolves implementations, and the package remains fully swappable.

### Testing models that use HasPermissions

In tests, the trait works automatically because the service provider binds all contracts. To swap an implementation for a test, rebind in the container:

```php
use DynamikDev\Marque\Contracts\Evaluator;

$this->app->instance(Evaluator::class, $mockEvaluator);

$user->canDo('posts.create'); // uses your mock
```

This is the standard Laravel testing pattern for service-located dependencies. See [Swapping implementations](swapping-implementations.md) for more on replacing default implementations.
