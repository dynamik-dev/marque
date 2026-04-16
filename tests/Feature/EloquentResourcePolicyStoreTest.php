<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Events\ResourcePolicyDetached;
use DynamikDev\Marque\Models\ResourcePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = app(ResourcePolicyStore::class);
});

it('attaches and retrieves a policy for a specific resource instance', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 42, $statement);

    $results = $this->store->forResource('App\\Models\\Document', 42);

    expect($results)->toHaveCount(1);

    $result = $results->first();
    expect($result->effect)->toBe(Effect::Allow)
        ->and($result->action)->toBe('documents.read')
        ->and($result->source)->toBe('resource:App\\Models\\Document:42');
});

it('attaches and retrieves a type-level policy with null resource id', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Deny,
        action: 'documents.delete',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', null, $statement);

    $results = $this->store->forResource('App\\Models\\Document', null);

    expect($results)->toHaveCount(1);

    $result = $results->first();
    expect($result->effect)->toBe(Effect::Deny)
        ->and($result->action)->toBe('documents.delete')
        ->and($result->source)->toBe('resource:App\\Models\\Document');
});

it('forResource returns both type-level and instance-level policies together', function (): void {
    $typeLevelStatement = new PolicyStatement(
        effect: Effect::Deny,
        action: 'documents.delete',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $instanceStatement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', null, $typeLevelStatement);
    $this->store->attach('App\\Models\\Document', 7, $instanceStatement);

    $results = $this->store->forResource('App\\Models\\Document', 7);

    expect($results)->toHaveCount(2);

    $actions = $results->pluck('action')->sort()->values()->all();
    expect($actions)->toBe(['documents.delete', 'documents.read']);
});

it('detaches a policy by action for a specific resource instance', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 10, $statement);

    $before = $this->store->forResource('App\\Models\\Document', 10);
    expect($before)->toHaveCount(1);

    $this->store->detach('App\\Models\\Document', 10, 'documents.read');

    $after = $this->store->forResource('App\\Models\\Document', 10);
    expect($after)->toBeEmpty();
});

it('detach with null id removes only type-level policies', function (): void {
    $typeLevelStatement = new PolicyStatement(
        effect: Effect::Deny,
        action: 'documents.delete',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $instanceStatement = new PolicyStatement(
        effect: Effect::Deny,
        action: 'documents.delete',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', null, $typeLevelStatement);
    $this->store->attach('App\\Models\\Document', 5, $instanceStatement);

    $this->store->detach('App\\Models\\Document', null, 'documents.delete');

    // Instance-level policy should remain
    $results = $this->store->forResource('App\\Models\\Document', 5);
    $actions = $results->pluck('action')->all();

    // Type-level is gone; instance-level stays
    expect($results->filter(fn ($s) => $s->source === 'resource:App\\Models\\Document'))->toBeEmpty()
        ->and($actions)->toContain('documents.delete');
});

it('stores and hydrates conditions correctly', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [
            new Condition(type: 'attribute_equals', parameters: ['attribute' => 'department_id', 'value' => 1]),
        ],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 20, $statement);

    $results = $this->store->forResource('App\\Models\\Document', 20);

    expect($results)->toHaveCount(1);

    $result = $results->first();
    expect($result->conditions)->toHaveCount(1);

    $condition = $result->conditions[0];
    expect($condition)->toBeInstanceOf(Condition::class)
        ->and($condition->type)->toBe('attribute_equals')
        ->and($condition->parameters)->toEqualCanonicalizing(['attribute' => 'department_id', 'value' => 1]);
});

it('detachById removes only the targeted statement when several share the same triple', function (): void {
    $allowWithDept1 = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [
            new Condition(type: 'attribute_equals', parameters: ['attribute' => 'department_id', 'value' => 1]),
        ],
        source: '',
    );

    $allowWithDept2 = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [
            new Condition(type: 'attribute_equals', parameters: ['attribute' => 'department_id', 'value' => 2]),
        ],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 99, $allowWithDept1);
    $this->store->attach('App\\Models\\Document', 99, $allowWithDept2);

    expect($this->store->forResource('App\\Models\\Document', 99))->toHaveCount(2);

    $targetRow = ResourcePolicy::query()
        ->where('resource_type', 'App\\Models\\Document')
        ->where('resource_id', '99')
        ->get()
        ->first(function (ResourcePolicy $row): bool {
            $conditions = $row->conditions ?? [];
            $first = $conditions[0] ?? null;

            return is_array($first)
                && is_array($first['parameters'] ?? null)
                && ($first['parameters']['value'] ?? null) === 1;
        });

    expect($targetRow)->not->toBeNull();

    /** @var ResourcePolicy $targetRow */
    $this->store->detachById($targetRow->id);

    $survivors = $this->store->forResource('App\\Models\\Document', 99);

    expect($survivors)->toHaveCount(1);

    $survivor = $survivors->first();
    expect($survivor->conditions[0]->parameters)
        ->toEqualCanonicalizing(['attribute' => 'department_id', 'value' => 2]);
});

it('detachById dispatches ResourcePolicyDetached with the row metadata', function (): void {
    Event::fake([ResourcePolicyDetached::class]);

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.update',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 77, $statement);

    $id = ResourcePolicy::query()
        ->where('resource_type', 'App\\Models\\Document')
        ->where('resource_id', '77')
        ->value('id');

    $this->store->detachById($id);

    Event::assertDispatched(
        ResourcePolicyDetached::class,
        fn (ResourcePolicyDetached $event): bool => $event->resourceType === 'App\\Models\\Document'
            && $event->resourceId === '77'
            && $event->action === 'documents.update',
    );
});

it('detachById is a no-op when the id does not exist', function (): void {
    Event::fake([ResourcePolicyDetached::class]);

    $this->store->detachById('01HZZZZZZZZZZZZZZZZZZZZZZZ');

    Event::assertNotDispatched(ResourcePolicyDetached::class);
});
