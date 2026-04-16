<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Resolvers\SanctumPolicyResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

class SanctumTestUser extends Authenticatable
{
    use HasApiTokens;
    use HasPermissions;

    protected $table = 'sanctum_test_users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('sanctum_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
    $this->evaluator = app(Evaluator::class);

    $this->user = SanctumTestUser::create(['name' => 'Sanctum User']);

    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.delete']);
    $this->assignmentStore->assign($this->user->getMorphClass(), $this->user->getKey(), 'editor');
});

afterEach(function (): void {
    Schema::dropIfExists('sanctum_test_users');
});

// --- Sanctum token with matching ability allows ---

it('allows when Sanctum token has a matching ability', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.create'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    expect($this->user->canDo('posts.create'))->toBeTrue();
});

it('allows when Sanctum token has wildcard ability', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['*'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    expect($this->user->canDo('posts.create'))->toBeTrue();
    expect($this->user->canDo('posts.read'))->toBeTrue();
    expect($this->user->canDo('posts.delete'))->toBeTrue();
});

it('allows when Sanctum token ability matches via wildcard pattern', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.*'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    expect($this->user->canDo('posts.create'))->toBeTrue();
    expect($this->user->canDo('posts.read'))->toBeTrue();
    expect($this->user->canDo('posts.delete'))->toBeTrue();
});

// --- Sanctum token without matching ability denies ---

it('denies when Sanctum token does not include the required ability', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.read'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    expect($this->user->canDo('posts.create'))->toBeFalse();
    expect($this->user->canDo('posts.read'))->toBeTrue();
});

it('denies when Sanctum token has empty abilities', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = [];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    expect($this->user->canDo('posts.create'))->toBeFalse();
    expect($this->user->canDo('posts.read'))->toBeFalse();
    expect($this->user->canDo('posts.delete'))->toBeFalse();
});

// --- No Sanctum token (session auth) allows normally ---

it('allows normally when no Sanctum token is present (session auth)', function (): void {
    // No token set — currentAccessToken() returns a transient token (not PersonalAccessToken).
    auth()->login($this->user);

    expect($this->user->canDo('posts.create'))->toBeTrue();
    expect($this->user->canDo('posts.read'))->toBeTrue();
});

it('allows normally when user is not authenticated', function (): void {
    /* When there is no authenticated user, the SanctumPolicyResolver returns empty — verify it injects no Deny statements. */
    $resolver = app(SanctumPolicyResolver::class);

    $request = new EvaluationRequest(
        principal: new Principal(
            type: $this->user->getMorphClass(),
            id: $this->user->getKey(),
        ),
        action: 'posts.create',
        context: new Context,
    );

    $statements = $resolver->resolve($request);

    expect($statements)->toBeEmpty();
});

// --- Cross-user evaluations (auth user != principal) ---

it('does not apply auth user Sanctum token to a different principal', function (): void {
    /* Bug regression: prior implementation read $token from auth()->user() directly,
     * so a CLI/batch evaluator authed as user Y but explaining for user X would
     * silently apply Y's token deny statements to X. The fix restricts Sanctum
     * filtering to evaluations whose principal matches the authenticated user. */
    $otherUser = SanctumTestUser::create(['name' => 'Other User']);

    /* Authed user Y holds a token that would deny posts.create. */
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.read'];
    $otherUser->withAccessToken($token);
    auth()->login($otherUser);

    /* Resolve for user X (no token bound on X). */
    $resolver = app(SanctumPolicyResolver::class);

    $request = new EvaluationRequest(
        principal: new Principal(
            type: $this->user->getMorphClass(),
            id: $this->user->getKey(),
        ),
        action: 'posts.create',
        context: new Context,
    );

    $statements = $resolver->resolve($request);

    expect($statements)->toBeEmpty();
});

it('explain for principal X while authed as Y is not blocked by Y Sanctum token', function (): void {
    /* End-to-end version of the above through the evaluator: explain() for
     * user X must reach decision Allow even though authed user Y holds a
     * narrowly-scoped Sanctum token that does not include posts.create. */
    $otherUser = SanctumTestUser::create(['name' => 'Other User']);

    $token = new PersonalAccessToken;
    $token->abilities = ['posts.read'];
    $otherUser->withAccessToken($token);
    auth()->login($otherUser);

    $request = new EvaluationRequest(
        principal: new Principal(
            type: $this->user->getMorphClass(),
            id: $this->user->getKey(),
        ),
        action: 'posts.create',
        context: new Context,
    );

    $explanation = $this->evaluator->evaluate($request);

    expect($explanation->decision)->toBe(Decision::Allow);
    expect($explanation->decidedBy)->not->toBe('sanctum-token');
});

// --- Colon-syntax abilities are normalized to dot syntax ---

it('does not silently deny when token holds a colon-syntax ability alongside a dot-syntax ability', function (): void {
    /* Sanctum operators commonly issue colon-syntax abilities (`server:read`).
     * The matcher treats `:` as a scope delimiter, so before normalization a
     * colon ability matched no permission and produced a blanket deny. The
     * dot-syntax ability in the same array must still grant its permission. */
    $token = new PersonalAccessToken;
    $token->abilities = ['server:read', 'posts.create'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    expect($this->user->canDo('posts.create'))->toBeTrue();
});

it('normalizes colon-syntax abilities to dot syntax when matching permissions', function (): void {
    /* Register a permission whose id is the dot-notation equivalent of the
     * colon-syntax ability and confirm it is granted. */
    $this->permissionStore->register(['server.read']);
    $this->roleStore->save('ops', 'Ops', ['server.read']);
    $this->assignmentStore->assign($this->user->getMorphClass(), $this->user->getKey(), 'ops');

    $token = new PersonalAccessToken;
    $token->abilities = ['server:read'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    expect($this->user->canDo('server.read'))->toBeTrue();
});

// --- explain() mirrors Sanctum token scoping ---

it('explain reports deny with sanctum note when token lacks required ability', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.read'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    $result = $this->user->explain('posts.create');

    expect($result->decision)->toBe(Decision::Deny);
    expect($result->decidedBy)->toBe('sanctum-token');
});

it('explain reports allow with no sanctum note when token includes required ability', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.create'];
    $this->user->withAccessToken($token);
    auth()->login($this->user);

    $result = $this->user->explain('posts.create');

    expect($result->decision)->toBe(Decision::Allow);
    expect($result->decidedBy)->not->toBe('sanctum-token');
});

it('explain reports allow with no sanctum note when no token is present', function (): void {
    auth()->login($this->user);

    $result = $this->user->explain('posts.create');

    expect($result->decision)->toBe(Decision::Allow);
    expect($result->decidedBy)->not->toBe('sanctum-token');
});
