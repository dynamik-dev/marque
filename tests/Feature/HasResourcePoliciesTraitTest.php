<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasResourcePolicies;
use DynamikDev\PolicyEngine\Contracts\ResourcePolicyStore;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\DTOs\Resource;
use DynamikDev\PolicyEngine\Enums\Effect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class ResourcePoliciesTestDocument extends Model
{
    use HasResourcePolicies;

    protected $table = 'documents';

    public $timestamps = false;

    protected $guarded = [];

    protected $fillable = ['title', 'owner_id'];
}

beforeEach(function (): void {
    Schema::create('documents', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('owner_id');
    });

    $this->store = app(ResourcePolicyStore::class);

    $this->document = ResourcePoliciesTestDocument::query()->create([
        'title' => 'Quarterly Report',
        'owner_id' => 1,
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('documents');
});

// --- toPolicyResource ---

it('toPolicyResource returns a Resource with the correct type and id', function (): void {
    $resource = $this->document->toPolicyResource();

    expect($resource)->toBeInstanceOf(Resource::class)
        ->and($resource->type)->toBe(ResourcePoliciesTestDocument::class)
        ->and($resource->id)->toBe($this->document->getKey());
});

it('toPolicyResource includes fillable attributes', function (): void {
    $resource = $this->document->toPolicyResource();

    expect($resource->attributes)->toHaveKey('title', 'Quarterly Report')
        ->and($resource->attributes)->toHaveKey('owner_id', 1);
});

// --- attachPolicy ---

it('attachPolicy attaches a policy via the store', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->document->attachPolicy($statement);

    $results = $this->store->forResource(
        ResourcePoliciesTestDocument::class,
        $this->document->getKey(),
    );

    expect($results)->toHaveCount(1)
        ->and($results->first()->action)->toBe('documents.read')
        ->and($results->first()->effect)->toBe(Effect::Allow);
});

// --- detachPolicy ---

it('detachPolicy removes a policy from the store', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->document->attachPolicy($statement);

    $before = $this->store->forResource(ResourcePoliciesTestDocument::class, $this->document->getKey());
    expect($before)->toHaveCount(1);

    $this->document->detachPolicy('documents.read');

    $after = $this->store->forResource(ResourcePoliciesTestDocument::class, $this->document->getKey());
    expect($after)->toBeEmpty();
});

it('detachPolicy only removes the specified action', function (): void {
    $readStatement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $writeStatement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.write',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->document->attachPolicy($readStatement);
    $this->document->attachPolicy($writeStatement);

    $this->document->detachPolicy('documents.read');

    $results = $this->store->forResource(ResourcePoliciesTestDocument::class, $this->document->getKey());

    expect($results)->toHaveCount(1)
        ->and($results->first()->action)->toBe('documents.write');
});
