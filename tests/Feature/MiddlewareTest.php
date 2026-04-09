<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Concerns\Scopeable;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

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
 * A minimal authenticatable model WITHOUT HasPermissions — for fail-closed testing.
 */
class MiddlewareTestUserWithoutTrait extends Authenticatable
{
    protected $table = 'middleware_test_users';

    protected $guarded = [];
}

/**
 * A minimal authenticatable model with HasPermissions AND HasApiTokens for Sanctum middleware testing.
 */
class MiddlewareTestSanctumUser extends Authenticatable
{
    use HasApiTokens;
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

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);

    $this->user = MiddlewareTestUser::query()->create(['name' => 'Alice']);
    $this->team = MiddlewareTestTeam::query()->create(['name' => 'Engineering']);
});

afterEach(function (): void {
    Schema::dropIfExists('middleware_test_teams');
    Schema::dropIfExists('middleware_test_users');
});

// --- can middleware (via Gate hook): allows ---

it('can middleware allows request when user has the permission', function (): void {
    Route::middleware('can:posts.create')->get('/test', fn () => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

// --- can middleware: denies ---

it('can middleware denies request with 403 when user lacks the permission', function (): void {
    Route::middleware('can:posts.delete')->get('/test', fn () => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->user->assign('viewer');

    $this->actingAs($this->user)
        ->getJson('/test')
        ->assertForbidden();
});

// --- can middleware: with scope parameter (route model binding) ---

it('can middleware resolves scope from route model binding', function (): void {
    Route::middleware([SubstituteBindings::class, 'can:posts.create,team'])
        ->get('/test/{team}', fn (MiddlewareTestTeam $team) => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::'.$this->team->getKey());

    $this->actingAs($this->user)
        ->getJson('/test/'.$this->team->getKey())
        ->assertOk();
});

// --- can middleware: denies scoped ---

it('can middleware denies scoped request when user lacks permission in that scope', function (): void {
    Route::middleware([SubstituteBindings::class, 'can:posts.create,team'])
        ->get('/test/{team}', fn (MiddlewareTestTeam $team) => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::999');

    $this->actingAs($this->user)
        ->getJson('/test/'.$this->team->getKey())
        ->assertForbidden();
});

// --- can middleware: fail-closed without HasPermissions trait ---

it('can middleware denies when user model lacks HasPermissions trait', function (): void {
    Route::middleware('can:posts.create')->get('/test', fn () => response()->json(['ok' => true]));

    $user = MiddlewareTestUserWithoutTrait::query()->create(['name' => 'Bob']);

    $this->actingAs($user)
        ->getJson('/test')
        ->assertForbidden();
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

it('role middleware allows request when user has global role and scope is provided', function (): void {
    Route::middleware('role:editor,scope')
        ->get('/test/{scope}', fn (string $scope) => response()->json(['ok' => true]));

    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    // Assign globally but not in specific scope.
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test/team::'.$this->team->getKey())
        ->assertOk();
});

it('role middleware denies request when user lacks the role in both global and scoped context', function (): void {
    Route::middleware('role:admin,scope')
        ->get('/test/{scope}', fn (string $scope) => response()->json(['ok' => true]));

    $this->roleStore->save('admin', 'Admin', ['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.read']);
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test/team::'.$this->team->getKey())
        ->assertForbidden();
});

// --- RoleMiddleware: with route model binding ---

it('role middleware resolves scope from route model binding', function (): void {
    Route::middleware([SubstituteBindings::class, 'role:team-editor,team'])
        ->get('/test/{team}', fn (MiddlewareTestTeam $team) => response()->json(['ok' => true]));

    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->user->assign('team-editor', 'team::'.$this->team->getKey());

    $this->actingAs($this->user)
        ->getJson('/test/'.$this->team->getKey())
        ->assertOk();
});

// --- can middleware: Sanctum token scoping ---

it('can middleware denies when Sanctum token lacks the required ability', function (): void {
    Route::middleware('can:posts.create')->get('/sanctum-deny-test', fn () => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);

    $sanctumUser = MiddlewareTestSanctumUser::query()->create(['name' => 'Token User']);
    $this->assignmentStore->assign($sanctumUser->getMorphClass(), $sanctumUser->getKey(), 'editor');

    $token = new PersonalAccessToken;
    $token->abilities = ['posts.read'];
    $sanctumUser->withAccessToken($token);

    $this->actingAs($sanctumUser)
        ->getJson('/sanctum-deny-test')
        ->assertForbidden();
});

it('can middleware allows when Sanctum token includes the required ability', function (): void {
    Route::middleware('can:posts.create')->get('/sanctum-test', fn () => response()->json(['ok' => true]));

    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);

    $sanctumUser = MiddlewareTestSanctumUser::query()->create(['name' => 'Token User']);
    $this->assignmentStore->assign($sanctumUser->getMorphClass(), $sanctumUser->getKey(), 'editor');

    $token = new PersonalAccessToken;
    $token->abilities = ['posts.create', 'posts.read'];
    $sanctumUser->withAccessToken($token);

    $this->actingAs($sanctumUser)
        ->getJson('/sanctum-test')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

// --- RoleMiddleware: aborts when scope parameter not found in route ---

it('role middleware aborts 403 when scope parameter does not match a route parameter', function (): void {
    Route::middleware('role:editor,nonexistent_param')
        ->get('/test-warn', fn () => response()->json(['ok' => true]));

    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->user->assign('editor');

    $this->actingAs($this->user)
        ->getJson('/test-warn')
        ->assertForbidden();
});
