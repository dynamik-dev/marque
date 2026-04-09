<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\DTOs\Context;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\EvaluationResult;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\DTOs\Resource;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Enums\Effect;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use Illuminate\Support\Collection;

function makeRequest(string $action, ?Resource $resource = null, ?string $scope = null): EvaluationRequest
{
    $context = $scope !== null
        ? new Context(scope: $scope)
        : new Context;

    return new EvaluationRequest(
        principal: new Principal(type: 'user', id: 1),
        action: $action,
        resource: $resource,
        context: $context,
    );
}

/**
 * @param  PolicyStatement[]  $statements
 */
function makeResolver(array $statements): PolicyResolver
{
    return new class($statements) implements PolicyResolver
    {
        /**
         * @param  PolicyStatement[]  $statements
         */
        public function __construct(private readonly array $statements) {}

        public function resolve(EvaluationRequest $request): Collection
        {
            return collect($this->statements);
        }
    };
}

// a) Default deny — no statements from resolvers → Deny with decidedBy='default-deny'

it('returns default deny when no resolvers produce statements', function (): void {
    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result)->toBeInstanceOf(EvaluationResult::class)
        ->and($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('default-deny');
});

// b) Allow — matching Allow statement → Allow with correct decidedBy

it('returns allow when a matching Allow statement exists', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.create',
        source: 'role:editor',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('role:editor');
});

// c) Deny wins — both Allow and Deny for same action → Deny wins

it('returns deny when both Allow and Deny statements exist for the same action', function (): void {
    $allow = new PolicyStatement(effect: Effect::Allow, action: 'posts.delete', source: 'role:editor');
    $deny = new PolicyStatement(effect: Effect::Deny, action: 'posts.delete', source: 'role:restricted');

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$allow, $deny])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.delete'));

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('role:restricted');
});

// d) Wildcard matching — `posts.*` matches `posts.create`

it('matches a wildcard action pattern against a specific action', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.*',
        source: 'role:editor',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('role:editor');
});

// e) Multiple resolvers — statements collected from all resolvers, deny from any source wins

it('collects statements from all resolvers and deny from any source wins', function (): void {
    $allowResolver = makeResolver([
        new PolicyStatement(effect: Effect::Allow, action: 'posts.publish', source: 'role:editor'),
    ]);

    $denyResolver = makeResolver([
        new PolicyStatement(effect: Effect::Deny, action: 'posts.publish', source: 'policy:content-lock'),
    ]);

    $evaluator = new DefaultEvaluator(
        resolvers: [$allowResolver, $denyResolver],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.publish'));

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('policy:content-lock');
});

// f) Unrelated actions — statements for different actions don't match → default deny

it('returns default deny when statements exist but none match the requested action', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'comments.create',
        source: 'role:commenter',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('default-deny');
});

// g) Trace enabled — when `config('policy-engine.trace')` is true, matchedStatements populated

it('populates matchedStatements when trace is enabled', function (): void {
    config(['policy-engine.trace' => true]);

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.create',
        source: 'role:editor',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result->matchedStatements)->toHaveCount(1)
        ->and($result->matchedStatements[0])->toBe($statement);
});

// h) Trace disabled — when trace is false, matchedStatements is empty array

it('leaves matchedStatements empty when trace is disabled', function (): void {
    config(['policy-engine.trace' => false]);

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.create',
        source: 'role:editor',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result->matchedStatements)->toBe([]);
});

// i) Principal pattern matching — statement with `principalPattern: 'user:1'` matches principal type:user id:1

it('applies a statement when principalPattern matches the request principal', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.create',
        principalPattern: 'user:1',
        source: 'policy:explicit',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('policy:explicit');
});

// j) Principal pattern rejection — statement with `principalPattern: 'user:999'` doesn't match principal type:user id:1

it('skips a statement when principalPattern does not match the request principal', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.create',
        principalPattern: 'user:999',
        source: 'policy:other-user',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $result = $evaluator->evaluate(makeRequest('posts.create'));

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('default-deny');
});

// k) Resource pattern matching — statement with `resourcePattern: 'post:42'` matches resource type:post id:42

it('applies a statement when resourcePattern matches the request resource', function (): void {
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.edit',
        resourcePattern: 'post:42',
        source: 'policy:resource-grant',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [makeResolver([$statement])],
        matcher: app(Matcher::class),
    );

    $resource = new Resource(type: 'post', id: 42);
    $result = $evaluator->evaluate(makeRequest('posts.edit', $resource));

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('policy:resource-grant');
});
