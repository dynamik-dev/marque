# Laravel Policy Engine

**Package:** `dynamik-dev/laravel-policy-engine`
**Spec:** See [spec.md](./spec.md) for the full specification.
**Requires:** Laravel 11+, PHP 8.4+

## What This Is

A Laravel-native scoped permissions package. Composable, contract-driven authorization that integrates with Gates, Policies, middleware, and Blade. Everything is coded to an interface — swap any implementation without touching the DX layer.

## Agent Routing

**All development tasks go to the `laravel-agent`.** This includes writing code, creating migrations, building tests, refactoring, and reviewing. Invoke it via the Task tool for any implementation work.

## Key Concepts

- **Permissions** — dot-notated strings (`posts.create`, `posts.delete.own`). Deny rules prefixed with `!`.
- **Roles** — named collections of permissions. Can be system-locked.
- **Assignments** — polymorphic link between a subject (any model), a role, and an optional scope.
- **Scopes** — `type::id` strings (e.g., `group::5`). Models use the `Scopeable` trait; raw strings are storage-only.
- **Boundaries** — max permission ceilings per scope. Checked before allow/deny.
- **Wildcards** — `posts.*`, `*.*` for verb, resource, and scope matching.
- **Policy Documents** — portable JSON for import/export/versioning of authorization config.

## Architecture (3 Layers)

1. **DX Layer** — traits (`HasPermissions`, `Scopeable`), middleware (`can_do`, `role`), Blade directives (`@canDo`, `@hasRole`), Artisan commands, `Primitives` facade. Contains no logic — delegates to contracts.
2. **Contracts** — `PermissionStore`, `RoleStore`, `AssignmentStore`, `BoundaryStore`, `Evaluator`, `Matcher`, `ScopeResolver`, `DocumentParser`, `DocumentImporter`, `DocumentExporter`.
3. **Default Implementations** — Eloquent stores, `CachedEvaluator`, `WildcardMatcher`, `ModelScopeResolver`, `JsonDocumentParser`. All swappable via service container bindings.

## Database Tables (Default Eloquent Implementation)

- `permissions` — string PK (`posts.create`)
- `roles` — string PK, `is_system` flag
- `role_permissions` — composite PK (`role_id`, `permission_id`)
- `assignments` — polymorphic `subject`, `role_id`, nullable `scope`, unique constraint on all four
- `boundaries` — unique `scope`, JSON `max_permissions`

## Evaluation Order

Assignments → Roles → Permissions → Boundary check → Deny wins → Allow/Deny result.

## Package Namespace

`DynamikDev\PolicyEngine\` — contracts in `DynamikDev\PolicyEngine\Contracts\`, default implementations in `DynamikDev\PolicyEngine\Stores\`, traits in `DynamikDev\PolicyEngine\Concerns\`, events in `DynamikDev\PolicyEngine\Events\`, facade in `DynamikDev\PolicyEngine\Facades\`.

## Testing

- Use Pest for all tests
- Feature tests preferred over unit tests
- Test through contracts, not concrete implementations
- Cover: permission matching (wildcards, deny), scoped evaluation, boundary enforcement, cache invalidation, document import/export round-trips

## Style Rules

- No `compact()` — explicit arrays only
- No `DB::` facade — use `Model::query()`
- No `event()` helper — use static dispatch (`Event::dispatch()`)
- API Resources for all responses, Form Requests for all validation
- Inline single-use variables; extract complex logic to helper methods
- Full PHP 8.4+ type hints and return types on every method
- Run `vendor/bin/pint --dirty` after modifying PHP files
