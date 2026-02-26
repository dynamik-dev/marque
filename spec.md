# Policy Primitives for Laravel

**Version:** 0.2.0
**Status:** Draft
**Requires:** Laravel 10+, PHP 8.2+
**Package:** `policy-primitives/laravel`

A Laravel-native implementation of the [Policy Primitives Spec](./policy-primitives-spec.md). Scoped, composable permissions that integrate with Gates, Policies, middleware, and Blade. Everything is coded to an interface. The package ships default implementations — swap any part without touching the rest.

---

## 1. Architecture

```
┌────────────────────────────────────────────────────────────────┐
│ DX Layer (traits, middleware, blade, artisan, facade)          │
│   The "dumb skeleton" — delegates everything to contracts      │
└──────────────────┬─────────────────────────────────────────────┘
                   │
┌──────────────────▼─────────────────────────────────────────────┐
│ Contracts (interfaces)                                         │
│   PermissionStore, RoleStore, AssignmentStore, BoundaryStore   │
│   Evaluator, Matcher, ScopeResolver, DocumentParser            │
└──────────────────┬─────────────────────────────────────────────┘
                   │
┌──────────────────▼─────────────────────────────────────────────┐
│ Default Implementations                                        │
│   Eloquent stores, cached evaluator, regex matcher,            │
│   model scope resolver, JSON document parser                   │
│   → all swappable via service container                        │
└────────────────────────────────────────────────────────────────┘
```

The package is a skeleton that wires contracts to DX surfaces. The default implementations use Eloquent and Laravel's cache. Swap the store to Redis, Postgres views, an API call, or a flat file — the traits, middleware, and Blade directives don't change.

---

## 2. Contracts

Every meaningful behavior is behind an interface. The package ships one default implementation per contract.

### Core Stores

```php
namespace PolicyPrimitives\Contracts;

interface PermissionStore
{
    /** Register one or more permission primitives. Idempotent. */
    public function register(string|array $permissions): void;

    /** Remove a permission. Cascades to role_permissions. */
    public function remove(string $id): void;

    /** List all permissions, optionally filtered by prefix. */
    public function all(?string $prefix = null): Collection;

    /** Check if a permission ID is registered. */
    public function exists(string $id): bool;
}

interface RoleStore
{
    /** Create or update a role. */
    public function save(string $id, string $name, array $permissions, bool $system = false): Role;

    /** Delete a role. Cascades assignments. */
    public function remove(string $id): void;

    /** Get a role by ID. */
    public function find(string $id): ?Role;

    /** List all roles. */
    public function all(): Collection;

    /** Get permission strings for a role. */
    public function permissionsFor(string $roleId): array;
}

interface AssignmentStore
{
    /** Assign a role to a subject, optionally scoped. */
    public function assign(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void;

    /** Revoke a role from a subject. */
    public function revoke(string $subjectType, string|int $subjectId, string $roleId, ?string $scope = null): void;

    /** Get all assignments for a subject. */
    public function forSubject(string $subjectType, string|int $subjectId): Collection;

    /** Get all assignments for a subject in a specific scope. */
    public function forSubjectInScope(string $subjectType, string|int $subjectId, string $scope): Collection;

    /** Get all subjects assigned a role in a scope. */
    public function subjectsInScope(string $scope, ?string $roleId = null): Collection;
}

interface BoundaryStore
{
    /** Set a permission boundary for a scope. */
    public function set(string $scope, array $maxPermissions): void;

    /** Remove a boundary. */
    public function remove(string $scope): void;

    /** Get the boundary for a scope, or null. */
    public function find(string $scope): ?Boundary;
}
```

### Evaluation

