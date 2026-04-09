<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Concerns\HasResourcePolicies;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\Resource;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class GateTestUser extends Model implements Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable;
    use HasPermissions;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class GateTestPost extends Model
{
    use HasResourcePolicies;

    protected $table = 'posts';

    protected $fillable = ['title', 'status'];

    public $timestamps = false;
}

beforeEach(function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('status')->default('draft');
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);

    $this->permissionStore->register(['posts.create', 'posts.update', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.update']);

    $this->user = GateTestUser::query()->create(['name' => 'Alice']);
    $this->user->assign('editor');

    $this->actingAs($this->user);
});

afterEach(function (): void {
    Schema::dropIfExists('posts');
    Schema::dropIfExists('users');
});

// --- Basic dot-notated Gate integration ---

it('allows a dot-notated permission the user has through Gate', function (): void {
    expect(Gate::allows('posts.create'))->toBeTrue();
});

it('denies a dot-notated permission the user lacks through Gate', function (): void {
    expect(Gate::allows('posts.delete'))->toBeFalse();
});

// --- Scope as first argument (v1 compat) ---

it('accepts a string scope as the first argument for scoped permissions', function (): void {
    $this->user->revoke('editor');
    $this->user->assign('editor', 'team::5');

    expect(Gate::allows('posts.create', ['team::5']))->toBeTrue();
});

// --- Resource model as single argument ---

it('accepts a model with HasResourcePolicies trait as a single argument', function (): void {
    $post = GateTestPost::query()->create(['title' => 'Hello', 'status' => 'draft']);

    expect(Gate::allows('posts.update', [$post]))->toBeTrue();
});

// --- Scope + resource as two arguments ---

it('accepts scope as first and resource model as second argument', function (): void {
    $this->user->revoke('editor');
    $this->user->assign('editor', 'team::5');

    $post = GateTestPost::query()->create(['title' => 'Hello', 'status' => 'draft']);

    expect(Gate::allows('posts.update', ['team::5', $post]))->toBeTrue();
});

// --- Resource DTO directly ---

it('accepts a Resource DTO as a single argument', function (): void {
    $resource = new Resource(type: 'post', id: 1, attributes: ['title' => 'Hello']);

    expect(Gate::allows('posts.update', [$resource]))->toBeTrue();
});

// --- Non-dot abilities are not intercepted ---

it('does not intercept non-dot abilities', function (): void {
    Gate::define('viewDashboard', fn (): bool => false);

    expect(Gate::allows('viewDashboard'))->toBeFalse();
});
