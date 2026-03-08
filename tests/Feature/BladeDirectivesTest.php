<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A minimal authenticatable model for testing Blade directives.
 */
class BladeTestUser extends Authenticatable
{
    use HasPermissions;

    protected $table = 'blade_test_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('blade_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    // Bind all contracts to concrete implementations.
    $assignmentStore = new EloquentAssignmentStore;
    $roleStore = new EloquentRoleStore;
    $boundaryStore = new EloquentBoundaryStore;
    $matcher = new WildcardMatcher;
    $scopeResolver = new ModelScopeResolver;
    $evaluator = new DefaultEvaluator(
        assignments: $assignmentStore,
        roles: $roleStore,
        boundaries: $boundaryStore,
        matcher: $matcher,
    );

    app()->instance(AssignmentStore::class, $assignmentStore);
    app()->instance(RoleStore::class, $roleStore);
    app()->instance(BoundaryStore::class, $boundaryStore);
    app()->instance(Matcher::class, $matcher);
    app()->instance(ScopeResolver::class, $scopeResolver);
    app()->instance(Evaluator::class, $evaluator);

    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = $roleStore;
    $this->assignmentStore = $assignmentStore;

    $this->user = BladeTestUser::query()->create(['name' => 'Alice']);
});

afterEach(function (): void {
    Schema::dropIfExists('blade_test_users');
});

/**
 * Render a Blade template string and return the trimmed output.
 */
function renderBlade(string $template, array $data = []): string
{
    return trim(Blade::render($template, $data));
}

// --- @canDo ---

it('renders content inside @canDo when user has the permission', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @canDo("posts.create")
            VISIBLE
        @endcanDo
        BLADE);

    expect($html)->toBe('VISIBLE');
});

it('hides content inside @canDo when user lacks the permission', function (): void {
    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->user->assign('viewer');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @canDo("posts.delete")
            HIDDEN
        @endcanDo
        BLADE);

    expect($html)->toBe('');
});

it('renders content inside @canDo with scope when user has scoped permission', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @canDo("posts.create", "team::5")
            SCOPED
        @endcanDo
        BLADE);

    expect($html)->toBe('SCOPED');
});

it('hides content inside @canDo with scope when user lacks scoped permission', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @canDo("posts.create", "team::99")
            HIDDEN
        @endcanDo
        BLADE);

    expect($html)->toBe('');
});

// --- @cannotDo ---

it('renders content inside @cannotDo when user lacks the permission', function (): void {
    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @cannotDo("posts.delete")
            DENIED
        @endcannotDo
        BLADE);

    expect($html)->toBe('DENIED');
});

it('hides content inside @cannotDo when user has the permission', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @cannotDo("posts.create")
            HIDDEN
        @endcannotDo
        BLADE);

    expect($html)->toBe('');
});

it('renders content inside @cannotDo with scope when user lacks scoped permission', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @cannotDo("posts.create", "team::99")
            NO_ACCESS
        @endcannotDo
        BLADE);

    expect($html)->toBe('NO_ACCESS');
});

// --- @hasRole ---

it('renders content inside @hasRole when user has the role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @hasRole("editor")
            IS_EDITOR
        @endhasRole
        BLADE);

    expect($html)->toBe('IS_EDITOR');
});

it('hides content inside @hasRole when user lacks the role', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @hasRole("admin")
            IS_ADMIN
        @endhasRole
        BLADE);

    expect($html)->toBe('');
});

it('renders content inside @hasRole with scope when user has the scoped role', function (): void {
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @hasRole("team-editor", "team::5")
            SCOPED_ROLE
        @endhasRole
        BLADE);

    expect($html)->toBe('SCOPED_ROLE');
});

it('hides content inside @hasRole with scope when user lacks the scoped role', function (): void {
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::5');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @hasRole("team-editor", "team::99")
            HIDDEN
        @endhasRole
        BLADE);

    expect($html)->toBe('');
});

it('hides content inside @hasRole when user has role globally but scope is requested', function (): void {
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @hasRole("editor", "team::5")
            HIDDEN
        @endhasRole
        BLADE);

    expect($html)->toBe('');
});

it('hides content inside @hasRole when user has role only in a scope and no scope is requested', function (): void {
    $this->roleStore->save('admin', 'Admin', ['posts.create']);
    $this->user->assign('admin', 'team::5');

    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @hasRole("admin")
            HIDDEN
        @endhasRole
        BLADE);

    expect($html)->toBe('');
});

// --- Unauthenticated user ---

it('hides @canDo content for unauthenticated user', function (): void {
    $html = renderBlade(<<<'BLADE'
        @canDo("posts.create")
            HIDDEN
        @endcanDo
        BLADE);

    expect($html)->toBe('');
});

it('hides @cannotDo content for unauthenticated user', function (): void {
    $html = renderBlade(<<<'BLADE'
        @cannotDo("posts.create")
            HIDDEN
        @endcannotDo
        BLADE);

    expect($html)->toBe('');
});

it('hides @hasRole content for unauthenticated user', function (): void {
    $html = renderBlade(<<<'BLADE'
        @hasRole("editor")
            HIDDEN
        @endhasRole
        BLADE);

    expect($html)->toBe('');
});

// --- @else support ---

it('supports @else with @canDo directive', function (): void {
    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @canDo("posts.delete")
            ALLOWED
        @else
            FALLBACK
        @endcanDo
        BLADE);

    expect($html)->toBe('FALLBACK');
});

it('supports @else with @hasRole directive', function (): void {
    $this->actingAs($this->user);

    $html = renderBlade(<<<'BLADE'
        @hasRole("admin")
            ADMIN
        @else
            NOT_ADMIN
        @endhasRole
        BLADE);

    expect($html)->toBe('NOT_ADMIN');
});
