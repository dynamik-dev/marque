# PRD: Laravel Policy Engine — Full Package Implementation

## Introduction

Build `dynamik-dev/laravel-policy-engine`, a Laravel-native scoped permissions package. It provides composable, contract-driven authorization that integrates with Gates, Policies, middleware, and Blade. Everything is coded to an interface — swap any implementation without touching the DX layer.

The package replaces flat permission systems (like Spatie) with structured `resource.verb` permissions, scoped role assignments, deny rules, permission boundaries, wildcard matching, and portable JSON policy documents. It follows a 3-layer architecture: DX layer (traits, middleware, blade, facade, commands) delegates to contracts (interfaces), which are backed by default implementations (Eloquent stores, cached evaluator, wildcard matcher).

**Package:** `dynamik-dev/laravel-policy-engine`
**Namespace:** `DynamikDev\PolicyEngine\`
**Requires:** Laravel 11+, PHP 8.4+
**Spec:** See `spec.md` for the full specification.

## Goals

- Implement the complete package as defined in `spec.md`, all 22 sections
- Ship working default implementations for every contract (Eloquent stores, cached evaluator, wildcard matcher, JSON document parser)
- Provide a full DX layer: `HasPermissions` trait, `Scopeable` trait, `Primitives` facade, `can_do`/`role` middleware, Blade directives, and Artisan commands
- Support policy documents for import/export/versioning of authorization config as JSON
- All implementations are swappable via Laravel's service container — no hardcoded dependencies between layers
- Full Pest test suite covering permission matching, scoped evaluation, boundary enforcement, cache invalidation, and document round-trips
- PHP 8.4+ with full type hints and return types on every method

## User Stories

### US-001: Create package scaffolding
**Priority:** 1
**Description:** As a developer, I need the Laravel package skeleton so all subsequent code has a home.

**Acceptance Criteria:**
- [ ] `composer.json` exists with name `dynamik-dev/laravel-policy-engine`, PHP `^8.4` requirement, Laravel `^11.0|^12.0` requirement, `pestphp/pest` as dev dependency, PSR-4 autoload mapping `DynamikDev\\PolicyEngine\\` to `src/`, and `DynamikDev\\PolicyEngine\\Tests\\` to `tests/`
- [ ] `src/PolicyEngineServiceProvider.php` exists as a skeleton class extending `Illuminate\Support\ServiceProvider` with empty `register()` and `boot()` methods. It must be registered in composer.json `extra.laravel.providers`
- [ ] `config/policy-engine.php` exists returning the configuration array from spec section 4 (cache settings, protect_system_roles, log_denials, explain toggle, document_format) — use config key `policy-engine`
- [ ] `phpunit.xml` (or `pest` equivalent) configured for the `tests/` directory
- [ ] Directory structure created: `src/Contracts/`, `src/Stores/`, `src/Models/`, `src/Concerns/`, `src/Events/`, `src/Facades/`, `src/Middleware/`, `src/Commands/`, `src/DTOs/`, `src/Documents/`, `tests/Feature/`
- [ ] `vendor/bin/pest` runs with zero tests and no errors

---

### US-002: Create database migrations
**Priority:** 2
**Description:** As a developer, I need the database tables so Eloquent stores can persist data.

**Acceptance Criteria:**
- [ ] Migration `create_permissions_table`: `permissions` table with `string('id')->primary()`, nullable `string('description')`, `timestamps()`
- [ ] Migration `create_roles_table`: `roles` table with `string('id')->primary()`, `string('name')`, `boolean('is_system')->default(false)`, `timestamps()`
- [ ] Migration `create_role_permissions_table`: `role_permissions` table with `string('role_id')`, `string('permission_id')`, composite `primary(['role_id', 'permission_id'])`, foreign key `role_id` referencing `roles.id` with cascade on delete
- [ ] Migration `create_assignments_table`: `assignments` table with `id()` (auto-increment PK), `morphs('subject')` (creates `subject_type` and `subject_id`), `string('role_id')`, nullable `string('scope')`, `timestamps()`, unique constraint on `['subject_id', 'subject_type', 'role_id', 'scope']`, foreign key `role_id` referencing `roles.id` with cascade on delete, index on `scope`
- [ ] Migration `create_boundaries_table`: `boundaries` table with `id()`, `string('scope')->unique()`, `json('max_permissions')`, `timestamps()`
- [ ] Migrations are publishable via the service provider's `boot()` method using `$this->publishesMigrations()`
- [ ] All migrations use anonymous classes (`return new class extends Migration`)

---

### US-003: Create Eloquent models
**Priority:** 3
**Description:** As a developer, I need Eloquent models for each table so stores can use them for database operations.

**Acceptance Criteria:**
- [ ] `src/Models/Permission.php` — model with `$table = 'permissions'`, `$primaryKey = 'id'`, `$keyType = 'string'`, `$incrementing = false`, fillable: `['id', 'description']`
- [ ] `src/Models/Role.php` — model with `$table = 'roles'`, `$primaryKey = 'id'`, `$keyType = 'string'`, `$incrementing = false`, fillable: `['id', 'name', 'is_system']`, cast `is_system` to `boolean`. Has a `permissions()` belongsToMany relationship through `role_permissions` table (related model: `Permission`, pivot table: `role_permissions`, foreign pivot key: `role_id`, related pivot key: `permission_id`)
- [ ] `src/Models/RolePermission.php` — model with `$table = 'role_permissions'`, `$incrementing = false`, no primary key auto-increment, fillable: `['role_id', 'permission_id']`, belongs to `Role` and `Permission`
- [ ] `src/Models/Assignment.php` — model with `$table = 'assignments'`, fillable: `['subject_type', 'subject_id', 'role_id', 'scope']`, has a `subject()` morphTo relationship, belongs to `Role`
- [ ] `src/Models/Boundary.php` — model with `$table = 'boundaries'`, fillable: `['scope', 'max_permissions']`, cast `max_permissions` to `array`
- [ ] All models have full PHP 8.4 type hints on properties and return types on methods

---

### US-004: Define all contracts (interfaces)
**Priority:** 4
**Description:** As a developer, I need the contract interfaces so all implementations code to a stable API.

**Acceptance Criteria:**
- [ ] `src/Contracts/PermissionStore.php` — interface with methods: `register(string|array $permissions): void`, `remove(string $id): void`, `all(?string $prefix = null): Collection`, `exists(string $id): bool`. Import `Illuminate\Support\Collection`
- [ ] `src/Contracts/RoleStore.php` — interface with methods: `save(string $id, string $name, array $permissions, bool $system = false): Role`, `remove(string $id): void`, `find(string $id): ?Role`, `all(): Collection`, `permissionsFor(string $roleId): array`. The `Role` return type refers to the `DynamikDev\PolicyEngine\Models\Role` Eloquent model
- [ ] `src/Contracts/AssignmentStore.php` — interface with methods: `assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void`, `revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void`, `forSubject(string $subjectType, string|int $subjectId): Collection`, `forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection`, `subjectsInScope(string $scope, ?string $roleId = null): Collection`
- [ ] `src/Contracts/BoundaryStore.php` — interface with methods: `set(string $scope, array $maxPermissions): void`, `remove(string $scope): void`, `find(string $scope): ?Boundary`. The `Boundary` return type refers to the `DynamikDev\PolicyEngine\Models\Boundary` Eloquent model
- [ ] `src/Contracts/Evaluator.php` — interface with methods: `can(string $subjectType, string|int $subjectId, string $permission): bool`, `explain(string $subjectType, string|int $subjectId, string $permission): EvaluationTrace`, `effectivePermissions(string $subjectType, string|int $subjectId, ?string $scope = null): array`. The `EvaluationTrace` type refers to `DynamikDev\PolicyEngine\DTOs\EvaluationTrace`
- [ ] `src/Contracts/Matcher.php` — interface with method: `matches(string $granted, string $required): bool`
- [ ] `src/Contracts/ScopeResolver.php` — interface with method: `resolve(mixed $scope): ?string`
- [ ] `src/Contracts/DocumentParser.php` — interface with methods: `parse(string $content): PolicyDocument`, `serialize(PolicyDocument $document): string`, `validate(string $content): ValidationResult`. Types refer to DTOs in `DynamikDev\PolicyEngine\DTOs\`
- [ ] `src/Contracts/DocumentImporter.php` — interface with method: `import(PolicyDocument $document, ImportOptions $options): ImportResult`
- [ ] `src/Contracts/DocumentExporter.php` — interface with method: `export(?string $scope = null): PolicyDocument`
- [ ] All interfaces are in `DynamikDev\PolicyEngine\Contracts\` namespace
- [ ] All method signatures have full PHP 8.4 type hints and return types

---

### US-005: Create DTOs and value objects
**Priority:** 5
**Description:** As a developer, I need data transfer objects so contracts have well-typed parameters and return values.

**Acceptance Criteria:**
- [ ] `src/DTOs/EvaluationTrace.php` — readonly class with public properties: `string $subject`, `string $required`, `string $result` (either `'ALLOW'` or `'DENY'`), `array $assignments` (each entry is an array with keys: `role`, `scope`, `permissions_checked`), `?string $boundary`, `bool $cacheHit`. Constructor accepts all properties
- [ ] `src/DTOs/PolicyDocument.php` — readonly class with public properties: `string $version` (default `'1.0'`), `array $permissions` (string[]), `array $roles` (each is array with keys: `id`, `name`, `permissions`, optional `system`), `array $assignments` (each is array with keys: `subject`, `role`, optional `scope`), `array $boundaries` (each is array with keys: `scope`, `max_permissions`). Constructor accepts all properties with sensible defaults (empty arrays)
- [ ] `src/DTOs/ImportOptions.php` — readonly class with public properties: `bool $validate` (default `true`), `bool $merge` (default `true`), `bool $dryRun` (default `false`), `bool $skipAssignments` (default `false`). Constructor accepts all properties
- [ ] `src/DTOs/ImportResult.php` — readonly class with public properties: `array $permissionsCreated`, `array $rolesCreated`, `array $rolesUpdated`, `int $assignmentsCreated`, `array $warnings`. Constructor accepts all properties
- [ ] `src/DTOs/ValidationResult.php` — readonly class with public properties: `bool $valid`, `array $errors` (string[])
- [ ] All DTOs are in `DynamikDev\PolicyEngine\DTOs\` namespace
- [ ] All DTOs use PHP 8.4 readonly classes with typed constructor parameters

---

### US-006: Implement WildcardMatcher
**Priority:** 6
**Description:** As a developer, I need a wildcard matcher so permission strings like `posts.*` can match `posts.create`.

**Acceptance Criteria:**
- [ ] `src/Matchers/WildcardMatcher.php` implements `DynamikDev\PolicyEngine\Contracts\Matcher`
- [ ] Exact match: `posts.create` matches `posts.create` — returns `true`
- [ ] Wildcard verb match: `posts.*` matches `posts.create`, `posts.delete`, `posts.update.own` — returns `true`
- [ ] Wildcard resource match: `*.create` matches `posts.create`, `comments.create` — returns `true`
- [ ] Full wildcard: `*.*` matches any permission string — returns `true`
- [ ] Single `*` matches any permission string — returns `true`
- [ ] No match: `posts.create` does NOT match `posts.delete` — returns `false`
- [ ] No match: `posts.create` does NOT match `comments.create` — returns `false`
- [ ] Scope matching: `posts.create:group::5` matches `posts.create:group::5` — returns `true`
- [ ] Scope wildcard: `posts.create` (no scope) matches `posts.create:group::5` (scoped) — returns `true` (unscoped grants cover scoped checks)
- [ ] Deep verb matching: `posts.delete.*` matches `posts.delete.own` and `posts.delete.any` — returns `true`
- [ ] Deny prefix is NOT handled by matcher — deny logic (`!` prefix) is the evaluator's responsibility. Matcher only compares raw permission strings without the `!` prefix
- [ ] Pest feature tests in `tests/Feature/WildcardMatcherTest.php` covering all cases above

---

### US-007: Implement ModelScopeResolver
**Priority:** 7
**Description:** As a developer, I need a scope resolver so models with `Scopeable` trait and raw strings can be resolved to scope strings.

**Acceptance Criteria:**
- [ ] `src/Resolvers/ModelScopeResolver.php` implements `DynamikDev\PolicyEngine\Contracts\ScopeResolver`
- [ ] If `$scope` is `null`, returns `null`
- [ ] If `$scope` is a `string`, returns it as-is (e.g., `'group::5'` returns `'group::5'`)
- [ ] If `$scope` is a Model with a `toScope()` method (i.e., uses `Scopeable` trait), calls `toScope()` and returns the result
- [ ] If `$scope` is an unsupported type, throws `\InvalidArgumentException`
- [ ] Pest feature tests in `tests/Feature/ModelScopeResolverTest.php` covering null, string, Scopeable model, and invalid input

---

### US-008: Implement EloquentPermissionStore
**Priority:** 8
**Description:** As a developer, I need an Eloquent-backed permission store so permissions can be persisted to the database.

**Acceptance Criteria:**
- [ ] `src/Stores/EloquentPermissionStore.php` implements `DynamikDev\PolicyEngine\Contracts\PermissionStore`
- [ ] `register(string|array $permissions)`: accepts a single permission string or array of strings. For each permission, creates a `Permission` model if it doesn't already exist (idempotent). Uses `Permission::query()->firstOrCreate(['id' => $perm])` or equivalent. Dispatches `PermissionCreated` event for each newly created permission
- [ ] `remove(string $id)`: deletes the permission by ID. Also deletes related `role_permissions` rows (handled by the model or explicit query). Dispatches `PermissionDeleted` event
- [ ] `all(?string $prefix = null)`: returns all permissions as a Collection. If `$prefix` is provided, filters to permissions whose `id` starts with the prefix (e.g., prefix `'posts'` returns `posts.create`, `posts.delete`, etc.)
- [ ] `exists(string $id)`: returns `true` if a permission with the given ID exists, `false` otherwise
- [ ] Events are dispatched using `Event::dispatch()`, not the `event()` helper
- [ ] Uses `Model::query()` instead of `DB::` facade
- [ ] Pest feature tests in `tests/Feature/EloquentPermissionStoreTest.php` covering register (single, array, idempotent), remove (with cascade), all (with and without prefix), exists

---

### US-009: Implement EloquentRoleStore
**Priority:** 9
**Description:** As a developer, I need an Eloquent-backed role store so roles and their permission associations can be persisted.

**Acceptance Criteria:**
- [ ] `src/Stores/EloquentRoleStore.php` implements `DynamikDev\PolicyEngine\Contracts\RoleStore`
- [ ] `save(string $id, string $name, array $permissions, bool $system = false)`: creates or updates a role. Uses `Role::query()->updateOrCreate(['id' => $id], ['name' => $name, 'is_system' => $system])`. Syncs the role's permissions in `role_permissions` table (delete existing, insert new). Dispatches `RoleCreated` event if new, `RoleUpdated` event if updated. Returns the Role model
- [ ] `remove(string $id)`: deletes the role. If `config('policy-engine.protect_system_roles')` is `true` and the role has `is_system = true`, throws an exception (e.g., `\RuntimeException`). Cascading delete of `role_permissions` and `assignments` is handled by foreign keys. Dispatches `RoleDeleted` event
- [ ] `find(string $id)`: returns the Role model or `null`
- [ ] `all()`: returns all roles as a Collection
- [ ] `permissionsFor(string $roleId)`: returns an array of permission ID strings for the role (from `role_permissions` table)
- [ ] Uses `Event::dispatch()` for events, `Model::query()` for queries
- [ ] Pest feature tests in `tests/Feature/EloquentRoleStoreTest.php` covering save (create, update, sync permissions), remove (normal, system-protected), find, all, permissionsFor

---

### US-010: Implement EloquentAssignmentStore
**Priority:** 10
**Description:** As a developer, I need an Eloquent-backed assignment store so role assignments to subjects (with optional scoping) can be persisted.

**Acceptance Criteria:**
- [ ] `src/Stores/EloquentAssignmentStore.php` implements `DynamikDev\PolicyEngine\Contracts\AssignmentStore`
- [ ] `assign(...)`: creates an assignment. Uses `Assignment::query()->firstOrCreate(...)` with all four fields (`subject_type`, `subject_id`, `role_id`, `scope`) to enforce uniqueness. Dispatches `AssignmentCreated` event only if newly created
- [ ] `revoke(...)`: deletes the matching assignment. Dispatches `AssignmentRevoked` event if a row was deleted
- [ ] `forSubject(string $subjectType, string|int $subjectId)`: returns all assignments for the subject as a Collection
- [ ] `forSubjectInScope(...)`: returns assignments for the subject filtered by exact scope match
- [ ] `subjectsInScope(string $scope, ?string $roleId = null)`: returns all assignments in the scope. If `$roleId` is provided, also filters by role
- [ ] Uses `Event::dispatch()` for events, `Model::query()` for queries
- [ ] Pest feature tests in `tests/Feature/EloquentAssignmentStoreTest.php` covering assign (new, idempotent), revoke, forSubject, forSubjectInScope, subjectsInScope (with and without roleId)

---

### US-011: Implement EloquentBoundaryStore
**Priority:** 11
**Description:** As a developer, I need an Eloquent-backed boundary store so permission boundaries can be set per scope.

**Acceptance Criteria:**
- [ ] `src/Stores/EloquentBoundaryStore.php` implements `DynamikDev\PolicyEngine\Contracts\BoundaryStore`
- [ ] `set(string $scope, array $maxPermissions)`: creates or updates a boundary. Uses `Boundary::query()->updateOrCreate(['scope' => $scope], ['max_permissions' => $maxPermissions])`
- [ ] `remove(string $scope)`: deletes the boundary for the given scope
- [ ] `find(string $scope)`: returns the Boundary model or `null`
- [ ] Uses `Model::query()` for queries
- [ ] Pest feature tests in `tests/Feature/EloquentBoundaryStoreTest.php` covering set (create, update), remove, find (exists, not exists)

---

### US-012: Create event classes
**Priority:** 12
**Description:** As a developer, I need event classes so store implementations can dispatch observable events for cache invalidation and auditing.

**Acceptance Criteria:**
- [ ] `src/Events/PermissionCreated.php` — public readonly property `string $permission`
- [ ] `src/Events/PermissionDeleted.php` — public readonly property `string $permission`
- [ ] `src/Events/RoleCreated.php` — public readonly property `Role $role` (Eloquent model)
- [ ] `src/Events/RoleUpdated.php` — public readonly properties `Role $role`, `array $changes`
- [ ] `src/Events/RoleDeleted.php` — public readonly property `Role $role`
- [ ] `src/Events/AssignmentCreated.php` — public readonly property `Assignment $assignment` (Eloquent model)
- [ ] `src/Events/AssignmentRevoked.php` — public readonly property `Assignment $assignment`
- [ ] `src/Events/AuthorizationDenied.php` — public readonly properties `string $subject`, `string $permission`, `?string $scope`
- [ ] `src/Events/DocumentImported.php` — public readonly property `ImportResult $result`
- [ ] All events are in `DynamikDev\PolicyEngine\Events\` namespace
- [ ] All events use constructor promotion for properties
- [ ] No event extends any base class (plain classes, not Eloquent events)

---

### US-013: Implement DefaultEvaluator
**Priority:** 13
**Description:** As a developer, I need the core evaluation engine that resolves subject assignments into an allow/deny decision.

**Acceptance Criteria:**
- [ ] `src/Evaluators/DefaultEvaluator.php` implements `DynamikDev\PolicyEngine\Contracts\Evaluator`
- [ ] Constructor accepts: `AssignmentStore $assignments`, `RoleStore $roles`, `BoundaryStore $boundaries`, `Matcher $matcher`
- [ ] `can(string $subjectType, string|int $subjectId, string $permission): bool` — evaluation order:
  1. Parse scope from permission string if present (format: `permission:scope`, e.g., `posts.create:group::5`)
  2. Get all assignments for the subject (global + scoped if scope is present)
  3. For each assignment, get the role's permissions via `RoleStore::permissionsFor()`
  4. Check boundary: if scope is present, get boundary via `BoundaryStore::find()`. If a boundary exists, verify the required permission is covered by at least one `max_permissions` entry using the `Matcher`. If not covered, deny
  5. Collect all deny permissions (prefixed with `!`) and all allow permissions (no prefix)
  6. **Deny wins:** if any deny permission (after stripping `!`) matches the required permission via `Matcher`, return `false`
  7. If any allow permission matches the required permission via `Matcher`, return `true`
  8. Default: return `false` (no match = deny)
  9. If result is `false` and `config('policy-engine.log_denials')` is `true`, dispatch `AuthorizationDenied` event
- [ ] `explain(...)` — returns an `EvaluationTrace` DTO with the full resolution chain. If `config('policy-engine.explain')` is `false`, throws `\RuntimeException`
- [ ] `effectivePermissions(string $subjectType, string|int $subjectId, ?string $scope = null)` — returns array of all permission strings the subject is effectively granted (after deny filtering) in the given scope (or globally if null)
- [ ] Pest feature tests in `tests/Feature/DefaultEvaluatorTest.php` covering: basic allow, basic deny, deny-wins-over-allow, wildcard grants, scoped evaluation, boundary enforcement (permission within boundary, permission outside boundary), no-assignment-means-deny, explain output structure

---

### US-014: Implement CachedEvaluator
**Priority:** 14
**Description:** As a developer, I need a caching wrapper around the evaluator so repeated permission checks are fast, with automatic event-driven cache invalidation.

**Acceptance Criteria:**
- [ ] `src/Evaluators/CachedEvaluator.php` implements `DynamikDev\PolicyEngine\Contracts\Evaluator`
- [ ] Constructor accepts: `Evaluator $inner` (the `DefaultEvaluator`), `CacheManager $cache`
- [ ] Cache key format: `policy-engine:{subjectType}:{subjectId}` for global, `policy-engine:{subjectType}:{subjectId}:{scope}` for scoped
- [ ] `can()`: checks cache for the subject's effective permissions first. If cache hit, evaluate against cached set. If cache miss, delegates to inner evaluator and caches the result
- [ ] Cache TTL is read from `config('policy-engine.cache.ttl')`
- [ ] Cache store is read from `config('policy-engine.cache.store')` — use `'default'` if not set
- [ ] If `config('policy-engine.cache.enabled')` is `false`, delegates directly to inner evaluator without caching
- [ ] `explain()` and `effectivePermissions()` delegate to inner evaluator (no caching on these)
- [ ] `src/Listeners/InvalidatePermissionCache.php` — a listener class that flushes relevant cache keys. Register it to listen for: `AssignmentCreated`, `AssignmentRevoked`, `RoleUpdated`, `RoleDeleted`, `PermissionDeleted`. On each event, flush the affected subject's cache keys (or flush all policy-engine cache keys if the affected subject cannot be determined, e.g., role changes affect all subjects with that role)
- [ ] Pest feature tests in `tests/Feature/CachedEvaluatorTest.php` covering: cache hit behavior, cache miss behavior, cache invalidation on assignment change, cache invalidation on role change, cache disabled config

---

### US-015: Implement HasPermissions trait
**Priority:** 15
**Description:** As a developer, I need the `HasPermissions` trait so any Eloquent model can perform permission checks and role assignments through a clean API.

**Acceptance Criteria:**
- [ ] `src/Concerns/HasPermissions.php` — trait in `DynamikDev\PolicyEngine\Concerns\` namespace
- [ ] `canDo(string $permission, mixed $scope = null): bool` — resolves scope via `ScopeResolver`, builds full permission string (`permission:scope` if scoped), delegates to `Evaluator::can()` using `$this->getMorphClass()` and `$this->getKey()`
- [ ] `cannotDo(string $permission, mixed $scope = null): bool` — negation of `canDo()`
- [ ] `assign(string $roleId, mixed $scope = null): void` — resolves scope, delegates to `AssignmentStore::assign()`
- [ ] `revoke(string $roleId, mixed $scope = null): void` — resolves scope, delegates to `AssignmentStore::revoke()`
- [ ] `assignments(): Collection` — delegates to `AssignmentStore::forSubject()`
- [ ] `assignmentsFor(mixed $scope): Collection` — resolves scope, delegates to `AssignmentStore::forSubjectInScope()`
- [ ] `effectivePermissions(mixed $scope = null): array` — resolves scope, delegates to `Evaluator::effectivePermissions()`
- [ ] `roles(): Collection` — gets assignments, plucks unique role_ids, returns collection of Role models via `RoleStore::find()`
- [ ] `rolesFor(mixed $scope): Collection` — same as `roles()` but filtered to scope
- [ ] `explain(string $permission, mixed $scope = null): EvaluationTrace` — resolves scope, delegates to `Evaluator::explain()`
- [ ] All methods resolve contracts from the container via `app()` — the trait holds no logic, only delegation
- [ ] Pest feature tests in `tests/Feature/HasPermissionsTraitTest.php` using a test User model, covering: canDo/cannotDo, assign/revoke, assignments/assignmentsFor, roles/rolesFor, effectivePermissions

---

### US-016: Implement Scopeable trait
**Priority:** 16
**Description:** As a developer, I need the `Scopeable` trait so models that represent scopes (e.g., Group, Organization) can produce scope strings and query membership.

**Acceptance Criteria:**
- [ ] `src/Concerns/Scopeable.php` — trait in `DynamikDev\PolicyEngine\Concerns\` namespace
- [ ] The model using this trait must define a `protected string $scopeType` property (e.g., `'group'`)
- [ ] `toScope(): string` — returns `"{$this->scopeType}::{$this->getKey()}"` (e.g., `"group::5"`)
- [ ] `members(): Collection` — delegates to `AssignmentStore::subjectsInScope($this->toScope())`, returns the collection of assignments
- [ ] `membersWithRole(string $roleId): Collection` — delegates to `AssignmentStore::subjectsInScope($this->toScope(), $roleId)`
- [ ] Pest feature tests in `tests/Feature/ScopeableTraitTest.php` using a test Group model, covering: toScope output format, members, membersWithRole

---

### US-017: Implement Primitives facade
**Priority:** 17
**Description:** As a developer, I need the `Primitives` facade so seeding and runtime management have a clean, static API.

**Acceptance Criteria:**
- [ ] `src/Facades/Primitives.php` — extends `Illuminate\Support\Facades\Facade`
- [ ] `src/PrimitivesManager.php` — the underlying class the facade proxies to. Constructor accepts `PermissionStore`, `RoleStore`, `BoundaryStore`, `DocumentParser`, `DocumentImporter`, `DocumentExporter` via dependency injection
- [ ] `permissions(array $permissions): void` — delegates to `PermissionStore::register()`
- [ ] `role(string $id, string $name, bool $system = false): RoleBuilder` — creates/saves a role via `RoleStore::save()` with empty permissions, returns a `RoleBuilder` fluent object
- [ ] `src/Support/RoleBuilder.php` — fluent builder with `grant(array $permissions): self` (adds permissions to role via `RoleStore::save()`) and `ungrant(array $permissions): self` (removes specific permissions) and `remove(): void` (delegates to `RoleStore::remove()`)
- [ ] `boundary(string $scope, array $maxPermissions): void` — delegates to `BoundaryStore::set()`
- [ ] `import(string $pathOrContent, ?ImportOptions $options = null): ImportResult` — if `$pathOrContent` is a file path (file_exists check), reads the file contents. Parses via `DocumentParser::parse()`, then delegates to `DocumentImporter::import()`. If `$options` is null, uses default `ImportOptions`
- [ ] `export(?string $scope = null): string` — delegates to `DocumentExporter::export()` then `DocumentParser::serialize()`
- [ ] `exportToFile(string $path, ?string $scope = null): void` — same as `export()` but writes result to file
- [ ] Pest feature tests in `tests/Feature/PrimitivesFacadeTest.php` covering: permissions registration, role creation with grant chaining, boundary setting, import from string, export

---

### US-018: Implement middleware
**Priority:** 18
**Description:** As a developer, I need `can_do` and `role` middleware so routes can be protected with permission and role checks.

**Acceptance Criteria:**
- [ ] `src/Middleware/CanDoMiddleware.php` — middleware class with `handle(Request $request, Closure $next, string $permission, ?string $scopeParam = null)`
  - Gets the authenticated user via `$request->user()`
  - If `$scopeParam` is provided, resolves the scope from route parameters: `$request->route($scopeParam)`. The route parameter value will be a model (via route model binding) or a string — pass it through `ScopeResolver`
  - Calls `$user->canDo($permission, scope: $resolvedScope)`
  - If denied, aborts with 403
  - If allowed, passes to `$next`
- [ ] `src/Middleware/RoleMiddleware.php` — middleware class with `handle(Request $request, Closure $next, string $role, ?string $scopeParam = null)`
  - Gets the authenticated user
  - Resolves scope same as CanDoMiddleware
  - Checks if user has the required role in scope by querying `AssignmentStore::forSubjectInScope()` (or `forSubject()` if no scope) and checking if any assignment has the required role_id
  - If no matching assignment, aborts with 403
  - If found, passes to `$next`
- [ ] Both middleware are registered in the service provider with aliases `can_do` and `role`
- [ ] Pest feature tests in `tests/Feature/MiddlewareTest.php` covering: can_do allows, can_do denies (403), can_do with scope parameter, role allows, role denies (403), unauthenticated user (401 or redirect)

---

### US-019: Implement Blade directives
**Priority:** 19
**Description:** As a developer, I need Blade directives so views can conditionally render based on permissions and roles.

**Acceptance Criteria:**
- [ ] `@canDo('permission', $scope)` / `@endCanDo` — compiles to a PHP if-block that calls `auth()->user()->canDo('permission', $scope)`. The second argument (`$scope`) is optional
- [ ] `@cannotDo('permission', $scope)` / `@endCannotDo` — compiles to the negation
- [ ] `@hasRole('role', $scope)` / `@endHasRole` — compiles to check if the user has the role assignment in the given scope. Uses `AssignmentStore` to check
- [ ] Directives are registered in the service provider's `boot()` method via `Blade::if()`
- [ ] If no user is authenticated, all directives evaluate to `false` (never throws)
- [ ] Pest feature tests in `tests/Feature/BladeDirectivesTest.php` covering: canDo renders content when allowed, canDo hides content when denied, cannotDo inverse behavior, hasRole with and without scope, unauthenticated user sees nothing

---

### US-020: Implement JsonDocumentParser
**Priority:** 20
**Description:** As a developer, I need a JSON document parser so policy documents can be parsed from and serialized to JSON strings.

**Acceptance Criteria:**
- [ ] `src/Documents/JsonDocumentParser.php` implements `DynamikDev\PolicyEngine\Contracts\DocumentParser`
- [ ] `parse(string $content): PolicyDocument` — decodes JSON string, validates structure, returns a `PolicyDocument` DTO. Throws `\InvalidArgumentException` if JSON is invalid
- [ ] `serialize(PolicyDocument $document): string` — encodes the DTO to a pretty-printed JSON string (`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`)
- [ ] `validate(string $content): ValidationResult` — validates JSON structure without importing. Checks: valid JSON, `version` field exists, `permissions` is array of strings (if present), `roles` entries have `id`, `name`, `permissions` keys (if present), `assignments` entries have `subject` and `role` keys (if present), `boundaries` entries have `scope` and `max_permissions` keys (if present). Returns `ValidationResult` with `valid = true` or `valid = false` with error messages
- [ ] All sections of the document (`permissions`, `roles`, `assignments`, `boundaries`) are optional — a document with only `roles` is valid
- [ ] Pest feature tests in `tests/Feature/JsonDocumentParserTest.php` covering: parse valid document, parse partial document (roles only), parse invalid JSON, serialize round-trip, validate valid document, validate invalid document

---

### US-021: Implement DefaultDocumentImporter
**Priority:** 21
**Description:** As a developer, I need a document importer so parsed policy documents can be applied to the stores.

**Acceptance Criteria:**
- [ ] `src/Documents/DefaultDocumentImporter.php` implements `DynamikDev\PolicyEngine\Contracts\DocumentImporter`
- [ ] Constructor accepts: `PermissionStore`, `RoleStore`, `AssignmentStore`, `BoundaryStore`
- [ ] `import(PolicyDocument $document, ImportOptions $options): ImportResult`:
  - If `$options->validate` is `true`, validate that permission strings referenced in roles exist in the document's permissions list or are already registered. Collect warnings for unregistered permissions
  - If `$options->merge` is `true` (default), merge with existing state (create new, update existing, don't delete missing). If `false`, replace: remove all existing permissions/roles/assignments/boundaries before importing
  - If `$options->dryRun` is `true`, compute the result without writing to any store. Return the `ImportResult` showing what would change
  - If `$options->skipAssignments` is `true`, skip importing assignments
  - Processing order: permissions first, then roles, then boundaries, then assignments
  - Register all permissions via `PermissionStore::register()`
  - Save all roles via `RoleStore::save()`
  - Set all boundaries via `BoundaryStore::set()`
  - Create all assignments via `AssignmentStore::assign()` (parse `subject` field as `type::id`)
  - Dispatch `DocumentImported` event with the `ImportResult`
  - Return `ImportResult` with counts and warnings
- [ ] Pest feature tests in `tests/Feature/DefaultDocumentImporterTest.php` covering: full import, merge mode, replace mode, dry run (no DB changes), skip assignments, validation warnings, round-trip with exporter

---

### US-022: Implement DefaultDocumentExporter
**Priority:** 22
**Description:** As a developer, I need a document exporter so the current authorization state can be exported as a PolicyDocument.

**Acceptance Criteria:**
- [ ] `src/Documents/DefaultDocumentExporter.php` implements `DynamikDev\PolicyEngine\Contracts\DocumentExporter`
- [ ] Constructor accepts: `PermissionStore`, `RoleStore`, `AssignmentStore`, `BoundaryStore`
- [ ] `export(?string $scope = null): PolicyDocument`:
  - If `$scope` is `null`, export everything: all permissions, all roles with their permissions, all assignments, all boundaries
  - If `$scope` is provided, export only: all permissions (always included), roles that have assignments in this scope, assignments in this scope, boundary for this scope (if exists)
  - Build and return a `PolicyDocument` DTO with `version = '1.0'`
  - Assignments are serialized as `subject` = `"{type}::{id}"`, `role` = role_id, `scope` = scope string or omitted if null
- [ ] Pest feature tests in `tests/Feature/DefaultDocumentExporterTest.php` covering: export all, export scoped, export empty state, round-trip with importer

---

### US-023: Implement listing Artisan commands
**Priority:** 23
**Description:** As a developer, I need Artisan commands to list permissions, roles, and assignments for inspection and debugging.

**Acceptance Criteria:**
- [ ] `src/Commands/ListPermissionsCommand.php` — signature: `primitives:permissions`. Lists all registered permissions in a table format (ID, description). Resolves `PermissionStore` from container
- [ ] `src/Commands/ListRolesCommand.php` — signature: `primitives:roles`. Lists all roles in a table (ID, name, system flag, permission count). For each role, shows its permissions indented below
- [ ] `src/Commands/ListAssignmentsCommand.php` — signature: `primitives:assignments {subject?} {--scope=}`. If `subject` argument is provided, lists assignments for that subject (expects format `type::id`, e.g., `user::42`). If `--scope` option is provided, lists all assignments in that scope. If neither, shows usage help. Resolves `AssignmentStore` from container
- [ ] All commands are registered in the service provider
- [ ] Pest feature tests in `tests/Feature/ArtisanCommandsTest.php` covering: each command runs without error, output contains expected data

---

### US-024: Implement explain Artisan command
**Priority:** 24
**Description:** As a developer, I need an Artisan command to explain why a permission check passes or fails.

**Acceptance Criteria:**
- [ ] `src/Commands/ExplainCommand.php` — signature: `primitives:explain {subject} {permission} {--scope=}`
- [ ] `subject` argument expects format `type::id` (e.g., `user::42`) — parse into subjectType and subjectId
- [ ] Delegates to `Evaluator::explain()`, building the full permission string with scope if `--scope` is provided
- [ ] Outputs the `EvaluationTrace` in a human-readable format: subject, required permission, result (ALLOW/DENY), each assignment checked with its role and which permissions matched/didn't match, boundary info, cache hit status
- [ ] If `config('policy-engine.explain')` is `false`, shows an error message telling the user to enable it
- [ ] Pest feature test in `tests/Feature/ArtisanCommandsTest.php` covering: explain command output for allow and deny cases

---

### US-025: Implement import Artisan command
**Priority:** 25
**Description:** As a developer, I need an Artisan command to import policy documents from JSON files.

**Acceptance Criteria:**
- [ ] `src/Commands/ImportCommand.php` — signature: `primitives:import {path} {--dry-run} {--skip-assignments} {--replace} {--force}`
- [ ] Reads the file at `{path}`, delegates to `Primitives::import()` with appropriate `ImportOptions`
- [ ] `--dry-run`: sets `ImportOptions::dryRun = true`, outputs what would change without applying
- [ ] `--skip-assignments`: sets `ImportOptions::skipAssignments = true`
- [ ] `--replace`: sets `ImportOptions::merge = false`. Requires `--force` flag or prompts for confirmation ("This will delete all existing permissions, roles, assignments, and boundaries. Continue?")
- [ ] Outputs a summary: permissions created, roles created/updated, assignments created, warnings
- [ ] If file not found, shows error message
- [ ] Pest feature test in `tests/Feature/ArtisanCommandsTest.php` covering: import success, dry run output, file not found error

---

### US-026: Implement export Artisan command
**Priority:** 26
**Description:** As a developer, I need an Artisan command to export authorization state to JSON.

**Acceptance Criteria:**
- [ ] `src/Commands/ExportCommand.php` — signature: `primitives:export {--scope=} {--path=} {--stdout}`
- [ ] If `--path` is provided, writes the JSON to that file path
- [ ] If `--stdout` is provided, outputs the JSON to stdout (useful for piping to `jq`)
- [ ] If neither `--path` nor `--stdout`, defaults to outputting to stdout
- [ ] If `--scope` is provided, passes it to `Primitives::export(scope: $scope)` for scoped export
- [ ] Pest feature test in `tests/Feature/ArtisanCommandsTest.php` covering: export to stdout, export to file, export scoped

---

### US-027: Implement validate Artisan command
**Priority:** 27
**Description:** As a developer, I need an Artisan command to validate a policy document without importing it.

**Acceptance Criteria:**
- [ ] `src/Commands/ValidateCommand.php` — signature: `primitives:validate {path}`
- [ ] Reads the file, delegates to `DocumentParser::validate()`
- [ ] If valid, outputs success message
- [ ] If invalid, outputs each validation error
- [ ] Exit code 0 on valid, 1 on invalid
- [ ] Pest feature test in `tests/Feature/ArtisanCommandsTest.php` covering: validate valid file, validate invalid file

---

### US-028: Implement sync and cache-clear Artisan commands
**Priority:** 28
**Description:** As a developer, I need utility Artisan commands for syncing permissions and clearing cache.

**Acceptance Criteria:**
- [ ] `src/Commands/SyncCommand.php` — signature: `primitives:sync`. Calls `Artisan::call('db:seed', ['--class' => 'PermissionSeeder'])` or a configurable seeder class. Outputs success message. This re-runs the seeder idempotently
- [ ] `src/Commands/CacheClearCommand.php` — signature: `primitives:cache-clear`. Flushes all cache keys with the `policy-engine:` prefix. Uses the configured cache store from `config('policy-engine.cache.store')`. Outputs success message with count of cleared entries (or a generic "Cache cleared" if count is unavailable)
- [ ] Both commands are registered in the service provider
- [ ] Pest feature tests in `tests/Feature/ArtisanCommandsTest.php` covering: cache-clear runs without error

---

### US-029: Wire service provider with all bindings
**Priority:** 29
**Description:** As a developer, I need the service provider to bind all contracts to default implementations and register all DX surfaces so the package works on install.

**Acceptance Criteria:**
- [ ] `register()` method binds all contracts to default implementations:
  - `PermissionStore::class` => `EloquentPermissionStore::class`
  - `RoleStore::class` => `EloquentRoleStore::class`
  - `AssignmentStore::class` => `EloquentAssignmentStore::class`
  - `BoundaryStore::class` => `EloquentBoundaryStore::class`
  - `Matcher::class` => `WildcardMatcher::class`
  - `ScopeResolver::class` => `ModelScopeResolver::class`
  - `DocumentParser::class` => `JsonDocumentParser::class`
  - `DocumentImporter::class` => `DefaultDocumentImporter::class`
  - `DocumentExporter::class` => `DefaultDocumentExporter::class`
  - `Evaluator::class` => `CachedEvaluator::class` (with `DefaultEvaluator` as the inner evaluator — use `$this->app->bind()` with a closure that constructs `CachedEvaluator` wrapping `DefaultEvaluator`)
  - `PrimitivesManager::class` as singleton
- [ ] `boot()` method:
  - Publishes config: `$this->publishes([config_path => config_path('policy-engine.php')], 'policy-engine-config')`
  - Publishes migrations via `$this->publishesMigrations()`
  - Loads migrations from package path
  - Registers middleware aliases: `can_do` => `CanDoMiddleware::class`, `role` => `RoleMiddleware::class`
  - Registers Blade directives: `@canDo`, `@endCanDo`, `@cannotDo`, `@endCannotDo`, `@hasRole`, `@endHasRole`
  - Registers Artisan commands (all 8 commands) when running in console
  - Registers event listeners for cache invalidation
- [ ] Merges config in `register()` via `$this->mergeConfigFrom()`
- [ ] Pest feature test: the service provider can be booted in a test Laravel app without errors, and all contracts resolve from the container

---

### US-030: Implement Sanctum token scoping
**Priority:** 30
**Description:** As a developer, I need the evaluator to intersect Sanctum token abilities with role-based permissions so tokens can only exercise permissions they explicitly declare.

**Acceptance Criteria:**
- [ ] In `DefaultEvaluator::can()`, after the normal evaluation resolves to `true`, check if the current request is authenticated via Sanctum (check if `$user->currentAccessToken()` exists and is a Sanctum `PersonalAccessToken`)
- [ ] If a Sanctum token is present, get the token's abilities array
- [ ] The final result is `true` only if BOTH the role-based evaluation allows the permission AND the token's abilities include a matching permission (using the `Matcher` for wildcard support)
- [ ] If no Sanctum token is present (session auth, etc.), skip the token check — role-based result stands
- [ ] This behavior is in `DefaultEvaluator` so it flows through `CachedEvaluator` automatically
- [ ] Pest feature tests in `tests/Feature/SanctumScopingTest.php` covering: token with matching ability allows, token without matching ability denies, no token (session auth) allows normally. These tests should be skippable if Sanctum is not installed

---

### US-031: Core feature tests — matching, evaluation, boundaries
**Priority:** 31
**Description:** As a developer, I need comprehensive tests for the core evaluation pipeline to ensure correctness.

**Acceptance Criteria:**
- [ ] `tests/Feature/EvaluationPipelineTest.php` — end-to-end tests that set up permissions, roles, assignments, and boundaries, then assert `canDo()` results. Tests should use the `HasPermissions` trait on a test User model and exercise the full stack (stores → evaluator → trait)
- [ ] Test cases:
  - User with `member` role can do `posts.create` (has permission)
  - User with `member` role cannot do `members.remove` (lacks permission)
  - User with `moderator` role with `!posts.delete.any` deny rule cannot delete (deny wins)
  - User with `admin` role with `*.*` wildcard can do anything
  - User with scoped assignment (`group::5`) can do permission in that scope
  - User with scoped assignment cannot do permission in a different scope
  - User with global assignment can do permission in any scope
  - Boundary restricts: user has `*.*` but boundary on scope limits to `posts.*`, user cannot do `members.invite` in that scope
  - Boundary allows: user has `posts.create` and boundary includes `posts.*`, user can do `posts.create`
  - No assignment = deny
- [ ] All tests use Pest syntax with `it()` or `test()` blocks
- [ ] All tests use `RefreshDatabase` trait (or equivalent for package testing)

---

### US-032: DX feature tests — middleware, blade, documents, commands
**Priority:** 32
**Description:** As a developer, I need comprehensive tests for the DX layer to ensure all user-facing surfaces work correctly.

**Acceptance Criteria:**
- [ ] `tests/Feature/DocumentRoundTripTest.php` — tests that create permissions/roles/assignments/boundaries, export to JSON, clear the database, import the JSON, and verify the state matches. Covers: full round-trip, scoped export/import, partial document import (roles only)
- [ ] `tests/Feature/CacheInvalidationTest.php` — tests that verify cache is invalidated when assignments change, roles change, or permissions are deleted. Verify that after invalidation, subsequent `canDo()` checks reflect the updated state
- [ ] All existing test files from prior stories should pass
- [ ] Run `vendor/bin/pest` — all tests pass with zero failures

---

## Functional Requirements

- FR-1: The package must provide 10 contracts (interfaces) as defined in spec section 2: `PermissionStore`, `RoleStore`, `AssignmentStore`, `BoundaryStore`, `Evaluator`, `Matcher`, `ScopeResolver`, `DocumentParser`, `DocumentImporter`, `DocumentExporter`
- FR-2: The package must ship one default implementation for every contract, all swappable via Laravel's service container
- FR-3: Permissions are dot-notated strings (e.g., `posts.create`, `posts.delete.own`). Deny rules are prefixed with `!`
- FR-4: Roles are named collections of permissions with an optional `is_system` flag. System roles are protected from deletion when `protect_system_roles` config is `true`
- FR-5: Assignments are polymorphic links between any subject model, a role, and an optional scope. The unique constraint is on `(subject_type, subject_id, role_id, scope)`
- FR-6: Scopes are `type::id` strings (e.g., `group::5`). Models implement the `Scopeable` trait to produce scope strings
- FR-7: Boundaries define maximum permission ceilings per scope. If a boundary exists for a scope, only permissions matching the boundary's `max_permissions` are allowed
- FR-8: Wildcards (`posts.*`, `*.*`, `*.create`) are supported in permissions, roles, and boundaries
- FR-9: Evaluation order: resolve assignments → resolve roles → collect permissions → check boundary → deny wins → allow/deny result
- FR-10: The `HasPermissions` trait adds `canDo()`, `cannotDo()`, `assign()`, `revoke()`, `assignments()`, `roles()`, `effectivePermissions()`, and `explain()` to any model
- FR-11: The `Primitives` facade provides `permissions()`, `role()`, `boundary()`, `import()`, `export()`, `exportToFile()` for seeding and runtime management
- FR-12: Middleware `can_do` checks a permission (with optional scope from route parameter). Middleware `role` checks for role assignment (with optional scope)
- FR-13: Blade directives `@canDo`, `@cannotDo`, `@hasRole` conditionally render content based on authorization
- FR-14: Policy documents are JSON with optional sections: `permissions`, `roles`, `assignments`, `boundaries`. Import supports merge/replace modes, dry run, and skip-assignments
- FR-15: Export produces a `PolicyDocument` for the full state or scoped subset. Serialize to JSON via `DocumentParser`
- FR-16: Eight Artisan commands: `primitives:permissions`, `primitives:roles`, `primitives:assignments`, `primitives:explain`, `primitives:import`, `primitives:export`, `primitives:validate`, `primitives:sync`, `primitives:cache-clear`
- FR-17: Events are dispatched for all mutations: `PermissionCreated`, `PermissionDeleted`, `RoleCreated`, `RoleUpdated`, `RoleDeleted`, `AssignmentCreated`, `AssignmentRevoked`, `AuthorizationDenied`, `DocumentImported`
- FR-18: Cache is event-driven — the `CachedEvaluator` listens for mutation events and invalidates affected cache entries
- FR-19: Sanctum token abilities are intersected with role-based permissions when a Sanctum token is present
- FR-20: All PHP code uses full 8.4+ type hints and return types. No `compact()`, no `DB::` facade, no `event()` helper

## Non-Goals

- No UI components, dashboard, or admin panel — this is a backend package only
- No REST API endpoints — the package provides the building blocks, the consuming app builds its own API
- No multi-tenancy isolation at the database level — scopes handle logical separation
- No YAML/TOML document parsers — only JSON ships by default (others are custom implementations)
- No automatic role hierarchy or inheritance — roles are flat collections of permissions
- No permission grouping or categorization beyond dot-notation prefixes
- No database support beyond what Laravel's migrations handle (no raw SQL, no DB-specific features)
- No queue/job integration for imports — imports are synchronous
- No approval workflow for document imports — that's a custom `DocumentImporter` implementation
- No Livewire, Inertia, or frontend framework integration

## Technical Considerations

- **Package testing:** Use Orchestra Testbench for testing Laravel packages outside a full app. Configure `TestCase` to load the service provider, run migrations, and set up an in-memory SQLite database
- **Namespace:** `DynamikDev\PolicyEngine\` with sub-namespaces: `Contracts\`, `Stores\`, `Models\`, `Concerns\`, `Events\`, `Facades\`, `Middleware\`, `Commands\`, `DTOs\`, `Documents\`, `Evaluators\`, `Matchers\`, `Resolvers\`, `Listeners\`, `Support\`
- **Polymorphic morphs:** The `assignments` table uses Laravel's `morphs()` which creates `subject_type` (string) and `subject_id` (unsigned big int) columns. The `getMorphClass()` method on models returns the morph alias
- **Cache key strategy:** Use `policy-engine:` prefix for all cache keys. Invalidation should be surgical when possible (flush specific subject keys) but may flush broader when role/permission changes affect unknown subjects
- **Config publishing:** Config file is `config/policy-engine.php` with key `policy-engine`
- **Migration publishing:** Use `$this->publishesMigrations()` (Laravel 11+ method) so migrations are published with timestamps
- **No `compact()`:** Per project style rules, use explicit arrays `['key' => $value]` instead
- **No `DB::` facade:** Use `Model::query()->...` for all database operations
- **No `event()` helper:** Use `Event::dispatch(new EventClass(...))` for all event dispatching
- **Pint:** Run `vendor/bin/pint --dirty` after modifying PHP files

## Success Metrics

- All 10 contracts have exactly one default implementation bound in the service provider
- `vendor/bin/pest` passes with 100% of tests green
- A test User model with `HasPermissions` can: register permissions, create roles, assign roles (globally and scoped), check permissions via `canDo()`, and get denied by boundaries — all in a single integration test
- Document import/export round-trip produces identical state (export → clear → import → export → compare)
- Cache invalidation: changing a role's permissions immediately affects `canDo()` results on the next check (no stale cache)
- Any contract can be rebound in a test service provider and the DX layer (traits, middleware, blade) continues working with the new implementation
- `vendor/bin/pint --dirty` produces no changes (code is already formatted)

## Open Questions

- Should the `explain()` output include timing information for performance debugging?
- Should document import support a `--prune` flag that removes permissions/roles not in the document?
- Should the package ship a `HasPermissions` contract (interface) in addition to the trait, for type-hinting in app code?
- Should `effectivePermissions()` return expanded permissions (resolve wildcards) or the raw permission strings from roles?
- What is the behavior when a subject has the same role assigned both globally and in a scope — are permissions additive or does scoped override global?