```php
interface Evaluator
{
    /**
     * The core authorization check.
     * Resolves assignments → roles → permissions → boundaries → deny/allow.
     */
    public function can(string $subjectType, string|int $subjectId, string $permission): bool;

    /**
     * Returns the full evaluation trace for debugging.
     * Implementations MAY throw if disabled in config.
     */
    public function explain(string $subjectType, string|int $subjectId, string $permission): EvaluationTrace;

    /**
     * Returns the resolved set of effective permissions for a subject.
     * Useful for caching and UI rendering (e.g., checkbox states).
     */
    public function effectivePermissions(string $subjectType, string|int $subjectId, ?string $scope = null): array;
}

interface Matcher
{
    /**
     * Does a granted permission string cover a required permission string?
     * Handles wildcards, scope matching, and specificity.
     */
    public function matches(string $granted, string $required): bool;
}

interface ScopeResolver
{
    /**
     * Resolve a scope parameter into a scope string.
     * Accepts: Scopeable model, string, or null.
     */
    public function resolve(mixed $scope): ?string;
}
```

### Documents

```php
interface DocumentParser
{
    /** Parse a policy document into a structured PolicyDocument DTO. */
    public function parse(string $content): PolicyDocument;

    /** Serialize a PolicyDocument back to string format. */
    public function serialize(PolicyDocument $document): string;

    /** Validate document content without importing. Returns validation errors. */
    public function validate(string $content): ValidationResult;
}

interface DocumentImporter
{
    /** Import a policy document. Merges with existing state by default. */
    public function import(PolicyDocument $document, ImportOptions $options): ImportResult;
}

interface DocumentExporter
{
    /** Export current state as a PolicyDocument, optionally scoped. */
    public function export(?string $scope = null): PolicyDocument;
}
```

---

## 3. Default Implementations

| Contract           | Default                   | Description                                    |
| ------------------ | ------------------------- | ---------------------------------------------- |
| `PermissionStore`  | `EloquentPermissionStore` | Reads/writes the `permissions` table           |
| `RoleStore`        | `EloquentRoleStore`       | Reads/writes `roles` + `role_permissions`      |
| `AssignmentStore`  | `EloquentAssignmentStore` | Reads/writes `assignments` (polymorphic)       |
| `BoundaryStore`    | `EloquentBoundaryStore`   | Reads/writes `boundaries`                      |
| `Evaluator`        | `CachedEvaluator`         | Wraps `DefaultEvaluator` with Laravel cache    |
| `Matcher`          | `WildcardMatcher`         | Regex-based wildcard and specificity matching  |
| `ScopeResolver`    | `ModelScopeResolver`      | Resolves Scopeable models, strings, and null   |
| `DocumentParser`   | `JsonDocumentParser`      | Parses/serializes JSON policy documents        |
| `DocumentImporter` | `DefaultDocumentImporter` | Validates + applies policy documents to stores |
| `DocumentExporter` | `DefaultDocumentExporter` | Reads from stores + builds PolicyDocument      |

### Swapping an implementation

```php
// AppServiceProvider or a dedicated provider
use PolicyPrimitives\Contracts\PermissionStore;
use App\Auth\RedisPermissionStore;

public function register(): void
{
    $this->app->bind(PermissionStore::class, RedisPermissionStore::class);
}
```

Everything upstream — traits, middleware, Blade, Artisan commands — continues working because it only touches contracts.

### Decorating (not replacing)

```php
// Add audit logging without replacing the store
use PolicyPrimitives\Contracts\AssignmentStore;
use PolicyPrimitives\Stores\EloquentAssignmentStore;

$this->app->bind(AssignmentStore::class, function ($app) {
    return new AuditingAssignmentStore(
        inner: $app->make(EloquentAssignmentStore::class),
        logger: $app->make(AuditLogger::class),
    );
});
```

---

## 4. Configuration

```php
// config/primitives.php

return [

    // Cache settings for resolved permission sets
    'cache' => [
        'enabled' => true,
        'store' => env('PRIMITIVES_CACHE_STORE', 'default'),
        'ttl' => 60 * 60, // 1 hour, invalidated on change regardless
    ],

    // Lock system roles from runtime modification
    'protect_system_roles' => true,

    // Dispatch AuthorizationDenied events on failed checks
    'log_denials' => true,

    // Enable explain() traces (disable in production)
    'explain' => env('PRIMITIVES_EXPLAIN', false),

    // Policy document format for import/export
    'document_format' => 'json', // registered DocumentParser to use
];
```

No `subject_model` config. Any model with the `HasPermissions` trait is a subject. The polymorphic `assignments` table handles identity.

