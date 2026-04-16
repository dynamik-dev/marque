<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Concerns\HasResourcePolicies;
use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\DTOs\Resource;
use DynamikDev\Marque\Enums\Decision;
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

// --- Null scope + Resource DTO as second argument ---

it('preserves the resource when first Gate argument is null and second is a Resource DTO', function (): void {
    $captured = new stdClass;
    $captured->request = null;

    app()->instance(Evaluator::class, new class($captured) implements Evaluator
    {
        public function __construct(private stdClass $captured) {}

        public function evaluate(EvaluationRequest $request): EvaluationResult
        {
            $this->captured->request = $request;

            return new EvaluationResult(
                decision: Decision::Allow,
                decidedBy: 'fake',
            );
        }
    });

    $resource = new Resource(type: 'post', id: 42, attributes: ['title' => 'Hello']);

    expect(Gate::allows('posts.update', [null, $resource]))->toBeTrue();
    expect($captured->request)->toBeInstanceOf(EvaluationRequest::class);
    expect($captured->request->resource)->toBe($resource);
    expect($captured->request->context->scope)->toBeNull();
});

it('preserves a Resource via toPolicyResource when first Gate argument is null', function (): void {
    $captured = new stdClass;
    $captured->request = null;

    app()->instance(Evaluator::class, new class($captured) implements Evaluator
    {
        public function __construct(private stdClass $captured) {}

        public function evaluate(EvaluationRequest $request): EvaluationResult
        {
            $this->captured->request = $request;

            return new EvaluationResult(
                decision: Decision::Allow,
                decidedBy: 'fake',
            );
        }
    });

    $post = GateTestPost::query()->create(['title' => 'Hello', 'status' => 'draft']);

    expect(Gate::allows('posts.update', [null, $post]))->toBeTrue();
    expect($captured->request)->toBeInstanceOf(EvaluationRequest::class);
    expect($captured->request->resource)->toBeInstanceOf(Resource::class);
    expect($captured->request->resource->type)->toBe('GateTestPost');
    expect($captured->request->resource->id)->toBe($post->getKey());
});

// --- Non-dot abilities are not intercepted ---

it('does not intercept non-dot abilities', function (): void {
    Gate::define('viewDashboard', fn (): bool => false);

    expect(Gate::allows('viewDashboard'))->toBeFalse();
});
