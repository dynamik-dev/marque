<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Concerns\HasResourcePolicies;
use DynamikDev\Marque\Facades\Marque;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * End-to-end test proving that ownership-based authorization works through
 * the full Marque stack with no Laravel model policy declared anywhere.
 *
 * Chain under test:
 *   Marque::import(json)
 *     -> permissions registered
 *     -> roles persisted
 *     -> resource policy with attribute_equals condition persisted
 *   $user->assign('role')
 *   Gate::forUser($user)->allows('posts.update', [$post])
 *     -> Gate::before hook in MarqueServiceProvider
 *     -> $post->toPolicyResource() via HasResourcePolicies trait
 *     -> DefaultEvaluator collects statements from all resolvers
 *     -> ResourcePolicyResolver returns the conditional statement
 *     -> AttributeEqualsEvaluator compares principal.id vs resource.user_id
 *     -> Allow or default-deny based on the comparison
 */
class OwnershipTestUser extends Model implements Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable;
    use HasPermissions;

    protected $table = 'ownership_users';

    public $timestamps = false;

    protected $guarded = [];

    protected function principalAttributes(): array
    {
        return ['id' => $this->id];
    }
}

class OwnershipTestPost extends Model
{
    use HasResourcePolicies;

    protected $table = 'ownership_posts';

    public $timestamps = false;

    protected $fillable = ['title', 'user_id'];
}

beforeEach(function (): void {
    Schema::create('ownership_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('ownership_posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('user_id');
    });

    $postType = OwnershipTestPost::class;

    $json = <<<JSON
    {
        "version": "2.0",
        "permissions": ["posts.create", "posts.update"],
        "roles": {
            "author": {
                "permissions": ["posts.create"]
            },
            "staff": {
                "permissions": ["posts.create", "posts.update"]
            }
        },
        "resource_policies": [
            {
                "resource_type": "{$postType}",
                "resource_id": null,
                "effect": "Allow",
                "action": "posts.update",
                "principal_pattern": null,
                "conditions": [
                    {
                        "type": "attribute_equals",
                        "parameters": {
                            "subject_key": "id",
                            "resource_key": "user_id"
                        }
                    }
                ]
            }
        ]
    }
    JSON;

    Marque::import($json);

    $this->author = OwnershipTestUser::query()->create(['name' => 'Alice']);
    $this->author->assign('author');

    $this->otherAuthor = OwnershipTestUser::query()->create(['name' => 'Bob']);
    $this->otherAuthor->assign('author');

    $this->staff = OwnershipTestUser::query()->create(['name' => 'Mallory']);
    $this->staff->assign('staff');

    $this->alicePost = OwnershipTestPost::query()->create([
        'title' => 'Alice wrote this',
        'user_id' => $this->author->id,
    ]);

    $this->bobPost = OwnershipTestPost::query()->create([
        'title' => 'Bob wrote this',
        'user_id' => $this->otherAuthor->id,
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('ownership_posts');
    Schema::dropIfExists('ownership_users');
});

it('allows an author to update their own post via the ownership condition', function (): void {
    expect(Gate::forUser($this->author)->allows('posts.update', [$this->alicePost]))->toBeTrue();
});

it('denies an author from updating a post owned by someone else', function (): void {
    expect(Gate::forUser($this->author)->allows('posts.update', [$this->bobPost]))->toBeFalse();
});

it('allows staff to update any post regardless of ownership', function (): void {
    expect(Gate::forUser($this->staff)->allows('posts.update', [$this->alicePost]))->toBeTrue()
        ->and(Gate::forUser($this->staff)->allows('posts.update', [$this->bobPost]))->toBeTrue();
});

it('denies an author from posts.update when no resource is supplied', function (): void {
    // The resource policy only resolves when a resource is present in the request.
    // Without a resource, the author has no unconditional grant from their role,
    // so the evaluator falls through to default-deny.
    expect($this->author->canDo('posts.update'))->toBeFalse();
});

it('allows staff to perform posts.update without a resource via their role grant', function (): void {
    expect($this->staff->canDo('posts.update'))->toBeTrue();
});

it('allows authors to create posts via their role grant', function (): void {
    expect($this->author->canDo('posts.create'))->toBeTrue()
        ->and($this->staff->canDo('posts.create'))->toBeTrue();
});

it('denies a user with no role from updating any post', function (): void {
    $stranger = OwnershipTestUser::query()->create(['name' => 'Eve']);

    expect(Gate::forUser($stranger)->allows('posts.update', [$this->alicePost]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('posts.update', [$this->bobPost]))->toBeFalse();
});
