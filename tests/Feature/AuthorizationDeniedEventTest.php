<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Evaluators\CachedEvaluator;
use DynamikDev\Marque\Evaluators\DefaultEvaluator;
use DynamikDev\Marque\Events\AuthorizationDenied;
use DynamikDev\Marque\Resolvers\IdentityPolicyResolver;
use DynamikDev\Marque\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class AuthorizationDeniedTestUser extends Model implements Authenticatable
{
    use HasPermissions;
    use Illuminate\Auth\Authenticatable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
    });

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

    config(['marque.resolvers' => [IdentityPolicyResolver::class]]);

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);

    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);

    $this->user = AuthorizationDeniedTestUser::query()->create([
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'password' => 'secret',
    ]);

    $this->user->assign('viewer');
});

afterEach(function (): void {
    Schema::dropIfExists('users');
    CacheStoreResolver::reset();
});

it('dispatches AuthorizationDenied when canDo returns false', function (): void {
    Event::fake([AuthorizationDenied::class]);

    $this->user->canDo('posts.delete');

    Event::assertDispatched(AuthorizationDenied::class, function (AuthorizationDenied $event): bool {
        return $event->subject === AuthorizationDeniedTestUser::class.':'.$this->user->getKey()
            && $event->permission === 'posts.delete'
            && $event->scope === null;
    });
});

it('does not dispatch AuthorizationDenied when canDo returns true', function (): void {
    Event::fake([AuthorizationDenied::class]);

    $this->user->canDo('posts.read');

    Event::assertNotDispatched(AuthorizationDenied::class);
});

it('dispatches AuthorizationDenied with scope on scoped denial', function (): void {
    Event::fake([AuthorizationDenied::class]);

    $this->user->canDo('posts.delete', 'team::7');

    Event::assertDispatched(AuthorizationDenied::class, function (AuthorizationDenied $event): bool {
        return $event->subject === AuthorizationDeniedTestUser::class.':'.$this->user->getKey()
            && $event->permission === 'posts.delete'
            && $event->scope === 'team::7';
    });
});

it('dispatches AuthorizationDenied via cannotDo on denial', function (): void {
    Event::fake([AuthorizationDenied::class]);

    $this->user->cannotDo('posts.delete');

    Event::assertDispatched(AuthorizationDenied::class, function (AuthorizationDenied $event): bool {
        return $event->permission === 'posts.delete';
    });
});
