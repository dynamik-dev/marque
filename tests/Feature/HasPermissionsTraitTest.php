<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\EvaluationResult;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\DTOs\Resource;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Evaluators\CachedEvaluator;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Resolvers\IdentityPolicyResolver;
use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A minimal Eloquent model for testing the HasPermissions trait.
 */
class HasPermissionsTestUser extends Model implements Authenticatable
{
    use HasPermissions;
    use Illuminate\Auth\Authenticatable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected function principalAttributes(): array
    {
        return ['department_id' => 42];
    }
}

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
    });

    // Wire up the v2 evaluator with IdentityPolicyResolver so canDo works
    // while the service provider binding is updated in Task 1.8.
    app()->singleton(Evaluator::class, function ($app): CachedEvaluator {
        return new CachedEvaluator(
            inner: new DefaultEvaluator(
                resolvers: [new IdentityPolicyResolver(
                    assignments: $app->make(AssignmentStore::class),
                    roles: $app->make(RoleStore::class),
                )],
                matcher: $app->make(Matcher::class),
            ),
            cache: $app->make(CacheManager::class),
        );
    });

    // Set the resolver chain for effectivePermissions().
    config(['policy-engine.resolvers' => [IdentityPolicyResolver::class]]);

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);

    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.update', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.update']);

    $this->user = HasPermissionsTestUser::query()->create([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'secret',
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('users');
    CacheStoreResolver::reset();
});

// --- toPrincipal ---

it('toPrincipal returns a Principal with correct type, id, and attributes', function (): void {
    $principal = $this->user->toPrincipal();

    expect($principal)->toBeInstanceOf(Principal::class)
        ->and($principal->type)->toBe(HasPermissionsTestUser::class)
        ->and($principal->id)->toBe($this->user->getKey())
        ->and($principal->attributes)->toBe(['department_id' => 42]);
});

// --- canDo ---

it('canDo returns true when user has permission via role', function (): void {
    $this->user->assign('editor');

    expect($this->user->canDo('posts.create'))->toBeTrue();
});

it('canDo returns false when user lacks permission', function (): void {
    $this->user->assign('editor');

    expect($this->user->canDo('posts.delete'))->toBeFalse();
});

it('canDo accepts an optional Resource parameter', function (): void {
    $this->user->assign('editor');

    $resource = new Resource(type: 'post', id: 99);

    expect($this->user->canDo('posts.create', null, resource: $resource))->toBeTrue();
});

it('canDo accepts an optional environment parameter', function (): void {
    $this->user->assign('editor');

    expect($this->user->canDo('posts.create', null, environment: ['ip' => '127.0.0.1']))->toBeTrue();
});

// --- cannotDo ---

it('cannotDo returns true when permission is missing', function (): void {
    expect($this->user->cannotDo('posts.delete'))->toBeTrue();
});

it('cannotDo returns false when permission is granted', function (): void {
    $this->user->assign('editor');

    expect($this->user->cannotDo('posts.create'))->toBeFalse();
});

// --- explain ---

it('explain returns an EvaluationResult', function (): void {
    $this->user->assign('editor');

    $result = $this->user->explain('posts.create');

    expect($result)->toBeInstanceOf(EvaluationResult::class)
        ->and($result->decision)->toBe(Decision::Allow);
});

it('explain returns a Deny result when permission is not granted', function (): void {
    $result = $this->user->explain('posts.delete');

    expect($result)->toBeInstanceOf(EvaluationResult::class)
        ->and($result->decision)->toBe(Decision::Deny);
});

// --- assign / revoke ---

it('assign then canDo returns true, revoke then canDo returns false', function (): void {
    $this->user->assign('editor');

    expect($this->user->canDo('posts.create'))->toBeTrue();

    $this->user->revoke('editor');

    // Flush the evaluator cache so the next check hits the store.
    app()->forgetInstance(Evaluator::class);
    app()->singleton(Evaluator::class, function ($app): CachedEvaluator {
        return new CachedEvaluator(
            inner: new DefaultEvaluator(
                resolvers: [new IdentityPolicyResolver(
                    assignments: $app->make(AssignmentStore::class),
                    roles: $app->make(RoleStore::class),
                )],
                matcher: $app->make(Matcher::class),
            ),
            cache: $app->make(CacheManager::class),
        );
    });

    expect($this->user->canDo('posts.create'))->toBeFalse();
});

// --- hasRole ---

it('hasRole returns true when user has the role (unscoped)', function (): void {
    $this->user->assign('editor');

    expect($this->user->hasRole('editor'))->toBeTrue();
});

it('hasRole returns false when user does not have the role', function (): void {
    expect($this->user->hasRole('editor'))->toBeFalse();
});

it('hasRole returns true when user has role in the given scope', function (): void {
    $this->user->assign('editor', 'team::5');

    expect($this->user->hasRole('editor', 'team::5'))->toBeTrue();
});

it('hasRole returns false when user has role in a different scope', function (): void {
    $this->user->assign('editor', 'team::5');

    expect($this->user->hasRole('editor', 'team::99'))->toBeFalse();
});

it('hasRole returns false for unscoped check when role is only scoped', function (): void {
    $this->user->assign('editor', 'team::5');

    expect($this->user->hasRole('editor'))->toBeFalse();
});

it('hasRole returns false for scoped check when role is only global', function (): void {
    $this->user->assign('editor');

    expect($this->user->hasRole('editor', 'team::5'))->toBeFalse();
});

// --- effectivePermissions ---

it('effectivePermissions returns allowed permission strings', function (): void {
    $this->user->assign('editor');

    $permissions = $this->user->effectivePermissions();

    expect($permissions)
        ->toContain('posts.create', 'posts.read', 'posts.update')
        ->not->toContain('posts.delete');
});

it('effectivePermissions excludes permissions covered by deny statements', function (): void {
    $this->roleStore->save('restricted', 'Restricted', ['!posts.update']);
    $this->user->assign('editor');
    $this->user->assign('restricted');

    $permissions = $this->user->effectivePermissions();

    expect($permissions)
        ->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.update');
});

it('effectivePermissions returns an empty array when user has no assignments', function (): void {
    expect($this->user->effectivePermissions())->toBe([]);
});
