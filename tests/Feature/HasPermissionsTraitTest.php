<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\DTOs\Resource;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Evaluators\CachedEvaluator;
use DynamikDev\Marque\Evaluators\DefaultEvaluator;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Resolvers\IdentityPolicyResolver;
use DynamikDev\Marque\Support\CacheStoreResolver;
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

    /* Wire up the v2 evaluator with IdentityPolicyResolver so canDo works. */
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
    config(['marque.resolvers' => [IdentityPolicyResolver::class]]);

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

it('effectivePermissions excludes literal denies under a wildcard allow', function (): void {
    $this->roleStore->save('wildcard-editor', 'Wildcard Editor', ['posts.*']);
    $this->roleStore->save('no-create', 'No Create', ['!posts.create']);
    $this->user->assign('wildcard-editor');
    $this->user->assign('no-create');

    $permissions = $this->user->effectivePermissions();

    expect($permissions)
        ->toContain('posts.read', 'posts.update', 'posts.delete')
        ->not->toContain('posts.create');
});

it('effectivePermissions expands a wildcard allow against the permission registry', function (): void {
    $this->roleStore->save('wildcard-editor', 'Wildcard Editor', ['posts.*']);
    $this->user->assign('wildcard-editor');

    $permissions = $this->user->effectivePermissions();

    expect($permissions)
        ->toContain('posts.create', 'posts.read', 'posts.update', 'posts.delete')
        ->toHaveCount(4);
});

it('effectivePermissions membership matches per-action canDo evaluation', function (): void {
    $this->roleStore->save('wildcard-editor', 'Wildcard Editor', ['posts.*']);
    $this->roleStore->save('no-delete', 'No Delete', ['!posts.delete']);
    $this->user->assign('wildcard-editor');
    $this->user->assign('no-delete');

    $effective = $this->user->effectivePermissions();

    foreach (['posts.create', 'posts.read', 'posts.update', 'posts.delete'] as $permission) {
        $isAllowed = $this->user->canDo($permission);
        $isMember = in_array($permission, $effective, true);

        expect($isMember)->toBe($isAllowed, "[{$permission}] effective membership must match canDo");
    }
});

it('effectivePermissions handles a wildcard deny that removes everything under a prefix', function (): void {
    $this->roleStore->save('wildcard-editor', 'Wildcard Editor', ['posts.*']);
    $this->roleStore->save('no-posts', 'No Posts', ['!posts.*']);
    $this->user->assign('wildcard-editor');
    $this->user->assign('no-posts');

    expect($this->user->effectivePermissions())->toBe([]);
});

// --- givePermission auto-registration parity with RoleBuilder::grant ---

it('givePermission auto-registers a literal permission that is not yet registered', function (): void {
    expect($this->permissionStore->exists('reports.view'))->toBeFalse();

    $this->user->givePermission('reports.view');

    expect($this->permissionStore->exists('reports.view'))->toBeTrue()
        ->and($this->user->canDo('reports.view'))->toBeTrue();
});

it('givePermission does not auto-register wildcard permissions', function (): void {
    $this->user->givePermission('reports.*');

    expect($this->permissionStore->exists('reports.*'))->toBeFalse();
});

it('givePermission strips the deny prefix when auto-registering', function (): void {
    $this->user->givePermission('!reports.delete');

    expect($this->permissionStore->exists('reports.delete'))->toBeTrue()
        ->and($this->permissionStore->exists('!reports.delete'))->toBeFalse();
});

it('givePermission and grant register literal permissions identically', function (): void {
    $this->user->givePermission('alpha.create');
    Marque::createRole('beta-role', 'Beta')->grant(['beta.create']);

    expect($this->permissionStore->exists('alpha.create'))->toBeTrue()
        ->and($this->permissionStore->exists('beta.create'))->toBeTrue();
});

it('givePermission and grant skip wildcard registration identically', function (): void {
    $this->user->givePermission('alpha.*');
    Marque::createRole('beta-role', 'Beta')->grant(['beta.*']);

    expect($this->permissionStore->exists('alpha.*'))->toBeFalse()
        ->and($this->permissionStore->exists('beta.*'))->toBeFalse();
});

it('givePermission does not duplicate an already-registered permission', function (): void {
    $this->permissionStore->register(['reports.view']);

    $this->user->givePermission('reports.view');

    $all = $this->permissionStore->all()->pluck('id')->all();
    expect(array_count_values($all)['reports.view'] ?? 0)->toBe(1);
});

it('effectivePermissions scales linearly across a 100-permission registry', function (): void {
    $bulk = [];
    for ($i = 0; $i < 100; $i++) {
        $bulk[] = "bulk.action_{$i}";
    }

    $this->permissionStore->register($bulk);
    $this->roleStore->save('bulk-editor', 'Bulk Editor', ['bulk.*']);
    $this->roleStore->save('bulk-no-50', 'Bulk No 50', ['!bulk.action_50']);
    $this->user->assign('bulk-editor');
    $this->user->assign('bulk-no-50');

    $start = hrtime(true);
    $permissions = $this->user->effectivePermissions();
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect($permissions)
        ->toHaveCount(99)
        ->not->toContain('bulk.action_50')
        ->toContain('bulk.action_0', 'bulk.action_99');

    // Sanity ceiling: 100 permissions with two wildcard statements should
    // resolve well under 500ms even on slow CI hardware.
    expect($elapsedMs)->toBeLessThan(500.0);
});
