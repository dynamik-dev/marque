<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\Context;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Resolvers\SanctumPolicyResolver;
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
    // When there is no authenticated user, the SanctumPolicyResolver returns empty.
    // Verify by checking the resolver directly: it should not inject any Deny statements.
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
