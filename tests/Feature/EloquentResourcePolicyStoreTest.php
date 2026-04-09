<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\ResourcePolicyStore;
use DynamikDev\PolicyEngine\DTOs\Condition;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\Enums\Effect;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        ->and($condition->parameters)->toBe(['attribute' => 'department_id', 'value' => 1]);
});
