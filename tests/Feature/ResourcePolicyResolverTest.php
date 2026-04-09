<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\ResourcePolicyStore;
use DynamikDev\PolicyEngine\DTOs\Context;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\DTOs\Resource;
use DynamikDev\PolicyEngine\Enums\Effect;
use DynamikDev\PolicyEngine\Resolvers\ResourcePolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = app(ResourcePolicyStore::class);
    $this->resolver = new ResourcePolicyResolver($this->store);
});

it('returns an empty collection when the request has no resource', function (): void {
    $request = new EvaluationRequest(
        principal: new Principal(type: 'App\\Models\\User', id: 1),
        action: 'documents.read',
        resource: null,
        context: new Context,
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toBeEmpty();
});

it('returns policies attached to the requested resource', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 5, $statement);

    $request = new EvaluationRequest(
        principal: new Principal(type: 'App\\Models\\User', id: 1),
        action: 'documents.read',
        resource: new Resource(type: 'App\\Models\\Document', id: 5),
        context: new Context,
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toHaveCount(1)
        ->and($statements->first()->action)->toBe('documents.read')
        ->and($statements->first()->effect)->toBe(Effect::Allow);
});

it('returns type-level policies when querying a specific resource instance', function (): void {
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
    $this->store->attach('App\\Models\\Document', 9, $instanceStatement);

    $request = new EvaluationRequest(
        principal: new Principal(type: 'App\\Models\\User', id: 1),
        action: 'documents.read',
        resource: new Resource(type: 'App\\Models\\Document', id: 9),
        context: new Context,
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toHaveCount(2);

    $actions = $statements->pluck('action')->sort()->values()->all();
    expect($actions)->toBe(['documents.delete', 'documents.read']);
});

it('returns no policies when resource type does not match', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 1, $statement);

    $request = new EvaluationRequest(
        principal: new Principal(type: 'App\\Models\\User', id: 1),
        action: 'documents.read',
        resource: new Resource(type: 'App\\Models\\Invoice', id: 1),
        context: new Context,
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toBeEmpty();
});
