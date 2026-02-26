# Policy Engine Package - Agent Memory

## Package Identity
- **Name:** `dynamik-dev/laravel-policy-engine`
- **Type:** Laravel library package (NOT an application)
- **Namespace:** `DynamikDev\PolicyEngine\`
- **PHP:** ^8.4, **Laravel:** ^11.0|^12.0

## Directory Structure
- `src/` - Main source (PSR-4: `DynamikDev\PolicyEngine\`)
- `src/Contracts/`, `src/Stores/`, `src/Models/`, `src/Concerns/`, `src/Events/`
- `src/Facades/`, `src/Middleware/`, `src/Commands/`, `src/DTOs/`, `src/Documents/`
- `config/policy-engine.php` - Package config
- `tests/` - PSR-4: `DynamikDev\PolicyEngine\Tests\`
- `tests/Feature/` - Feature tests (Pest)

## Testing Setup
- **Pest 3** with `pestphp/pest-plugin-laravel`
- **Orchestra Testbench 9/10** for Laravel package testing context
- Base `tests/TestCase.php` extends `Orchestra\Testbench\TestCase`
- `tests/Pest.php` binds `TestCase` for Feature tests
- **Pint** added as dev dependency for formatting

## Database Schema (5 tables)
- `permissions` - string PK (`id`), nullable `description`, timestamps
- `roles` - string PK (`id`), `name`, `is_system` bool, timestamps
- `role_permissions` - composite PK (`role_id`, `permission_id`), FK to roles cascade delete
- `assignments` - auto-increment PK, polymorphic `subject`, `role_id` FK, nullable `scope`, unique constraint on all four, scope index
- `boundaries` - auto-increment PK, unique `scope`, JSON `max_permissions`, timestamps
- Migrations in `database/migrations/` using anonymous classes, no date prefix
- Service provider loads migrations via `loadMigrationsFrom()` and publishes via `publishesMigrations()` with tag `policy-engine-migrations`

## Key Files
- `/src/PolicyEngineServiceProvider.php` - Merges config, loads migrations, publishes config (`policy-engine-config`) and migrations (`policy-engine-migrations`)
- `/config/policy-engine.php` - cache, protect_system_roles, log_denials, explain, document_format
- `/phpunit.xml` - Feature test suite only, source coverage on `src/`

## Evaluator
- `src/Evaluators/DefaultEvaluator.php` - Implements `Contracts\Evaluator` with `can()`, `explain()`, `effectivePermissions()`
- Dependencies: `AssignmentStore`, `RoleStore`, `BoundaryStore`, `Matcher` (all via constructor injection)
- Evaluation order: gather assignments -> collect permissions -> boundary check -> deny-wins -> allow check -> default deny
- Permission format supports scope suffix: `permission:scope` (e.g., `posts.create:group::5`)
- Global (unscoped) assignments always apply, even when evaluating scoped permissions
- `explain()` guarded by `config('policy-engine.explain')` -- throws `RuntimeException` if disabled
- Denial logging via `AuthorizationDenied` event dispatch when `config('policy-engine.log_denials')` is true
- `effectivePermissions()` deduplicates and filters denied permissions from the allow set

## Traits (DX Layer)
- `src/Concerns/HasPermissions.php` - Delegates to Evaluator, AssignmentStore, RoleStore, ScopeResolver via `app()` container resolution
- Methods: `canDo()`, `cannotDo()`, `assign()`, `revoke()`, `assignments()`, `assignmentsFor()`, `effectivePermissions()`, `roles()`, `rolesFor()`, `explain()`
- Builds scoped permission strings as `permission:scope` for the Evaluator's `can()`/`explain()` methods
- Requires the using class to be an Eloquent Model (uses `getMorphClass()` and `getKey()`)

## Service Provider Bindings
- As of US-015, service provider has NO contract bindings yet (US-029 will add them)
- Tests must manually bind contracts via `app()->instance()` when testing traits/code that resolves from container

## Primitives Facade (DX Layer)
- `src/PrimitivesManager.php` - Orchestrates PermissionStore, RoleStore, BoundaryStore, DocumentParser, DocumentImporter, DocumentExporter
- `src/Support/RoleBuilder.php` - Fluent builder returned by `PrimitivesManager::role()` with `grant()`, `ungrant()`, `remove()`
- `src/Facades/Primitives.php` - Facade accessor resolves `PrimitivesManager::class` from container
- PrimitivesManager must be bound in container manually until US-029 adds service provider bindings
- DocumentParser, DocumentImporter, DocumentExporter implementations don't exist yet (US-020/021/022); tests use anonymous class mocks

## Matchers
- `src/Matchers/WildcardMatcher.php` - `*` matches one or more segments; unscoped grants cover any scope; scoped grants require exact scope match

## Test Patterns for Container-Dependent Code
- When testing traits that resolve from container, bind all contracts in `beforeEach()` via `app()->instance()`
- For test models, create a simple class in the test file with `Schema::create()` in `beforeEach()` and `Schema::dropIfExists()` in `afterEach()`
- Test model class declared outside Pest closures (global scope in test file)

## Middleware (DX Layer)
- `src/Middleware/CanDoMiddleware.php` - Checks `$user->canDo($permission, $scope)`, aborts 401 if unauthenticated, 403 if denied
- `src/Middleware/RoleMiddleware.php` - Checks `AssignmentStore` for matching role_id, aborts 401/403
- Both accept `?string $scopeParam` that resolves from `$request->route($scopeParam)` through `ScopeResolver`
- Registered in service provider `boot()` via `Router::aliasMiddleware()` as `can_do` and `role`
- Route model binding tests require `SubstituteBindings` middleware in the stack (runs before our middleware)
- Middleware test models must extend `Illuminate\Foundation\Auth\User` (Authenticatable) for `actingAs()` to work

## Pint Style Notes
- Uses `concat_space` fixer (no spaces around `.` concatenation operator)
- Pest does NOT support `--no-interaction` flag (use plain `vendor/bin/pest`)
