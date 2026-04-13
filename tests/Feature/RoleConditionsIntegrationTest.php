<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\DTOs\Resource;
use DynamikDev\Marque\Facades\Marque;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class RoleConditionsUser extends Model implements Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable;
    use HasPermissions;

    protected $table = 'role_cond_users';

    public $timestamps = false;

    protected $guarded = [];

    protected function principalAttributes(): array
    {
        return ['department_id' => $this->department_id];
    }
}

beforeEach(function (): void {
    Schema::create('role_cond_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('department_id');
    });

    config()->set('marque.cache.enabled', false);

    $json = <<<'JSON'
    {
        "version": "2.0",
        "permissions": ["posts.create", "posts.read", "posts.update"],
        "roles": {
            "editor": {
                "permissions": ["posts.create", "posts.read", "posts.update"],
                "conditions": {
                    "posts.update": [
                        {
                            "type": "attribute_equals",
                            "parameters": {
                                "subject_key": "department_id",
                                "resource_key": "department_id"
                            }
                        }
                    ]
                }
            }
        }
    }
    JSON;

    Marque::import($json);

    $this->matchingUser = RoleConditionsUser::query()->create([
        'name' => 'Alice',
        'department_id' => 5,
    ]);
    $this->matchingUser->assign('editor');

    $this->mismatchedUser = RoleConditionsUser::query()->create([
        'name' => 'Bob',
        'department_id' => 99,
    ]);
    $this->mismatchedUser->assign('editor');

    $this->matchingResource = new Resource(
        type: 'post',
        id: '1',
        attributes: ['department_id' => 5],
    );

    $this->mismatchedResource = new Resource(
        type: 'post',
        id: '2',
        attributes: ['department_id' => 42],
    );
});

afterEach(function (): void {
    Schema::dropIfExists('role_cond_users');
});

it('allows a user whose department matches the resource condition', function (): void {
    expect($this->matchingUser->canDo('posts.update', resource: $this->matchingResource))->toBeTrue();
});

it('denies a user whose department does not match the resource condition', function (): void {
    expect($this->mismatchedUser->canDo('posts.update', resource: $this->matchingResource))->toBeFalse();
});

it('denies when the resource department does not match any user', function (): void {
    expect($this->matchingUser->canDo('posts.update', resource: $this->mismatchedResource))->toBeFalse();
});

it('allows unconditional permissions for all users regardless of department', function (): void {
    expect($this->matchingUser->canDo('posts.read'))->toBeTrue()
        ->and($this->mismatchedUser->canDo('posts.read'))->toBeTrue()
        ->and($this->matchingUser->canDo('posts.create'))->toBeTrue()
        ->and($this->mismatchedUser->canDo('posts.create'))->toBeTrue();
});

it('denies conditional permission when no resource is provided', function (): void {
    expect($this->matchingUser->canDo('posts.update'))->toBeFalse();
});

it('round-trips role conditions through export and re-import', function (): void {
    $exported = Marque::export();
    $decoded = json_decode($exported, associative: true);

    expect($decoded['roles']['editor'])->toHaveKey('conditions')
        ->and($decoded['roles']['editor']['conditions'])->toHaveKey('posts.update')
        ->and($decoded['roles']['editor']['conditions']['posts.update'])->toHaveCount(1)
        ->and($decoded['roles']['editor']['conditions']['posts.update'][0]['type'])->toBe('attribute_equals');

    // Re-import the exported document and verify conditions still enforced
    Marque::import($exported);

    expect($this->matchingUser->canDo('posts.update', resource: $this->matchingResource))->toBeTrue()
        ->and($this->mismatchedUser->canDo('posts.update', resource: $this->matchingResource))->toBeFalse();
});