---

## 5. Migrations

```php
// create_permissions_table
Schema::create('permissions', function (Blueprint $table) {
    $table->string('id')->primary();          // "posts.create"
    $table->string('description')->nullable();
    $table->timestamps();
});

// create_roles_table
Schema::create('roles', function (Blueprint $table) {
    $table->string('id')->primary();          // "moderator"
    $table->string('name');                   // "Moderator"
    $table->boolean('is_system')->default(false);
    $table->timestamps();
});

// create_role_permissions_table
Schema::create('role_permissions', function (Blueprint $table) {
    $table->string('role_id');
    $table->string('permission_id');          // Can include deny: "!posts.delete"
    $table->primary(['role_id', 'permission_id']);
    $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
});

// create_assignments_table
Schema::create('assignments', function (Blueprint $table) {
    $table->id();
    $table->morphs('subject');                // Polymorphic: user, team, token
    $table->string('role_id');
    $table->string('scope')->nullable();      // "group::5" — null means global
    $table->timestamps();

    $table->unique(['subject_id', 'subject_type', 'role_id', 'scope']);
    $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    $table->index('scope');
});

// create_boundaries_table (optional)
Schema::create('boundaries', function (Blueprint $table) {
    $table->id();
    $table->string('scope')->unique();        // "org::acme"
    $table->json('max_permissions');           // ["posts.*", "comments.*"]
    $table->timestamps();
});
```

These are the default Eloquent implementation's tables. A custom store (Redis, Postgres views, API-backed) may not need them at all.

---

## 6. Traits

### `HasPermissions` — for subjects

```php
use PolicyPrimitives\Concerns\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions;
}
```

The trait delegates to contracts resolved from the container. It holds no logic itself.

```php
// Assignment (delegates to AssignmentStore)
$user->assign('moderator', scope: $group);
$user->assign('member');
$user->revoke('moderator', scope: $group);

// Permission checks (delegates to Evaluator)
$user->canDo('posts.delete', scope: $group);
$user->canDo('posts.create');
$user->cannotDo('posts.delete', scope: $group);

// Introspection (delegates to AssignmentStore + Evaluator)
$user->assignments();
$user->assignmentsFor(scope: $group);
$user->effectivePermissions(scope: $group);
$user->roles();
$user->rolesFor(scope: $group);

// Debug (delegates to Evaluator)
$user->explain('posts.delete', scope: $group);
```

Internally:

```php
trait HasPermissions
{
    public function canDo(string $permission, mixed $scope = null): bool
    {
        $resolver = app(ScopeResolver::class);
        $evaluator = app(Evaluator::class);

        $resolved = $resolver->resolve($scope);
        $full = $resolved ? "{$permission}:{$resolved}" : $permission;

        return $evaluator->can(
            subjectType: $this->getMorphClass(),
            subjectId: $this->getKey(),
            permission: $full,
        );
    }

    public function assign(string $roleId, mixed $scope = null): void
    {
        $resolver = app(ScopeResolver::class);
        $store = app(AssignmentStore::class);

        $store->assign(
            subjectType: $this->getMorphClass(),
            subjectId: $this->getKey(),
            roleId: $roleId,
            scope: $resolver->resolve($scope),
        );
    }

    // ... same delegation pattern for all methods
}
```

The trait is glue. Replace the `Evaluator` binding and every `canDo()` call across the app uses the new implementation.

### `Scopeable` — for models that act as scopes

```php
use PolicyPrimitives\Concerns\Scopeable;

class Group extends Model
{
    use Scopeable;

    protected string $scopeType = 'group';
}
```

```php
$group->toScope();                      // "group::5"
$group->members();                      // Subjects with any role here
$group->membersWithRole('moderator');   // Subjects with this role here
```

`Scopeable` delegates membership queries to `AssignmentStore::subjectsInScope()`.

---

## 7. Seeding

