<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\DTOs\Context;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\EvaluationResult;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Evaluators\CachedEvaluator;
use Illuminate\Cache\CacheManager;

function makeTestRequest(string $action, ?string $scope = null): EvaluationRequest
{
    return new EvaluationRequest(
        principal: new Principal(type: 'user', id: 1),
        action: $action,
        resource: null,
        context: new Context(scope: $scope),
    );
}

function makeAllowResult(): EvaluationResult
{
    return new EvaluationResult(
        decision: Decision::Allow,
        decidedBy: 'role:editor',
    );
}

function makeDenyResult(): EvaluationResult
{
    return new EvaluationResult(
        decision: Decision::Deny,
        decidedBy: 'default-deny',
    );
}

beforeEach(function (): void {
    config()->set('policy-engine.cache.enabled', true);
    config()->set('policy-engine.cache.store', 'array');
    config()->set('policy-engine.cache.ttl', 300);
});

// --- Delegates on miss ---

it('calls inner evaluator once on cache miss and returns result', function (): void {
    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.create');
    $expected = makeAllowResult();

    $inner->expects('evaluate')
        ->once()
        ->with(Mockery::type(EvaluationRequest::class))
        ->andReturn($expected);

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));
    $result = $evaluator->evaluate($request);

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('role:editor');
});

// --- Returns cached on hit ---

it('returns cached result on second call without calling inner again', function (): void {
    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.read');

    $inner->expects('evaluate')
        ->once()
        ->andReturn(makeAllowResult());

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $first = $evaluator->evaluate($request);
    $second = $evaluator->evaluate($request);

    expect($first->decision)->toBe(Decision::Allow)
        ->and($second->decision)->toBe(Decision::Allow);
});

// --- Bypasses cache when disabled ---

it('bypasses cache when cache is disabled and calls inner twice', function (): void {
    config()->set('policy-engine.cache.enabled', false);

    $inner = Mockery::mock(Evaluator::class);
    $request = makeTestRequest('posts.delete');

    $inner->expects('evaluate')
        ->twice()
        ->andReturn(makeDenyResult());

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $evaluator->evaluate($request);
    $evaluator->evaluate($request);
});

// --- Different requests cached separately ---

it('caches different actions under separate keys and calls inner once each', function (): void {
    $inner = Mockery::mock(Evaluator::class);
    $createRequest = makeTestRequest('posts.create');
    $deleteRequest = makeTestRequest('posts.delete');

    $inner->expects('evaluate')
        ->twice()
        ->andReturnUsing(function (EvaluationRequest $req): EvaluationResult {
            return $req->action === 'posts.create' ? makeAllowResult() : makeDenyResult();
        });

    $evaluator = new CachedEvaluator(inner: $inner, cache: app(CacheManager::class));

    $createResult = $evaluator->evaluate($createRequest);
    $deleteResult = $evaluator->evaluate($deleteRequest);

    // Call again to verify cache hit — no additional inner calls.
    $evaluator->evaluate($createRequest);
    $evaluator->evaluate($deleteRequest);

    expect($createResult->decision)->toBe(Decision::Allow)
        ->and($deleteResult->decision)->toBe(Decision::Deny);
});
