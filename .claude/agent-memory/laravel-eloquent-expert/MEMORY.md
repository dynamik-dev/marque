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

## Pint Style Notes
- Uses `concat_space` fixer (no spaces around `.` concatenation operator)