```php
use PolicyPrimitives\Facades\Primitives;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        Primitives::permissions([
            'posts.read',
            'posts.create',
            'posts.update.own',
            'posts.update.any',
            'posts.delete.own',
            'posts.delete.any',
            'posts.delete.pinned',
            'posts.report',
            'comments.read',
            'comments.create',
            'comments.update.own',
            'comments.delete.own',
            'comments.delete.any',
            'members.invite',
            'members.remove',
            'members.role.assign',
            'roles.create',
            'roles.update',
            'roles.delete',
        ]);

        Primitives::role('viewer', 'Viewer', system: true)
            ->grant(['posts.read', 'comments.read']);

        Primitives::role('member', 'Member', system: true)
            ->grant([
                'posts.read', 'posts.create', 'posts.update.own', 'posts.delete.own',
                'comments.read', 'comments.create', 'comments.update.own', 'comments.delete.own',
            ]);

        Primitives::role('moderator', 'Moderator', system: true)
            ->grant([
                'posts.read', 'posts.create', 'posts.update.any',
                'posts.delete.any', 'posts.delete.pinned',
                'comments.read', 'comments.create', 'comments.update.any', 'comments.delete.any',
                'members.invite', 'members.remove',
            ]);

        Primitives::role('admin', 'Admin', system: true)
            ->grant(['*.*']);
    }
}
```

The `Primitives` facade delegates to `PermissionStore` and `RoleStore`. Seeding is idempotent.

---

## 8. Policy Documents

Portable JSON documents for defining, exporting, versioning, and sharing authorization configurations.

### Document Format

```json
{
  "version": "1.0",
  "permissions": [
    "posts.read",
    "posts.create",
    "posts.update.own",
    "posts.update.any",
    "posts.delete.own",
    "posts.delete.any",
    "members.invite",
    "members.remove"
  ],
  "roles": [
    {
      "id": "triage",
      "name": "Triage",
      "permissions": [
        "posts.read",
        "posts.update.any",
        "comments.create",
        "!posts.delete.any"
      ]
    },
    {
      "id": "community-lead",
      "name": "Community Lead",
      "permissions": [
        "posts.*",
        "comments.*",
        "members.invite",
        "!members.remove"
      ]
    }
  ],
  "assignments": [
    {
      "subject": "user::42",
      "role": "triage",
      "scope": "group::alpha"
    },
    {
      "subject": "user::7",
      "role": "community-lead",
      "scope": "group::beta"
    }
  ],
  "boundaries": [
    {
      "scope": "org::acme",
      "max_permissions": ["posts.*", "comments.*"]
    }
  ]
}
```

Every section is optional. A document containing only `roles` is valid — useful for sharing role templates without touching assignments.

### The Pipeline

```
JSON string                          PolicyDocument DTO                    Database
────────────── DocumentParser ──────── ─────────────── DocumentImporter ────────────
               .parse()                                .import()

Database                             PolicyDocument DTO                    JSON string
────────────── DocumentExporter ────── ─────────────── DocumentParser ─────────────
               .export()                               .serialize()
```

Each step is a separate contract. Replace the parser to support YAML or TOML. Replace the importer to add approval workflows. Replace the exporter to filter sensitive assignments.

### PHP Usage

```php
use PolicyPrimitives\Facades\Primitives;

// Import from file
Primitives::import(storage_path('policies/community.json'));

// Import from string
Primitives::import($jsonString);

// Import with options
Primitives::import($jsonString, new ImportOptions(
    validate: true,         // Fail on unknown permissions (default: true)
    merge: true,            // Merge with existing state (default: true, false = replace)
    dryRun: false,          // Preview changes without applying
    skipAssignments: false, // Import roles/permissions only
));

// Dry run — returns what would change without touching the DB
$result = Primitives::import($jsonString, new ImportOptions(dryRun: true));
$result->permissionsCreated;   // ['posts.report']
$result->rolesCreated;         // ['triage']
$result->rolesUpdated;         // ['community-lead']
$result->assignmentsCreated;   // 2
$result->warnings;             // ['Permission "posts.archive" not registered']

// Export everything
$json = Primitives::export();

// Export scoped — only roles/assignments relevant to this scope
$json = Primitives::export(scope: 'group::alpha');

// Export to file
Primitives::exportToFile(storage_path('policies/backup.json'));
Primitives::exportToFile(storage_path('policies/backup.json'), scope: 'org::acme');
```

