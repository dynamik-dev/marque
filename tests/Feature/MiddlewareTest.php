<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Concerns\Scopeable;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A minimal authenticatable model for testing middleware.
 */
class MiddlewareTestUser extends Authenticatable
{
    use HasPermissions;

    protected $table = 'middleware_test_users';

    protected $guarded = [];
}

/**
 * A scopeable model for testing middleware scope resolution.
 */
class MiddlewareTestTeam extends Model
{
    use Scopeable;

    protected $table = 'middleware_test_teams';

    protected $guarded = [];

    protected string $scopeType = 'team';
}

beforeEach(function (): void {
    Schema::create('middleware_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('middleware_test_teams', function (Blueprint $table): void {
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

    $this->user = MiddlewareTestUser::query()->create(['name' => 'Alice']);
    $this->team = MiddlewareTestTeam::query()->create(['name' => 'Engineering']);
});

afterEach(function (): void {
    Schema::dropIfExists('middleware_test_teams');
    Schema::dropIfExists('middleware_test_users');
});

// --- CanDoMiddleware: allows ---

it('can_do middleware allows request when user has the permission', function (): void {
    Route::middleware('can_do:posts.create')->get('/test', fn () => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

// --- CanDoMiddleware: denies ---

it('can_do middleware denies request with 403 when user lacks the permission', function (): void {
    Route::middleware('can_do:posts.delete')->get('/test', fn () => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->user->assign('viewer');

    $this->actingAs($this->user)
        ->getJson('/test')
        ->assertForbidden();
});

// --- CanDoMiddleware: unauthenticated ---

it('can_do middleware returns 401 for unauthenticated user', function (): void {
    Route::middleware('can_do:posts.create')->get('/test', fn () => response()->json(['ok' => true]));

    $this->getJson('/test')
        ->assertUnauthorized();
});

// --- CanDoMiddleware: with scope parameter (string) ---

it('can_do middleware allows request with scope from route parameter string', function (): void {
    Route::middleware('can_do:posts.create,scope')
        ->get('/test/{scope}', fn (string $scope) => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::'.$this->team->getKey());

    $this->actingAs($this->user)
        ->getJson('/test/team::'.$this->team->getKey())
        ->assertOk();
});

it('can_do middleware denies request when user lacks permission in scope', function (): void {
    Route::middleware('can_do:posts.create,scope')
        ->get('/test/{scope}', fn (string $scope) => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::999');

    $this->actingAs($this->user)
        ->getJson('/test/team::'.$this->team->getKey())
        ->assertForbidden();
});

// --- CanDoMiddleware: with scope parameter (route model binding) ---

it('can_do middleware resolves scope from route model binding', function (): void {
    Route::middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class, 'can_do:posts.create,team'])
        ->get('/test/{team}', fn (MiddlewareTestTeam $team) => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::'.$this->team->getKey());

    $this->actingAs($this->user)
        ->getJson('/test/'.$this->team->getKey())
        ->assertOk();
});

// --- RoleMiddleware: allows ---

it('role middleware allows request when user has the role', function (): void {
    Route::middleware('role:editor')->get('/test', fn () => response()->json(['ok' => true]));

    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

// --- RoleMiddleware: denies ---

it('role middleware denies request with 403 when user lacks the role', function (): void {
    Route::middleware('role:admin')->get('/test', fn () => response()->json(['ok' => true]));

    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test')
        ->assertForbidden();
});

it('role middleware requires a global assignment when no scope is provided', function (): void {
    Route::middleware('role:admin')->get('/global-test', fn () => response()->json(['ok' => true]));

    $this->roleStore->save('admin', 'Admin', ['posts.create']);
    $this->user->assign('admin', 'team::'.$this->team->getKey());

    $this->actingAs($this->user)
        ->getJson('/global-test')
        ->assertForbidden();
});

// --- RoleMiddleware: unauthenticated ---

it('role middleware returns 401 for unauthenticated user', function (): void {
    Route::middleware('role:editor')->get('/test', fn () => response()->json(['ok' => true]));

    $this->getJson('/test')
        ->assertUnauthorized();
});

// --- RoleMiddleware: with scope parameter ---

it('role middleware allows request when user has the role in scope', function (): void {
    Route::middleware('role:team-editor,scope')
        ->get('/test/{scope}', fn (string $scope) => response()->json(['ok' => true]));

    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::'.$this->team->getKey());

    $this->actingAs($this->user)
        ->getJson('/test/team::'.$this->team->getKey())
        ->assertOk();
});

it('role middleware denies request when user lacks the role in scope', function (): void {
    Route::middleware('role:team-editor,scope')
        ->get('/test/{scope}', fn (string $scope) => response()->json(['ok' => true]));

    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::999');

    $this->actingAs($this->user)
        ->getJson('/test/team::'.$this->team->getKey())
        ->assertForbidden();
});

it('role middleware checks only scoped assignments when scope is provided', function (): void {
    Route::middleware('role:editor,scope')
        ->get('/test/{scope}', fn (string $scope) => response()->json(['ok' => true]));

    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    // Assign globally but not in specific scope.
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test/team::'.$this->team->getKey())
        ->assertForbidden();
});

// --- RoleMiddleware: with route model binding ---

it('role middleware resolves scope from route model binding', function (): void {
    Route::middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class, 'role:team-editor,team'])
        ->get('/test/{team}', fn (MiddlewareTestTeam $team) => response()->json(['ok' => true]));

    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::'.$this->team->getKey());

    $this->actingAs($this->user)
        ->getJson('/test/'.$this->team->getKey())
        ->assertOk();
});
