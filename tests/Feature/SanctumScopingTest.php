<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
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

    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
    $this->assignmentStore = new EloquentAssignmentStore;
    $this->boundaryStore = new EloquentBoundaryStore;

    $this->evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

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
    $token->abilities = ['posts.create', 'posts.read'];

    $this->user->withAccessToken($token);
    $this->actingAs($this->user);

    expect($this->evaluator->can(
        $this->user->getMorphClass(),
        $this->user->getKey(),
        'posts.create',
    ))->toBeTrue();
});

it('allows when Sanctum token has wildcard ability', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['*'];

    $this->user->withAccessToken($token);
    $this->actingAs($this->user);

    expect($this->evaluator->can(
        $this->user->getMorphClass(),
        $this->user->getKey(),
        'posts.delete',
    ))->toBeTrue();
});

it('allows when Sanctum token ability matches via wildcard pattern', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.*'];

    $this->user->withAccessToken($token);
    $this->actingAs($this->user);

    expect($this->evaluator->can(
        $this->user->getMorphClass(),
        $this->user->getKey(),
        'posts.create',
    ))->toBeTrue();
});

// --- Sanctum token without matching ability denies ---

it('denies when Sanctum token does not include the required ability', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = ['posts.read'];

    $this->user->withAccessToken($token);
    $this->actingAs($this->user);

    expect($this->evaluator->can(
        $this->user->getMorphClass(),
        $this->user->getKey(),
        'posts.delete',
    ))->toBeFalse();
});

it('denies when Sanctum token has empty abilities', function (): void {
    $token = new PersonalAccessToken;
    $token->abilities = [];

    $this->user->withAccessToken($token);
    $this->actingAs($this->user);

    expect($this->evaluator->can(
        $this->user->getMorphClass(),
        $this->user->getKey(),
        'posts.create',
    ))->toBeFalse();
});

// --- No Sanctum token (session auth) allows normally ---

it('allows normally when no Sanctum token is present (session auth)', function (): void {
    $this->actingAs($this->user);

    expect($this->evaluator->can(
        $this->user->getMorphClass(),
        $this->user->getKey(),
        'posts.create',
    ))->toBeTrue();
});

it('allows normally when user is not authenticated', function (): void {
    expect($this->evaluator->can(
        $this->user->getMorphClass(),
        $this->user->getKey(),
        'posts.create',
    ))->toBeTrue();
});
