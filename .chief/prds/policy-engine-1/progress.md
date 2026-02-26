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
