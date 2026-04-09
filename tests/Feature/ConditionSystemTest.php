<?php

declare(strict_types=1);

use Carbon\Carbon;
use DynamikDev\PolicyEngine\Conditions\AttributeEqualsEvaluator;
use DynamikDev\PolicyEngine\Conditions\AttributeInEvaluator;
use DynamikDev\PolicyEngine\Conditions\DefaultConditionRegistry;
use DynamikDev\PolicyEngine\Conditions\EnvironmentEqualsEvaluator;
use DynamikDev\PolicyEngine\Conditions\IpRangeEvaluator;
use DynamikDev\PolicyEngine\Conditions\TimeBetweenEvaluator;
use DynamikDev\PolicyEngine\Contracts\ConditionRegistry;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\DTOs\Condition;
use DynamikDev\PolicyEngine\DTOs\Context;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\DTOs\Resource;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Enums\Effect;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use Illuminate\Support\Collection;

/* Helpers */

function conditionRequest(
    string $action = 'posts.read',
    ?Resource $resource = null,
    array $principalAttributes = [],
    array $environment = [],
    ?string $scope = null,
): EvaluationRequest {
    return new EvaluationRequest(
        principal: new Principal(type: 'user', id: 1, attributes: $principalAttributes),
        action: $action,
        resource: $resource,
        context: new Context(scope: $scope, environment: $environment),
    );
}

function conditionResolver(array $statements): PolicyResolver
{
    return new class($statements) implements PolicyResolver
    {
        public function __construct(private readonly array $statements) {}

        public function resolve(EvaluationRequest $request): Collection
        {
            return collect($this->statements);
        }
    };
}

function makeRegistry(): DefaultConditionRegistry
{
    $registry = new DefaultConditionRegistry;
    $registry->register('attribute_equals', AttributeEqualsEvaluator::class);
    $registry->register('attribute_in', AttributeInEvaluator::class);
    $registry->register('environment_equals', EnvironmentEqualsEvaluator::class);
    $registry->register('ip_range', IpRangeEvaluator::class);
    $registry->register('time_between', TimeBetweenEvaluator::class);

    return $registry;
}

/* Registry tests */

it('resolves a registered evaluator by type', function (): void {
    $registry = new DefaultConditionRegistry;
    $registry->register('attribute_equals', AttributeEqualsEvaluator::class);

    $evaluator = $registry->evaluatorFor('attribute_equals');

    expect($evaluator)->toBeInstanceOf(AttributeEqualsEvaluator::class);
});

it('throws InvalidArgumentException for an unknown condition type', function (): void {
    $registry = new DefaultConditionRegistry;

    expect(fn () => $registry->evaluatorFor('nonexistent'))->toThrow(InvalidArgumentException::class);
});

it('registers the built-in types via the service container binding', function (): void {
    $registry = app(ConditionRegistry::class);

    expect($registry->evaluatorFor('attribute_equals'))->toBeInstanceOf(AttributeEqualsEvaluator::class)
        ->and($registry->evaluatorFor('attribute_in'))->toBeInstanceOf(AttributeInEvaluator::class)
        ->and($registry->evaluatorFor('environment_equals'))->toBeInstanceOf(EnvironmentEqualsEvaluator::class)
        ->and($registry->evaluatorFor('ip_range'))->toBeInstanceOf(IpRangeEvaluator::class)
        ->and($registry->evaluatorFor('time_between'))->toBeInstanceOf(TimeBetweenEvaluator::class);
});

/* AttributeEqualsEvaluator tests */

