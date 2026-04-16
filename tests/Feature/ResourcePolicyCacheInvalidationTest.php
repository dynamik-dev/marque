<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\DTOs\Resource;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Events\ResourcePolicyAttached;
use DynamikDev\Marque\Events\ResourcePolicyDetached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('marque.cache.enabled', true);
    config()->set('marque.cache.store', 'array');
    config()->set('marque.cache.ttl', 3600);

    $this->store = app(ResourcePolicyStore::class);
    $this->evaluator = app(Evaluator::class);
});

it('dispatches ResourcePolicyAttached when a policy is attached', function (): void {
    Event::fake([ResourcePolicyAttached::class]);

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );

    $this->store->attach('App\\Models\\Document', 42, $statement);

    Event::assertDispatched(ResourcePolicyAttached::class, function (ResourcePolicyAttached $event): bool {
        return $event->resourceType === 'App\\Models\\Document'
            && $event->resourceId === 42
            && $event->action === 'documents.read';
    });
});

it('dispatches ResourcePolicyDetached when a policy is removed', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    );
    $this->store->attach('App\\Models\\Document', 42, $statement);

    Event::fake([ResourcePolicyDetached::class]);

    $this->store->detach('App\\Models\\Document', 42, 'documents.read');

    Event::assertDispatched(ResourcePolicyDetached::class, function (ResourcePolicyDetached $event): bool {
        return $event->resourceType === 'App\\Models\\Document'
            && $event->resourceId === 42
            && $event->action === 'documents.read';
    });
});

it('does not dispatch ResourcePolicyDetached when nothing matches the detach query', function (): void {
    Event::fake([ResourcePolicyDetached::class]);

    $this->store->detach('App\\Models\\Document', 999, 'documents.read');

    Event::assertNotDispatched(ResourcePolicyDetached::class);
});

it('invalidates cached evaluations when a resource policy is attached', function (): void {
    $request = new EvaluationRequest(
        principal: new Principal(type: 'App\\Models\\User', id: 1),
        action: 'documents.read',
        resource: new Resource(type: 'App\\Models\\Document', id: 99),
        context: new Context,
    );

    // First evaluation: no policies, so denied. The result is cached.
    expect($this->evaluator->evaluate($request)->decision)->toBe(Decision::Deny);

    // Attach an Allow policy for this exact resource.
    $this->store->attach('App\\Models\\Document', 99, new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    ));

    // The cached Deny must be invalidated; re-evaluation should now Allow.
    expect($this->evaluator->evaluate($request)->decision)->toBe(Decision::Allow);
});

it('invalidates cached evaluations when a resource policy is detached', function (): void {
    $this->store->attach('App\\Models\\Document', 100, new PolicyStatement(
        effect: Effect::Allow,
        action: 'documents.read',
        principalPattern: null,
        resourcePattern: null,
        conditions: [],
        source: '',
    ));

    $request = new EvaluationRequest(
        principal: new Principal(type: 'App\\Models\\User', id: 2),
        action: 'documents.read',
        resource: new Resource(type: 'App\\Models\\Document', id: 100),
        context: new Context,
    );

    // First evaluation: policy grants Allow; result is cached.
    expect($this->evaluator->evaluate($request)->decision)->toBe(Decision::Allow);

    $this->store->detach('App\\Models\\Document', 100, 'documents.read');

    // The cached Allow must be invalidated; re-evaluation should now Deny.
    expect($this->evaluator->evaluate($request)->decision)->toBe(Decision::Deny);
});