### Artisan Commands

```bash
# Import a policy document
php artisan primitives:import policies/community.json

# Dry run — preview changes
php artisan primitives:import policies/community.json --dry-run

# Import roles/permissions only, skip assignments
php artisan primitives:import policies/community.json --skip-assignments

# Replace mode — wipe and re-import (dangerous, requires --force)
php artisan primitives:import policies/community.json --replace --force

# Export everything
php artisan primitives:export --path=policies/backup.json

# Export scoped
php artisan primitives:export --scope="group::alpha" --path=policies/group-alpha.json

# Export to stdout (pipe to jq, diff, etc.)
php artisan primitives:export --stdout | jq '.roles[] | .id'

# Validate without importing
php artisan primitives:validate policies/community.json
```

### Custom Document Format

The default parser handles JSON. Register your own for YAML, TOML, or any format:

```php
use PolicyPrimitives\Contracts\DocumentParser;

class YamlDocumentParser implements DocumentParser
{
    public function parse(string $content): PolicyDocument { /* ... */ }
    public function serialize(PolicyDocument $document): string { /* ... */ }
    public function validate(string $content): ValidationResult { /* ... */ }
}

// Register in a service provider
$this->app->bind(DocumentParser::class, YamlDocumentParser::class);
```

### Workflows Enabled by Documents

| Workflow          | How                                                       |
| ----------------- | --------------------------------------------------------- |
| Version control   | Commit JSON documents to git alongside code               |
| CI/CD deploy      | `php artisan primitives:import` in deploy pipeline        |
| Environment sync  | Export from staging, import to production                 |
| Tenant onboarding | Per-plan JSON templates applied on org creation           |
| Role sharing      | Share a role document as a gist or in docs                |
| Auditing          | Diff two exported documents to see what changed           |
| UI role builder   | UI writes JSON, backend imports it via `DocumentImporter` |
| Approval flow     | Custom `DocumentImporter` that queues for admin review    |

---

## 9. Using `canDo()` Directly

For pure permission checks with no business logic, skip policies.

```php
class PostController extends Controller
{
    public function store(Group $group)
    {
        abort_unless(auth()->user()->canDo('posts.create', scope: $group), 403);

        // create post...
    }
}
```

---

## 10. Integrating with Model Policies

When authorization depends on the state of the resource (ownership, timestamps, flags), use policies. The policy delegates permission resolution to `canDo()`.

### When to use a policy

| Scenario                              | Policy?                 |
| ------------------------------------- | ----------------------- |
| Pure permission check                 | No — `canDo()` directly |
| Ownership (`.own` / `.any`)           | Yes                     |
| Time-based rules                      | Yes                     |
| State-based (pinned, archived)        | Yes                     |
| Compound (permission + business rule) | Yes                     |

### Example

```php
class PostPolicy
{
    public function create(User $user, Group $group): bool
    {
        return $user->canDo('posts.create', scope: $group);
    }

    public function update(User $user, Post $post): bool
    {
        $group = $post->group;

        if ($user->canDo('posts.update.any', scope: $group)) {
            return true;
        }

        return $user->canDo('posts.update.own', scope: $group)
            && $post->user_id === $user->id;
    }

    public function delete(User $user, Post $post): bool
    {
        $group = $post->group;

        if ($post->is_pinned) {
            return $user->canDo('posts.delete.pinned', scope: $group);
        }

        if ($user->canDo('posts.delete.any', scope: $group)) {
            return true;
        }

        return $user->canDo('posts.delete.own', scope: $group)
            && $post->user_id === $user->id;
    }
}
```

Controllers use standard Laravel authorization:

```php
$this->authorize('delete', $post);
```

---

## 11. Middleware

### `can_do` — direct permission check from route

```php
Route::middleware('can_do:posts.create,group')
    ->post('/groups/{group}/posts', [PostController::class, 'store']);
```

Resolves `{group}` via route model binding + `ScopeResolver`.

### `role` — require a role in scope

```php
Route::middleware('role:admin,group')
    ->prefix('/groups/{group}/settings')
    ->group(/* ... */);
```

