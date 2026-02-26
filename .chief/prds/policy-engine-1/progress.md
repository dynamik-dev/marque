## Codebase Patterns
- Use `orchestra/testbench` + `pestphp/pest-plugin-laravel` for package testing
- Tests extend `DynamikDev\PolicyEngine\Tests\TestCase` which registers the service provider
- Config key is `policy-engine`, env prefix is `POLICY_ENGINE_`
- All PHP files use `declare(strict_types=1)`
- Package namespace: `DynamikDev\PolicyEngine\`
- Use anonymous migration classes (`return new class extends Migration`)
- Run `vendor/bin/pint --dirty` after modifying PHP files
- Use `vendor/bin/pest` for testing
- Use `casts()` method (not `$casts` property) for Eloquent model casts (PHP 8.4+ style)
- Use PHPDoc generics on relationship return types: `@return BelongsToMany<Permission, $this>`
- DTOs use `readonly class` with constructor promotion and sensible defaults
- Eloquent stores: use `firstOrCreate` + `wasRecentlyCreated` for idempotent register with event dispatch
- Event classes: pass Eloquent models (not IDs) as readonly constructor-promoted properties in `src/Events/`
- Feature tests for stores use `RefreshDatabase` trait and `Event::fake()` for event assertions
- SQLite does NOT enforce FK cascades by default — don't rely on cascade deletes in tests; explicitly clean up related records
- Use `config()->set('policy-engine.cache.store', 'array')` in cache-related tests for isolation
- Tests must manually bind contracts to implementations in `beforeEach()` until US-029 (service provider bindings) is complete
- For test models, define inline classes (e.g., `TestUser`) with traits directly in the test file + create in-memory migration tables

---

## 2026-02-26 - US-001
- What was implemented: Package scaffolding — composer.json, service provider, config, phpunit.xml, Pest setup, and full directory structure
- Files changed:
  - `composer.json` — package definition with all dependencies
  - `src/PolicyEngineServiceProvider.php` — skeleton service provider
  - `config/policy-engine.php` — configuration array
  - `phpunit.xml` — test configuration
  - `tests/Pest.php` — Pest configuration
  - `tests/TestCase.php` — base test case with testbench
  - `.gitignore` — standard ignores
  - `.gitkeep` files in all src/ subdirectories and tests/Feature/
- **Learnings for future iterations:**
  - Package testing requires `orchestra/testbench` and `pestphp/pest-plugin-laravel` as dev dependencies
  - The TestCase.php needs to register the service provider via `getPackageProviders()`
  - `vendor/bin/pest` runs cleanly with zero tests and no errors on PHP 8.5.2
  - The service provider merges config in `register()` and publishes it in `boot()`
---

## 2026-02-26 - US-002
- What was implemented: Database migrations for all 5 tables (permissions, roles, role_permissions, assignments, boundaries)
- Files changed:
  - `database/migrations/create_permissions_table.php` — string PK `id`, nullable `description`, timestamps
  - `database/migrations/create_roles_table.php` — string PK `id`, `name`, `is_system` boolean, timestamps
  - `database/migrations/create_role_permissions_table.php` — composite PK, foreign key to roles with cascade delete
  - `database/migrations/create_assignments_table.php` — auto-increment PK, morphs, role FK, nullable scope, unique constraint, scope index
  - `database/migrations/create_boundaries_table.php` — auto-increment PK, unique scope, JSON max_permissions, timestamps
  - `src/PolicyEngineServiceProvider.php` — added `loadMigrationsFrom()` and `publishesMigrations()`
- **Learnings for future iterations:**
  - Use `loadMigrationsFrom()` unconditionally in `boot()` so migrations are always discovered
  - Use `publishesMigrations()` (Laravel 11+) inside `runningInConsole()` block for user publishing
  - Migration filenames without date prefix (e.g., `create_permissions_table.php`) — `publishesMigrations()` auto-prepends timestamps when published
  - The `publishesMigrations()` method exists on `ServiceProvider` in Laravel 11+
---

## 2026-02-26 - US-003
- What was implemented: Eloquent models for all 5 database tables (Permission, Role, RolePermission, Assignment, Boundary)
- Files changed:
  - `src/Models/Permission.php` — string PK, non-incrementing, fillable: id, description
  - `src/Models/Role.php` — string PK, non-incrementing, is_system boolean cast, belongsToMany permissions via role_permissions pivot
  - `src/Models/RolePermission.php` — non-incrementing, no timestamps, belongsTo Role and Permission
  - `src/Models/Assignment.php` — auto-increment PK, morphTo subject, belongsTo Role
  - `src/Models/Boundary.php` — auto-increment PK, max_permissions cast to array
  - Deleted `src/Models/.gitkeep`
- **Learnings for future iterations:**
  - Use `casts()` method (not `$casts` property) for PHP 8.4+ style
  - RolePermission pivot table has no timestamps — set `$timestamps = false` on the model
  - Use named arguments on `belongsToMany()` for readability: `related:`, `table:`, `foreignPivotKey:`, `relatedPivotKey:`
  - PHPDoc generics on relationships: `@return BelongsToMany<Permission, $this>`
---

## 2026-02-26 - US-004
- What was implemented: All 10 contract interfaces defining the package's API surface
- Files changed:
  - `src/Contracts/PermissionStore.php` — register, remove, all, exists
  - `src/Contracts/RoleStore.php` — save, remove, find, all, permissionsFor
  - `src/Contracts/AssignmentStore.php` — assign, revoke, forSubject, forSubjectInScope, subjectsInScope
  - `src/Contracts/BoundaryStore.php` — set, remove, find
  - `src/Contracts/Evaluator.php` — can, explain, effectivePermissions
  - `src/Contracts/Matcher.php` — matches
  - `src/Contracts/ScopeResolver.php` — resolve
  - `src/Contracts/DocumentParser.php` — parse, serialize, validate
  - `src/Contracts/DocumentImporter.php` — import
  - `src/Contracts/DocumentExporter.php` — export
  - Deleted `src/Contracts/.gitkeep`
- **Learnings for future iterations:**
  - Contract interfaces can reference DTO types that don't exist yet — PHP resolves type hints lazily
  - Use PHPDoc `@return Collection<int, Model>` generics on Collection return types for IDE support
  - Keep interfaces minimal — no docblocks beyond method purpose and `@param`/`@return` generics
---

## 2026-02-26 - US-005
- What was implemented: All 5 DTO/value object classes (EvaluationTrace, PolicyDocument, ImportOptions, ImportResult, ValidationResult)
- Files changed:
  - `src/DTOs/EvaluationTrace.php` — readonly class: subject, required, result, assignments array, boundary, cacheHit
  - `src/DTOs/PolicyDocument.php` — readonly class: version (default '1.0'), permissions, roles, assignments, boundaries (all default empty arrays)
  - `src/DTOs/ImportOptions.php` — readonly class: validate (true), merge (true), dryRun (false), skipAssignments (false)
  - `src/DTOs/ImportResult.php` — readonly class: permissionsCreated, rolesCreated, rolesUpdated, assignmentsCreated, warnings
  - `src/DTOs/ValidationResult.php` — readonly class: valid bool, errors array (default empty)
  - Deleted `src/DTOs/.gitkeep`
- **Learnings for future iterations:**
  - PHP 8.4 `readonly class` makes all properties implicitly readonly — no need for property-level `readonly`
  - DTOs use constructor promotion with sensible defaults where the spec requires them
  - PHPDoc `@param` annotations with array shape types (e.g., `array{role: string, scope: ?string}`) help IDE support
  - The contracts already reference these DTOs via `use` statements — types resolve correctly now
---

## 2026-02-26 - US-007
- What was implemented: ModelScopeResolver — resolves null, string, and Scopeable Model scopes to canonical scope strings
- Files changed:
  - `src/Resolvers/ModelScopeResolver.php` — implements ScopeResolver contract: null→null, string→string, Model with toScope()→toScope() result, else throws InvalidArgumentException
  - `tests/Feature/ModelScopeResolverTest.php` — 8 Pest tests covering null, string, empty string, Model with toScope(), integer, array, stdClass, Model without toScope()
- **Learnings for future iterations:**
  - ModelScopeResolver is a pure class (no dependencies) — instantiate directly for testing
  - Use `method_exists($scope, 'toScope')` to check for Scopeable trait since the trait doesn't exist yet
  - Check `instanceof Model` AND `method_exists()` — a non-Model with toScope() should still be rejected
  - Use `get_debug_type()` in exception messages for better DX
  - The Scopeable trait (US-016) will add the `toScope()` method — the resolver is ready for it
---

## 2026-02-26 - US-006
- What was implemented: WildcardMatcher — segment-based wildcard permission matching with scope support
- Files changed:
  - `src/Matchers/WildcardMatcher.php` — implements Matcher contract with segment-by-segment matching, scope splitting, and wildcard expansion
  - `tests/Feature/WildcardMatcherTest.php` — 27 Pest tests covering exact match, wildcard verb/resource, full wildcard, single star, scope matching, deep verb matching, and edge cases
  - Deleted `src/Matchers/.gitkeep`
- **Learnings for future iterations:**
  - WildcardMatcher is a pure class (no dependencies) — can be instantiated directly without the container for testing
  - Permission format: `resource.verb[:scope]` — split on first `:` for scope, then `.` for segments
  - Unscoped grants cover any scope; scoped grants require exact scope match
  - `*` segment matches one or more segments (greedy) — `posts.delete.*` matches `posts.delete.own` but NOT `posts.delete`
  - Deny prefix (`!`) is NOT handled by matcher — that's the evaluator's responsibility
---

## 2026-02-26 - US-008
- What was implemented: EloquentPermissionStore — Eloquent-backed implementation of PermissionStore contract, plus PermissionCreated/PermissionDeleted event classes
- Files changed:
  - `src/Events/PermissionCreated.php` — readonly event class with `permissionId` property
  - `src/Events/PermissionDeleted.php` — readonly event class with `permissionId` property
  - `src/Stores/EloquentPermissionStore.php` — implements PermissionStore: register (idempotent), remove (with role_permissions cascade), all (with prefix filter), exists
  - `tests/Feature/EloquentPermissionStoreTest.php` — 13 Pest tests covering all store methods, idempotency, event dispatch, and cascade deletion
  - Deleted `src/Events/.gitkeep` and `src/Stores/.gitkeep`
- **Learnings for future iterations:**
  - Use `firstOrCreate` + `wasRecentlyCreated` to detect genuinely new records for event dispatch
  - Manually delete `role_permissions` rows before deleting a permission — the Permission model doesn't auto-cascade
  - Prefix filtering uses `$prefix . '.%'` with LIKE query for dot-notated permission namespacing
  - Feature tests for Eloquent stores need `RefreshDatabase` trait — pure unit testing won't work
  - `Event::fake()` must be called before the action under test for assertions to work
---

## 2026-02-26 - US-009
- What was implemented: EloquentRoleStore — Eloquent-backed implementation of RoleStore contract, plus RoleCreated/RoleUpdated/RoleDeleted event classes
- Files changed:
  - `src/Events/RoleCreated.php` — readonly event class with `roleId` property
  - `src/Events/RoleUpdated.php` — readonly event class with `roleId` property
  - `src/Events/RoleDeleted.php` — readonly event class with `roleId` property
  - `src/Stores/EloquentRoleStore.php` — implements RoleStore: save (create/update with permission sync), remove (system-role protection), find, all, permissionsFor
  - `tests/Feature/EloquentRoleStoreTest.php` — 15 Pest tests covering save (create, update, permission sync), remove (normal, system-protected, protection-disabled), find, all, permissionsFor
- **Learnings for future iterations:**
  - Use `updateOrCreate` + `wasRecentlyCreated` for create-or-update with event dispatch (RoleCreated vs RoleUpdated)
  - Permission sync: delete all existing RolePermission rows, then insert new ones — simpler than `sync()` since RolePermission is a manual model
  - System role protection: check `config('policy-engine.protect_system_roles')` before allowing deletion of `is_system` roles
  - Foreign key cascade on `role_permissions` and `assignments` tables handles cleanup when a role is deleted
  - `permissionsFor` uses `RolePermission::query()->where()->pluck()->all()` to return a plain array of string IDs
---

## 2026-02-26 - US-010
- What was implemented: EloquentAssignmentStore — Eloquent-backed implementation of AssignmentStore contract, plus AssignmentCreated/AssignmentRevoked event classes
- Files changed:
  - `src/Events/AssignmentCreated.php` — readonly event class with subjectType, subjectId, roleId, scope properties
  - `src/Events/AssignmentRevoked.php` — readonly event class with subjectType, subjectId, roleId, scope properties
  - `src/Stores/EloquentAssignmentStore.php` — implements AssignmentStore: assign (idempotent firstOrCreate), revoke (with delete count check), forSubject, forSubjectInScope, subjectsInScope (with optional roleId filter)
  - `tests/Feature/EloquentAssignmentStoreTest.php` — 11 Pest tests covering assign (new, idempotent, events), revoke (removal, events, no-op), forSubject, forSubjectInScope, subjectsInScope (with/without roleId)
- **Learnings for future iterations:**
  - Assignment events carry all four fields (subjectType, subjectId, roleId, scope) since assignments are identified by composite key
  - `firstOrCreate` with all four fields enforces uniqueness via DB constraint and returns `wasRecentlyCreated` for event dispatch
  - For revoke, use `->delete()` return value (int count) to determine whether to dispatch event
  - `when($roleId, fn ...)` is clean for optional query filters — used in `subjectsInScope`
  - Tests need to create Role records in beforeEach due to FK constraint on assignments table
---

## 2026-02-26 - US-011
- What was implemented: EloquentBoundaryStore — Eloquent-backed implementation of BoundaryStore contract
- Files changed:
  - `src/Stores/EloquentBoundaryStore.php` — implements BoundaryStore: set (updateOrCreate), remove (where+delete), find (where+first)
  - `tests/Feature/EloquentBoundaryStoreTest.php` — 6 Pest tests covering set (create, update), remove (existing, non-existing), find (exists, null)
- **Learnings for future iterations:**
  - EloquentBoundaryStore is the simplest store — no events, no FK cascading, no protection logic
  - `updateOrCreate` with `['scope' => $scope]` as match key and `['max_permissions' => $maxPermissions]` as values handles both create and update in one call
  - No need for event dispatch in boundary store — boundaries are simple config-style records
  - Boundary model's `max_permissions` cast to `array` handles JSON encode/decode automatically
---

## 2026-02-26 - US-012
- What was implemented: Updated all 7 existing event classes to match spec signatures, created 2 new event classes (AuthorizationDenied, DocumentImported), updated stores and tests
- Files changed:
  - `src/Events/PermissionCreated.php` — renamed `$permissionId` to `$permission`
  - `src/Events/PermissionDeleted.php` — renamed `$permissionId` to `$permission`
  - `src/Events/RoleCreated.php` — changed from `string $roleId` to `Role $role`
  - `src/Events/RoleUpdated.php` — changed from `string $roleId` to `Role $role, array $changes`
  - `src/Events/RoleDeleted.php` — changed from `string $roleId` to `Role $role`
  - `src/Events/AssignmentCreated.php` — consolidated four fields into `Assignment $assignment`
  - `src/Events/AssignmentRevoked.php` — consolidated four fields into `Assignment $assignment`
  - `src/Events/AuthorizationDenied.php` — new event: `string $subject, string $permission, ?string $scope`
  - `src/Events/DocumentImported.php` — new event: `ImportResult $result`
  - `src/Stores/EloquentRoleStore.php` — updated event dispatch to pass Role model and changes array
  - `src/Stores/EloquentAssignmentStore.php` — updated event dispatch to pass Assignment model; refactored revoke to fetch-then-delete
  - `tests/Feature/EloquentPermissionStoreTest.php` — updated event assertions for new property names
  - `tests/Feature/EloquentRoleStoreTest.php` — updated event assertions to use `$event->role->id`
  - `tests/Feature/EloquentAssignmentStoreTest.php` — updated event assertions to use `$event->assignment->*`
- **Learnings for future iterations:**
  - Event signatures should pass Eloquent models (not just IDs) for richer event payloads — stores already have the models loaded
  - For revoke operations, fetch the model with `->first()` before `->delete()` so the model is available for the event payload
  - `$role->getChanges()` returns attributes changed during the last `updateOrCreate` save — useful for RoleUpdated event
  - Morphs store `subject_id` as a string in the DB — use `(int)` cast when comparing in test assertions
  - Positional constructor args mean renaming a parameter doesn't break call sites, but test assertions checking properties by name must be updated
---

## 2026-02-26 - US-013
- What was implemented: DefaultEvaluator — core evaluation engine resolving subject assignments into allow/deny decisions with boundary enforcement, deny-wins logic, scoped evaluation, and explain traces
- Files changed:
  - `src/Evaluators/DefaultEvaluator.php` — implements Evaluator contract: can() with 9-step evaluation, explain() with EvaluationTrace DTO, effectivePermissions() with deny filtering
  - `tests/Feature/DefaultEvaluatorTest.php` — 23 Pest tests covering basic allow/deny, deny-wins-over-allow, wildcard grants, scoped evaluation, boundary enforcement, no-assignment-means-deny, AuthorizationDenied event dispatch, explain traces, effectivePermissions
- **Learnings for future iterations:**
  - DefaultEvaluator uses constructor injection of 4 contracts (AssignmentStore, RoleStore, BoundaryStore, Matcher) — fully swappable
  - `gatherAssignments()` returns global-only when unscoped; global + scoped when scope is present — global assignments always apply
  - Scope parsing splits on first `:` only — scopes can contain colons (e.g., `group::5`)
  - Boundary check only applies when a scope is present — unscoped permissions skip boundary enforcement
  - `cacheHit` is always `false` in DefaultEvaluator — reserved for CachedEvaluator decorator
  - Tests use real Eloquent stores + WildcardMatcher (no mocks) — tests through the full stack
  - `Event::fake([AuthorizationDenied::class])` in specific tests to avoid interfering with store events in beforeEach
---

## 2026-02-26 - US-014
- What was implemented: CachedEvaluator decorator and InvalidatePermissionCache listener for event-driven cache invalidation
- Files changed:
  - `src/Evaluators/CachedEvaluator.php` — implements Evaluator, wraps DefaultEvaluator with cache-aside pattern; caches effectivePermissions per subject+scope; bypasses cache when disabled; delegates explain/effectivePermissions to inner
  - `src/Listeners/InvalidatePermissionCache.php` — handles AssignmentCreated, AssignmentRevoked, RoleUpdated, RoleDeleted, PermissionDeleted events; flushes the cache store
  - `tests/Feature/CachedEvaluatorTest.php` — 10 Pest tests covering cache miss, cache hit, invalidation on assignment/role changes, cache disabled bypass, delegation of explain/effectivePermissions, scoped cache keys
- **Learnings for future iterations:**
  - CachedEvaluator caches `effectivePermissions` (not individual `can()` results) — this allows checking any permission against the cached set
  - Cache key format: `policy-engine:{subjectType}:{subjectId}` (global) or `policy-engine:{subjectType}:{subjectId}:{scope}` (scoped)
  - SQLite does NOT enforce FK cascades by default — tests relying on cascade behavior should explicitly clean up related records
  - `CacheManager::store('array')` returns a consistent store instance across calls — safe for cross-class cache sharing in tests
  - The `cacheKey()` method is `public static` so tests can verify cache contents directly
  - For cache invalidation, flushing the entire configured cache store is the simplest correct approach — scoped key enumeration would require taggable stores
---

## 2026-02-26 - US-015
- What was implemented: HasPermissions trait — delegation-only trait providing canDo/cannotDo, assign/revoke, assignments/assignmentsFor, roles/rolesFor, effectivePermissions, explain methods
- Files changed:
  - `src/Concerns/HasPermissions.php` — trait with 10 public methods + private `buildScopedPermission()` helper, all resolving contracts from the container via `app()`
  - `tests/Feature/HasPermissionsTraitTest.php` — 25 Pest tests covering all trait methods with 49 assertions
- **Learnings for future iterations:**
  - The trait resolves contracts via `app()` per call — no constructor injection, since traits cannot have constructors
  - `buildScopedPermission()` appends `:scope` to permission strings for scoped evaluation — the evaluator expects this format
  - Tests must manually bind contracts to implementations in `beforeEach()` since the service provider (US-029) hasn't registered bindings yet
  - Create a `TestUser` model class with `HasPermissions` trait and an in-memory migration for testing — define the class in the test file itself
  - `roles()` and `rolesFor()` use pluck→unique→map→filter→values pipeline to resolve Role models from assignments
  - The `explain()` method throws `RuntimeException` when `config('policy-engine.explain')` is false — tested with config toggle
---
