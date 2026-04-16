<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Concerns\HasResourcePolicies;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Models\ResourcePolicy;
use DynamikDev\Marque\Support\ResourcePolicyBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class BuilderTestUser extends Model
{
    use HasPermissions;

    protected $table = 'builder_test_users';

    protected $guarded = [];

    protected function principalAttributes(): array
    {
        return ['id' => $this->getKey()];
    }
}

class BuilderTestPost extends Model
{
    use HasResourcePolicies;

    protected $table = 'builder_test_posts';

    protected $guarded = [];

    protected function resourceAttributes(): array
    {
        return [
            'status' => $this->status,
            'user_id' => $this->user_id,
        ];
    }
}

beforeEach(function (): void {
    Schema::create('builder_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('builder_test_posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('status');
        $table->unsignedBigInteger('user_id');
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('builder_test_posts');
    Schema::dropIfExists('builder_test_users');
});

it('returns a ResourcePolicyBuilder from Marque::resource()', function (): void {
    expect(Marque::resource(BuilderTestPost::class))
        ->toBeInstanceOf(ResourcePolicyBuilder::class);
});

it('defaults the owner field to user_id when ownedBy is not called', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->ownerCan('posts.update');

    $statement = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null)
        ->first();

    expect($statement->conditions[0]->parameters)->toBe([
        'subject_key' => 'id',
        'resource_key' => 'user_id',
    ]);
});

it('attaches one statement per action when ownerCan is called with multiple actions', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->ownedBy('user_id')
        ->ownerCan(['posts.update', 'posts.delete', 'posts.view']);

    $statements = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    expect($statements)->toHaveCount(3);

    $actions = $statements->pluck('action')->all();
    expect($actions)->toContain('posts.update', 'posts.delete', 'posts.view');

    $first = $statements->first();
    expect($first->conditions)->toHaveCount(1)
        ->and($first->conditions[0]->type)->toBe('attribute_equals')
        ->and($first->conditions[0]->parameters)->toBe([
            'subject_key' => 'id',
            'resource_key' => 'user_id',
        ]);
});

it('accepts a single action string for ownerCan', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->ownedBy('user_id')
        ->ownerCan('posts.update');

    $statements = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    expect($statements)->toHaveCount(1)
        ->and($statements->first()->action)->toBe('posts.update');
});

it('uses a custom subject key when ownedBy is given one', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->ownedBy('user_id', subjectKey: 'external_id')
        ->ownerCan('posts.update');

    $statement = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null)
        ->first();

    expect($statement->conditions[0]->parameters)->toBe([
        'subject_key' => 'external_id',
        'resource_key' => 'user_id',
    ]);
});

it('emits an attribute_in condition for allow statements inside a when() closure', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], function ($policy): void {
            $policy->anyoneCan('posts.view');
        });

    $statement = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null)
        ->first();

    expect($statement->action)->toBe('posts.view')
        ->and($statement->conditions)->toHaveCount(1)
        ->and($statement->conditions[0]->type)->toBe('attribute_in')
        ->and($statement->conditions[0]->parameters)->toBe([
            'source' => 'resource',
            'key' => 'status',
            'values' => ['published'],
        ]);
});

it('passes an array of values through when() unchanged as the allowed set', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => ['published', 'archived']], function ($policy): void {
            $policy->anyoneCan('posts.view');
        });

    $statement = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null)
        ->first();

    expect($statement->conditions[0]->parameters['values'])
        ->toBe(['published', 'archived']);
});

it('does not leak conditions outside the when() closure', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], function ($policy): void {
            $policy->anyoneCan('posts.view');
        })
        ->anyoneCan('posts.list');

    $statements = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    $listStatement = $statements->firstWhere('action', 'posts.list');

    expect($listStatement->conditions)->toBe([]);
});

it('accumulates conditions when when() closures are nested', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], function ($policy): void {
            $policy->when(['visibility' => 'public'], function ($policy): void {
                $policy->anyoneCan('posts.view');
            });
        });

    $statement = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null)
        ->first();

    expect($statement->conditions)->toHaveCount(2);

    $keys = array_map(fn ($c) => $c->parameters['key'], $statement->conditions);
    expect($keys)->toContain('status', 'visibility');
});

it('applies active conditions to ownerCan calls inside a when() closure', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], function ($policy): void {
            $policy->ownerCan('posts.update');
        });

    $statement = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null)
        ->first();

    expect($statement->conditions)->toHaveCount(2);

    $types = array_map(fn ($c) => $c->type, $statement->conditions);
    expect($types)->toContain('attribute_equals', 'attribute_in');
});

it('allows multiple top-level when() closures to coexist independently', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], function ($policy): void {
            $policy->anyoneCan('posts.view');
        })
        ->when(['status' => 'draft'], function ($policy): void {
            $policy->anyoneCan('posts.preview');
        });

    $statements = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    $view = $statements->firstWhere('action', 'posts.view');
    $preview = $statements->firstWhere('action', 'posts.preview');

    expect($view->conditions[0]->parameters['values'])->toBe(['published'])
        ->and($preview->conditions[0]->parameters['values'])->toBe(['draft']);
});

it('combines ownerCan outside a when() with anyoneCan inside a when()', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->ownerCan(['posts.update', 'posts.delete'])
        ->when(['status' => 'published'], function ($policy): void {
            $policy->anyoneCan('posts.view');
        });

    $statements = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    expect($statements)->toHaveCount(3);

    $actions = $statements->pluck('action')->all();
    expect($actions)->toContain('posts.update', 'posts.delete', 'posts.view');
});