Both middleware delegate to the `Evaluator` and `ScopeResolver` contracts.

---

## 12. Blade Directives

```blade
@canDo('posts.create', $group)
    <button>New Post</button>
@endCanDo

@cannotDo('members.remove', $group)
    <span>Contact an admin to remove members.</span>
@endCannotDo

@hasRole('moderator', $group)
    <a href="{{ route('group.modqueue', $group) }}">Mod Queue</a>
@endHasRole

{{-- Standard @can still works — hits policies --}}
@can('delete', $post)
    <button>Delete Post</button>
@endcan
```

---

## 13. Runtime Role Management

```php
use PolicyPrimitives\Facades\Primitives;

$role = Primitives::role('triage', 'Triage')
    ->grant([
        'posts.read',
        'posts.update.any',
        'comments.create',
        '!posts.delete.any',
    ]);

$user->assign('triage', scope: $group);

// Modify
$role->grant(['posts.report']);
$role->ungrant(['comments.create']);

// Delete (cascades)
$role->remove();
```

All operations go through `RoleStore` and `AssignmentStore`. Events fire for each mutation.

---

## 14. The `explain()` Helper

```php
$trace = $user->explain('posts.delete.pinned', scope: $group);
```

```php
PolicyPrimitives\EvaluationTrace {
    +subject: "App\Models\User:42",
    +required: "posts.delete.pinned:group::5",
    +result: "DENY",
    +assignments: [
        {
            role: "member",
            scope: "group::5",
            permissions_checked: [
                "posts.read" => "no match",
                "posts.create" => "no match",
                "posts.delete.own" => "no match",
            ],
        },
    ],
    +boundary: null,
    +cache_hit: false,
}
```

```bash
php artisan primitives:explain 42 "posts.delete.pinned" --scope="group::5"
```

Delegates to `Evaluator::explain()`. Disabled in production by default.

---

## 15. Artisan Commands

```bash
php artisan primitives:permissions              # List all permissions
php artisan primitives:roles                    # List roles + permissions
php artisan primitives:assignments 42           # Assignments for subject
php artisan primitives:assignments --scope="group::5"

php artisan primitives:explain 42 "posts.delete" --scope="group::5"

php artisan primitives:import policies/file.json [--dry-run] [--skip-assignments] [--replace --force]
php artisan primitives:export [--scope="..."] [--path=...] [--stdout]
php artisan primitives:validate policies/file.json

php artisan primitives:sync                     # Re-run seeder idempotently
php artisan primitives:cache-clear              # Flush permission cache
```

All commands resolve dependencies from the container. A custom `PermissionStore` means `primitives:permissions` lists from that store.

---

## 16. Events

```php
PolicyPrimitives\Events\PermissionCreated     // ->permission
PolicyPrimitives\Events\PermissionDeleted     // ->permission
PolicyPrimitives\Events\RoleCreated           // ->role
PolicyPrimitives\Events\RoleUpdated           // ->role, ->changes
PolicyPrimitives\Events\RoleDeleted           // ->role
PolicyPrimitives\Events\AssignmentCreated     // ->assignment
PolicyPrimitives\Events\AssignmentRevoked     // ->assignment
PolicyPrimitives\Events\AuthorizationDenied   // ->subject, ->permission, ->scope
PolicyPrimitives\Events\DocumentImported      // ->result
```

Events are dispatched by the default store implementations. Custom stores SHOULD dispatch the same events for cache invalidation and observability.

The `EventDispatcher` is injected into stores via constructor — not hardcoded. A store implementation can use its own event mechanism or disable events entirely.

---

## 17. Caching

The default `CachedEvaluator` wraps `DefaultEvaluator` with Laravel's cache:

```
primitives:{morph_type}:{id}              → global effective permissions
primitives:{morph_type}:{id}:{scope}      → scoped effective permissions
```

Cache invalidation is event-driven — the package registers listeners for all mutation events. Custom store implementations that dispatch the standard events get automatic cache invalidation.

To replace the caching strategy entirely, bind your own `Evaluator`:

```php
$this->app->bind(Evaluator::class, MyEvaluator::class);
```

---

## 18. Boundaries

