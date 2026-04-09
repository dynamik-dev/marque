<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

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
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

it('allows when Sanctum token has wildcard ability', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

it('allows when Sanctum token ability matches via wildcard pattern', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

// --- Sanctum token without matching ability denies ---

it('denies when Sanctum token does not include the required ability', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

it('denies when Sanctum token has empty abilities', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

// --- No Sanctum token (session auth) allows normally ---

it('allows normally when no Sanctum token is present (session auth)', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

it('allows normally when user is not authenticated', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

// --- explain() mirrors Sanctum token scoping ---

it('explain reports deny with sanctum note when token lacks required ability', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

it('explain reports allow with no sanctum note when token includes required ability', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');

it('explain reports allow with no sanctum note when no token is present', function (): void {
    // SanctumPolicyResolver not yet implemented (Task 5.1).
})->skip('SanctumPolicyResolver not yet implemented (Task 5.1)');