it('resolves an owner update through the full evaluator pipeline', function (): void {
    app(PermissionStore::class)->register(['posts.update']);

    Marque::resource(BuilderTestPost::class)
        ->ownedBy('user_id')
        ->ownerCan('posts.update');

    $alice = BuilderTestUser::query()->create(['name' => 'Alice']);
    $bob = BuilderTestUser::query()->create(['name' => 'Bob']);

    $alicesPost = BuilderTestPost::query()->create([
        'title' => 'Alices Post',
        'status' => 'draft',
        'user_id' => $alice->getKey(),
    ]);

    expect($alice->canDo('posts.update', resource: $alicesPost->toPolicyResource()))
        ->toBeTrue();

    expect($bob->canDo('posts.update', resource: $alicesPost->toPolicyResource()))
        ->toBeFalse();
});

it('detaches statements for a given action via detach()', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->ownedBy('user_id')
        ->ownerCan(['posts.update', 'posts.delete']);

    expect(app(ResourcePolicyStore::class)->forResource(BuilderTestPost::class, null))
        ->toHaveCount(2);

    Marque::resource(BuilderTestPost::class)->detach('posts.update');

    $remaining = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->action)->toBe('posts.delete');
});

it('removes a single statement via detachById() while siblings survive', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], fn ($policy) => $policy->anyoneCan('posts.view'))
        ->when(['status' => 'draft'], fn ($policy) => $policy->anyoneCan('posts.view'));

    $store = app(ResourcePolicyStore::class);

    expect($store->forResource(BuilderTestPost::class, null))->toHaveCount(2);

    $publishedRow = ResourcePolicy::query()
        ->where('action', 'posts.view')
        ->get()
        ->first(function (ResourcePolicy $row): bool {
            $first = ($row->conditions ?? [])[0] ?? null;

            return is_array($first)
                && is_array($first['parameters'] ?? null)
                && in_array('published', $first['parameters']['values'] ?? [], true);
        });

    expect($publishedRow)->not->toBeNull();

    /** @var ResourcePolicy $publishedRow */
    Marque::resource(BuilderTestPost::class)->detachById($publishedRow->id);

    $remaining = $store->forResource(BuilderTestPost::class, null);

    expect($remaining)->toHaveCount(1);

    $survivingValues = $remaining->first()->conditions[0]->parameters['values'];
    expect($survivingValues)->toBe(['draft']);
});

it('does not leak ownedBy() called inside a when() closure to subsequent chain calls', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], function ($policy): void {
            $policy->ownedBy('author_id', subjectKey: 'external_id')
                ->ownerCan('posts.update');
        })
        ->ownerCan('posts.delete');

    $statements = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    $update = $statements->firstWhere('action', 'posts.update');
    $delete = $statements->firstWhere('action', 'posts.delete');

    $updateOwnership = collect($update->conditions)
        ->firstWhere('type', 'attribute_equals');
    expect($updateOwnership->parameters)->toBe([
        'subject_key' => 'external_id',
        'resource_key' => 'author_id',
    ]);

    expect($delete->conditions)->toHaveCount(1)
        ->and($delete->conditions[0]->type)->toBe('attribute_equals')
        ->and($delete->conditions[0]->parameters)->toBe([
            'subject_key' => 'id',
            'resource_key' => 'user_id',
        ]);
});

it('isolates ownedBy() state across nested when() blocks', function (): void {
    Marque::resource(BuilderTestPost::class)
        ->ownedBy('owner_id', subjectKey: 'uuid')
        ->when(['status' => 'published'], function ($policy): void {
            $policy->ownedBy('author_id', subjectKey: 'external_id')
                ->when(['visibility' => 'public'], function ($policy): void {
                    $policy->ownedBy('editor_id', subjectKey: 'staff_id')
                        ->ownerCan('posts.edit');
                })
                ->ownerCan('posts.update');
        })
        ->ownerCan('posts.delete');

    $statements = app(ResourcePolicyStore::class)
        ->forResource(BuilderTestPost::class, null);

    $edit = collect($statements->firstWhere('action', 'posts.edit')->conditions)
        ->firstWhere('type', 'attribute_equals');
    $update = collect($statements->firstWhere('action', 'posts.update')->conditions)
        ->firstWhere('type', 'attribute_equals');
    $delete = collect($statements->firstWhere('action', 'posts.delete')->conditions)
        ->firstWhere('type', 'attribute_equals');

    expect($edit->parameters)->toBe([
        'subject_key' => 'staff_id',
        'resource_key' => 'editor_id',
    ]);

    expect($update->parameters)->toBe([
        'subject_key' => 'external_id',
        'resource_key' => 'author_id',
    ]);

    expect($delete->parameters)->toBe([
        'subject_key' => 'uuid',
        'resource_key' => 'owner_id',
    ]);
});

it('resolves a public view through the full evaluator pipeline', function (): void {
    app(PermissionStore::class)->register(['posts.view']);

    Marque::resource(BuilderTestPost::class)
        ->when(['status' => 'published'], function ($policy): void {
            $policy->anyoneCan('posts.view');
        });

    $reader = BuilderTestUser::query()->create(['name' => 'Reader']);
    $author = BuilderTestUser::query()->create(['name' => 'Author']);

    $publishedPost = BuilderTestPost::query()->create([
        'title' => 'Published Post',
        'status' => 'published',
        'user_id' => $author->getKey(),
    ]);

    $draftPost = BuilderTestPost::query()->create([
        'title' => 'Draft Post',
        'status' => 'draft',
        'user_id' => $author->getKey(),
    ]);

    expect($reader->canDo('posts.view', resource: $publishedPost->toPolicyResource()))
        ->toBeTrue();

    expect($reader->canDo('posts.view', resource: $draftPost->toPolicyResource()))
        ->toBeFalse();
});