```php
Primitives::boundary('org::acme', [
    'posts.*',
    'comments.*',
    '!members.remove',
]);
```

Boundaries are checked by the `Evaluator` before the allow/deny phases. The default `BoundaryStore` uses Eloquent. Swap it for config-driven boundaries, an API call, or a plan-based lookup.

---

## 19. Sanctum Token Scoping (Optional)

```php
$token = $user->createToken('deploy-bot', abilities: [
    'posts.read:group::5',
    'posts.create:group::5',
]);
```

The `HasPermissions` trait intersects token abilities with role-based permissions when it detects a Sanctum-authenticated request. A token can only exercise permissions that both the token AND the user's role assignments allow.

This behavior is handled by the `Evaluator`. The default `CachedEvaluator` checks for token context. A custom evaluator can ignore, extend, or replace this logic.

---

## 20. Extension Points Summary

| You want to...                       | Swap this contract | Example                                 |
| ------------------------------------ | ------------------ | --------------------------------------- |
| Store permissions in Redis           | `PermissionStore`  | `RedisPermissionStore`                  |
| Store assignments in an external API | `AssignmentStore`  | `ApiAssignmentStore`                    |
| Use a different matching algorithm   | `Matcher`          | `RegexMatcher`, `BitfieldMatcher`       |
| Support YAML policy documents        | `DocumentParser`   | `YamlDocumentParser`                    |
| Add approval workflow for imports    | `DocumentImporter` | `ApprovalQueueImporter`                 |
| Filter exports by tenant             | `DocumentExporter` | `TenantScopedExporter`                  |
| Replace the evaluation engine        | `Evaluator`        | `LazyEvaluator`, `ExternalPolicyEngine` |
| Resolve scopes from DTOs, not models | `ScopeResolver`    | `DtoScopeResolver`                      |
| Add audit logging to any store       | Decorate any store | Wrap with `AuditingStore` decorator     |
| Disable caching                      | `Evaluator`        | Bind `DefaultEvaluator` directly        |

Every customization is a binding. No config flags, no feature toggles, no inheritance chains. Swap the implementation, keep the DX.

---

## 21. Comparison with Spatie

| Concern                              | Spatie                  | Policy Primitives                       |
| ------------------------------------ | ----------------------- | --------------------------------------- |
| Scoped permissions                   | Not supported           | Native, first-class                     |
| Permission format                    | Flat strings            | Structured `resource.verb` with scope   |
| Role assignment scope                | Global only             | Global or scoped to any Scopeable model |
| Deny rules                           | Not supported           | Native, deny wins                       |
| Boundaries                           | Not supported           | Native                                  |
| Wildcards                            | `*` only                | Verb, resource, and scope wildcards     |
| Policy integration                   | Parallel system         | Unified — policies call `canDo()`       |
| Cache invalidation                   | Manual                  | Automatic via events                    |
| Portable config (JSON import/export) | Not supported           | Native                                  |
| Extensibility                        | Extend Eloquent models  | Swap any contract via container         |
| Row scaling                          | O(permissions × scopes) | O(assignments only)                     |

---

## 22. Design Principles

1. **Contracts over concrete.** Every behavior is an interface. The package ships defaults — you ship replacements.
2. **The skeleton is dumb.** Traits, middleware, Blade directives, and Artisan commands contain no logic. They resolve contracts from the container and delegate. Replace the implementation, keep the DX.
3. **Lean into the framework.** Use Gates, Policies, middleware, Blade, and events as Laravel intends. Don't replace them — power them.
4. **Policies for business logic, primitives for permission data.** A policy should never hardcode a role name. A permission check should never contain business rules.
5. **Scope is always a model.** In application code, scopes are Eloquent models with `Scopeable`. Raw strings are for storage and documents.
6. **Documents are portable.** Export, version, share, diff, and import authorization config as JSON. The database is a runtime cache of truth — the document is the source.
7. **Observable.** Every mutation fires a Laravel event. Cache invalidation, audit trails, and webhook dispatch are just listeners.
8. **Zero config for simple apps.** Install, seed, use `canDo()`. Policies, boundaries, documents, custom stores, and token scoping are opt-in.