it('AttributeEqualsEvaluator passes when subject and resource attribute values match', function (): void {
    $evaluator = new AttributeEqualsEvaluator;
    $condition = new Condition('attribute_equals', ['subject_key' => 'dept', 'resource_key' => 'dept']);

    $request = conditionRequest(
        resource: new Resource(type: 'doc', id: 1, attributes: ['dept' => 'engineering']),
        principalAttributes: ['dept' => 'engineering'],
    );

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('AttributeEqualsEvaluator fails when subject and resource attribute values differ', function (): void {
    $evaluator = new AttributeEqualsEvaluator;
    $condition = new Condition('attribute_equals', ['subject_key' => 'dept', 'resource_key' => 'dept']);

    $request = conditionRequest(
        resource: new Resource(type: 'doc', id: 1, attributes: ['dept' => 'marketing']),
        principalAttributes: ['dept' => 'engineering'],
    );

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('AttributeEqualsEvaluator fails when resource is null', function (): void {
    $evaluator = new AttributeEqualsEvaluator;
    $condition = new Condition('attribute_equals', ['subject_key' => 'dept', 'resource_key' => 'dept']);

    $request = conditionRequest(principalAttributes: ['dept' => 'engineering']);

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('AttributeEqualsEvaluator fails when subject key is missing from principal attributes', function (): void {
    $evaluator = new AttributeEqualsEvaluator;
    $condition = new Condition('attribute_equals', ['subject_key' => 'dept', 'resource_key' => 'dept']);

    $request = conditionRequest(
        resource: new Resource(type: 'doc', id: 1, attributes: ['dept' => 'engineering']),
        principalAttributes: [],
    );

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('AttributeEqualsEvaluator fails when resource key is missing from resource attributes', function (): void {
    $evaluator = new AttributeEqualsEvaluator;
    $condition = new Condition('attribute_equals', ['subject_key' => 'dept', 'resource_key' => 'dept']);

    $request = conditionRequest(
        resource: new Resource(type: 'doc', id: 1, attributes: []),
        principalAttributes: ['dept' => 'engineering'],
    );

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

/* AttributeInEvaluator tests */

it('AttributeInEvaluator passes when principal attribute value is in the allowed set', function (): void {
    $evaluator = new AttributeInEvaluator;
    $condition = new Condition('attribute_in', [
        'source' => 'principal',
        'key' => 'role',
        'values' => ['admin', 'editor'],
    ]);

    $request = conditionRequest(principalAttributes: ['role' => 'editor']);

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('AttributeInEvaluator fails when principal attribute value is not in the allowed set', function (): void {
    $evaluator = new AttributeInEvaluator;
    $condition = new Condition('attribute_in', [
        'source' => 'principal',
        'key' => 'role',
        'values' => ['admin', 'editor'],
    ]);

    $request = conditionRequest(principalAttributes: ['role' => 'viewer']);

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('AttributeInEvaluator passes when resource attribute value is in the allowed set', function (): void {
    $evaluator = new AttributeInEvaluator;
    $condition = new Condition('attribute_in', [
        'source' => 'resource',
        'key' => 'status',
        'values' => ['published', 'draft'],
    ]);

    $request = conditionRequest(
        resource: new Resource(type: 'post', id: 1, attributes: ['status' => 'draft']),
    );

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('AttributeInEvaluator passes when environment value is in the allowed set', function (): void {
    $evaluator = new AttributeInEvaluator;
    $condition = new Condition('attribute_in', [
        'source' => 'environment',
        'key' => 'region',
        'values' => ['us-east-1', 'us-west-2'],
    ]);

    $request = conditionRequest(environment: ['region' => 'us-east-1']);

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('AttributeInEvaluator uses strict type comparison', function (): void {
    $evaluator = new AttributeInEvaluator;
    $condition = new Condition('attribute_in', [
        'source' => 'principal',
        'key' => 'level',
        'values' => [1, 2, 3],
    ]);

    // String "1" should NOT match integer 1 in strict mode
    $request = conditionRequest(principalAttributes: ['level' => '1']);

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

/* EnvironmentEqualsEvaluator tests */

it('EnvironmentEqualsEvaluator passes when env key matches expected value', function (): void {
    $evaluator = new EnvironmentEqualsEvaluator;
    $condition = new Condition('environment_equals', ['key' => 'env', 'value' => 'production']);

    $request = conditionRequest(environment: ['env' => 'production']);

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('EnvironmentEqualsEvaluator fails when env key value differs from expected', function (): void {
    $evaluator = new EnvironmentEqualsEvaluator;
    $condition = new Condition('environment_equals', ['key' => 'env', 'value' => 'production']);

    $request = conditionRequest(environment: ['env' => 'staging']);

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('EnvironmentEqualsEvaluator fails when env key is absent', function (): void {
    $evaluator = new EnvironmentEqualsEvaluator;
    $condition = new Condition('environment_equals', ['key' => 'env', 'value' => 'production']);

    $request = conditionRequest(environment: []);

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

/* IpRangeEvaluator tests */

it('IpRangeEvaluator passes when IP is within a CIDR range', function (): void {
    $evaluator = new IpRangeEvaluator;
    $condition = new Condition('ip_range', ['ranges' => ['10.0.0.0/8']]);

    $request = conditionRequest(environment: ['ip' => '10.5.6.7']);

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('IpRangeEvaluator fails when IP is outside all CIDR ranges', function (): void {
    $evaluator = new IpRangeEvaluator;
    $condition = new Condition('ip_range', ['ranges' => ['10.0.0.0/8']]);

    $request = conditionRequest(environment: ['ip' => '192.168.1.1']);

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('IpRangeEvaluator passes when IP matches an exact IP in ranges', function (): void {
    $evaluator = new IpRangeEvaluator;
    $condition = new Condition('ip_range', ['ranges' => ['192.168.1.100']]);

    $request = conditionRequest(environment: ['ip' => '192.168.1.100']);

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('IpRangeEvaluator fails when no IP is in the environment', function (): void {
    $evaluator = new IpRangeEvaluator;
    $condition = new Condition('ip_range', ['ranges' => ['10.0.0.0/8']]);

    $request = conditionRequest(environment: []);

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('IpRangeEvaluator passes when IP matches any of multiple ranges', function (): void {
    $evaluator = new IpRangeEvaluator;
    $condition = new Condition('ip_range', ['ranges' => ['10.0.0.0/8', '172.16.0.0/12']]);

    $request = conditionRequest(environment: ['ip' => '172.20.5.1']);

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('IpRangeEvaluator handles a /32 single-host CIDR correctly', function (): void {
    $evaluator = new IpRangeEvaluator;
    $condition = new Condition('ip_range', ['ranges' => ['10.0.0.1/32']]);

    $inRange = conditionRequest(environment: ['ip' => '10.0.0.1']);
    $outOfRange = conditionRequest(environment: ['ip' => '10.0.0.2']);

    expect($evaluator->passes($condition, $inRange))->toBeTrue()
        ->and($evaluator->passes($condition, $outOfRange))->toBeFalse();
});

/* TimeBetweenEvaluator tests */

it('TimeBetweenEvaluator passes when current time falls within the window', function (): void {
    $evaluator = new TimeBetweenEvaluator;
    $now = Carbon::now('UTC');

    // Build a window that starts 1 hour ago and ends 1 hour from now
    $start = $now->copy()->subHour()->format('H:i');
    $end = $now->copy()->addHour()->format('H:i');

    $condition = new Condition('time_between', [
        'start' => $start,
        'end' => $end,
        'timezone' => 'UTC',
    ]);

    $request = conditionRequest();

    expect($evaluator->passes($condition, $request))->toBeTrue();
});

it('TimeBetweenEvaluator fails when current time is outside the window', function (): void {
    $evaluator = new TimeBetweenEvaluator;
    $now = Carbon::now('UTC');

    // Build a window 2 hours ago that ended 1 hour ago
    $start = $now->copy()->subHours(2)->format('H:i');
    $end = $now->copy()->subHour()->format('H:i');

    // Only do this test when start < end (no midnight wrap)
    if ($start >= $end) {
        expect(true)->toBeTrue(); // skip gracefully

        return;
    }

    $condition = new Condition('time_between', [
        'start' => $start,
        'end' => $end,
        'timezone' => 'UTC',
    ]);

    $request = conditionRequest();

    expect($evaluator->passes($condition, $request))->toBeFalse();
});

it('TimeBetweenEvaluator returns false when start parameter is missing', function (): void {
    $evaluator = new TimeBetweenEvaluator;
    $condition = new Condition('time_between', ['end' => '18:00', 'timezone' => 'UTC']);

    expect($evaluator->passes($condition, conditionRequest()))->toBeFalse();
});

it('TimeBetweenEvaluator returns false when end parameter is missing', function (): void {
    $evaluator = new TimeBetweenEvaluator;
    $condition = new Condition('time_between', ['start' => '09:00', 'timezone' => 'UTC']);

    expect($evaluator->passes($condition, conditionRequest()))->toBeFalse();
});

/* Integration: conditions wired into DefaultEvaluator */

it('DefaultEvaluator skips a statement when its condition fails', function (): void {
    $registry = makeRegistry();

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.read',
        conditions: [
            new Condition('environment_equals', ['key' => 'env', 'value' => 'production']),
        ],
        source: 'policy:env-gate',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [conditionResolver([$statement])],
        matcher: app(Matcher::class),
        conditionRegistry: $registry,
    );

    // Staging environment — condition should fail, statement excluded, default-deny applies
    $request = conditionRequest(environment: ['env' => 'staging']);

    $result = $evaluator->evaluate($request);

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('default-deny');
});

it('DefaultEvaluator applies a statement when its condition passes', function (): void {
    $registry = makeRegistry();

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.read',
        conditions: [
            new Condition('environment_equals', ['key' => 'env', 'value' => 'production']),
        ],
        source: 'policy:env-gate',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [conditionResolver([$statement])],
        matcher: app(Matcher::class),
        conditionRegistry: $registry,
    );

    // Production environment — condition passes, Allow granted
    $request = conditionRequest(environment: ['env' => 'production']);

    $result = $evaluator->evaluate($request);

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('policy:env-gate');
});

it('DefaultEvaluator applies a statement with no conditions regardless of registry', function (): void {
    $registry = makeRegistry();

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.read',
        conditions: [],
        source: 'policy:unconditional',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [conditionResolver([$statement])],
        matcher: app(Matcher::class),
        conditionRegistry: $registry,
    );

    $result = $evaluator->evaluate(conditionRequest());

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('policy:unconditional');
});

it('DefaultEvaluator applies statements when conditionRegistry is null and conditions are present', function (): void {
    // Without a registry wired in, conditions are bypassed (treated as passing)
    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.read',
        conditions: [
            new Condition('environment_equals', ['key' => 'env', 'value' => 'production']),
        ],
        source: 'policy:no-registry',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [conditionResolver([$statement])],
        matcher: app(Matcher::class),
        conditionRegistry: null,
    );

    $result = $evaluator->evaluate(conditionRequest(environment: ['env' => 'staging']));

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->decidedBy)->toBe('policy:no-registry');
});

it('DefaultEvaluator evaluates all conditions and fails fast on the first failure', function (): void {
    $registry = makeRegistry();

    $statement = new PolicyStatement(
        effect: Effect::Allow,
        action: 'posts.read',
        conditions: [
            new Condition('environment_equals', ['key' => 'env', 'value' => 'production']),
            new Condition('attribute_in', ['source' => 'principal', 'key' => 'role', 'values' => ['admin']]),
        ],
        source: 'policy:multi-condition',
    );

    $evaluator = new DefaultEvaluator(
        resolvers: [conditionResolver([$statement])],
        matcher: app(Matcher::class),
        conditionRegistry: $registry,
    );

    // env matches but role does not — overall should deny
    $request = conditionRequest(
        principalAttributes: ['role' => 'editor'],
        environment: ['env' => 'production'],
    );

    $result = $evaluator->evaluate($request);

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('default-deny');
});
