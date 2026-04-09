<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Concerns\HasPermissions;
use DynamikDev\PolicyEngine\Concerns\Scopeable;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\DTOs\Context;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Models\Assignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('benchmark');

// --- Models ---

class BenchUser extends Authenticatable
{
    use HasPermissions;

    protected $table = 'bench_users';

    protected $guarded = [];
}

class BenchGroup extends Model
{
    use Scopeable;

    protected $table = 'bench_groups';

    protected $guarded = [];
}

// --- Setup ---

beforeEach(function (): void {
    Schema::create('bench_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('bench_groups', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
    $this->boundaryStore = app(BoundaryStore::class);

    // The container-resolved evaluator is a CachedEvaluator wrapping DefaultEvaluator.
    $this->cachedEvaluator = app(Evaluator::class);

    // Extract the inner DefaultEvaluator for direct benchmarking.
    $reflection = new ReflectionClass($this->cachedEvaluator);
    $innerProp = $reflection->getProperty('inner');
    $this->defaultEvaluator = $innerProp->getValue($this->cachedEvaluator);

    config()->set('policy-engine.cache.enabled', true);
    config()->set('policy-engine.cache.store', 'array');
    config()->set('policy-engine.cache.ttl', 3600);
});

afterEach(function (): void {
    Schema::dropIfExists('bench_groups');
    Schema::dropIfExists('bench_users');
});

// --- Helpers ---

/**
 * Build an EvaluationRequest from type, id, action, and optional scope string.
 */
function benchRequest(string $type, string|int $id, string $action, ?string $scope = null): EvaluationRequest
{
    return new EvaluationRequest(
        principal: new Principal(type: $type, id: $id),
        action: $action,
        resource: null,
        context: new Context(scope: $scope),
    );
}

/**
 * Evaluate a permission check using the given evaluator instance.
 */
function benchCan(Evaluator $evaluator, string $type, string|int $id, string $action, ?string $scope = null): bool
{
    return $evaluator->evaluate(benchRequest($type, $id, $action, $scope))->decision === Decision::Allow;
}

/**
 * Check role membership directly via AssignmentStore (replaces evaluator->hasRole()).
 */
function benchHasRole(AssignmentStore $store, string $type, string|int $id, string $role, ?string $scope = null): bool
{
    if ($scope !== null) {
        return $store->forSubjectGlobalAndScope($type, $id, $scope)->contains('role_id', $role);
    }

    return $store->forSubjectGlobal($type, $id)->contains('role_id', $role);
}

function seedGroupsPlatform(object $test, int $users, int $groups, int $groupsPerUser): void
{
    // Permissions
    $test->permissionStore->register([
        'posts.create', 'posts.read', 'posts.update.own', 'posts.update.any',
        'posts.delete.own', 'posts.delete.any', 'comments.create', 'comments.delete',
        'members.invite', 'members.remove', 'settings.manage',
    ]);

    // Roles
    $test->roleStore->save('member', 'Member', ['posts.create', 'posts.read', 'posts.update.own', 'posts.delete.own', 'comments.create']);
    $test->roleStore->save('moderator', 'Moderator', ['posts.read', 'posts.update.any', 'posts.delete.any', 'comments.create', 'comments.delete', 'members.invite']);
    $test->roleStore->save('admin', 'Admin', ['posts.*', 'comments.*', 'members.*', 'settings.manage']);
    $test->roleStore->save('restricted', 'Restricted', ['posts.read', '!posts.create']);

    // Boundaries
    $test->boundaryStore->set('free-group', ['posts.*', 'comments.*']);
    $test->boundaryStore->set('paid-group', ['posts.*', 'comments.*', 'members.*', 'settings.*']);

    // Users (batch to avoid SQLite variable limit)
    $now = now();
    foreach (array_chunk(range(1, $users), 500) as $chunk) {
        $rows = array_map(fn (int $i) => ['id' => $i, 'name' => "User {$i}", 'created_at' => $now, 'updated_at' => $now], $chunk);
        BenchUser::query()->insert($rows);
    }

    // Groups
    foreach (array_chunk(range(1, $groups), 500) as $chunk) {
        $rows = array_map(fn (int $i) => ['id' => $i, 'name' => "Group {$i}", 'created_at' => $now, 'updated_at' => $now], $chunk);
        BenchGroup::query()->insert($rows);
    }

    // Assignments — each user joins N random groups
    $roles = ['member', 'member', 'member', 'moderator', 'admin'];
    $assignmentRows = [];

    for ($userId = 1; $userId <= $users; $userId++) {
        $userGroups = array_rand(range(1, $groups), min($groupsPerUser, $groups));

        if (! is_array($userGroups)) {
            $userGroups = [$userGroups];
        }

        foreach ($userGroups as $groupIndex) {
            $groupId = $groupIndex + 1;
            $role = $roles[array_rand($roles)];

            $assignmentRows[] = [
                'subject_type' => BenchUser::class,
                'subject_id' => $userId,
                'role_id' => $role,
                'scope' => "benchgroup::{$groupId}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Batch insert every 500 rows to avoid memory issues.
        if (count($assignmentRows) >= 500) {
            Assignment::query()->insert($assignmentRows);
            $assignmentRows = [];
        }
    }

    if ($assignmentRows !== []) {
        Assignment::query()->insert($assignmentRows);
    }
}

function benchmark(Closure $fn, int $iterations = 100): array
{
    // Warmup
    $fn();

    $times = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1_000_000; // ms
    }

    sort($times);
    $count = count($times);

    return [
        'iterations' => $count,
        'avg_ms' => round(array_sum($times) / $count, 3),
        'p50_ms' => round($times[(int) ($count * 0.5)], 3),
        'p95_ms' => round($times[(int) ($count * 0.95)], 3),
        'p99_ms' => round($times[(int) ($count * 0.99)], 3),
        'min_ms' => round($times[0], 3),
        'max_ms' => round($times[$count - 1], 3),
    ];
}

function benchReport(string $label, array $stats): void
{
    echo sprintf(
        "  %-45s avg: %7.3fms  p50: %7.3fms  p95: %7.3fms  p99: %7.3fms\n",
        $label,
        $stats['avg_ms'],
        $stats['p50_ms'],
        $stats['p95_ms'],
        $stats['p99_ms'],
    );
}

// --- Benchmarks: Small scale (100 users, 20 groups) ---

it('benchmarks small scale: 100 users, 20 groups', function (): void {
    seedGroupsPlatform($this, users: 100, groups: 20, groupsPerUser: 5);

    $scope = 'benchgroup::1';

    echo "\n\n  SMALL SCALE: 100 users, 20 groups, ~500 assignments\n";
    echo '  '.str_repeat('-', 75)."\n";

    // evaluate() — cache miss (first call, evaluates from DB)
    config()->set('policy-engine.cache.enabled', false);
    benchReport('evaluate() uncached', benchmark(
        fn () => benchCan($this->defaultEvaluator, BenchUser::class, 1, 'posts.create', $scope),
    ));

    // evaluate() — cache hit
    config()->set('policy-engine.cache.enabled', true);
    benchCan($this->cachedEvaluator, BenchUser::class, 1, 'posts.create', $scope); // warm
    benchReport('evaluate() cached', benchmark(
        fn () => benchCan($this->cachedEvaluator, BenchUser::class, 1, 'posts.create', $scope),
    ));

    // hasRole() via AssignmentStore — uncached
    config()->set('policy-engine.cache.enabled', false);
    benchReport('hasRole() uncached', benchmark(
        fn () => benchHasRole($this->assignmentStore, BenchUser::class, 1, 'member', $scope),
    ));

    // effectivePermissions() — uncached via BenchUser model
    config()->set('policy-engine.cache.enabled', false);
    $benchUser = BenchUser::query()->find(1);
    benchReport('effectivePermissions() uncached', benchmark(
        fn () => $benchUser->effectivePermissions($scope),
        iterations: 50,
    ));

    // Wildcard match
    config()->set('policy-engine.cache.enabled', false);
    benchReport('evaluate() wildcard posts.* uncached', benchmark(
        fn () => benchCan($this->defaultEvaluator, BenchUser::class, 1, 'posts.delete.own', $scope),
    ));

    // Deny rule check
    $this->assignmentStore->assign(BenchUser::class, 1, 'restricted', $scope);
    benchReport('evaluate() with deny rule uncached', benchmark(
        fn () => benchCan($this->defaultEvaluator, BenchUser::class, 1, 'posts.create', $scope),
    ));

    echo "\n";
    expect(true)->toBeTrue();
});

// --- Benchmarks: Medium scale (1,000 users, 100 groups) ---

it('benchmarks medium scale: 1,000 users, 100 groups', function (): void {
    seedGroupsPlatform($this, users: 1_000, groups: 100, groupsPerUser: 8);

    echo "\n\n  MEDIUM SCALE: 1,000 users, 100 groups, ~8,000 assignments\n";
    echo '  '.str_repeat('-', 75)."\n";

    $scope = 'benchgroup::1';

    config()->set('policy-engine.cache.enabled', false);
    benchReport('evaluate() uncached', benchmark(
        fn () => benchCan($this->defaultEvaluator, BenchUser::class, 1, 'posts.create', $scope),
    ));

    config()->set('policy-engine.cache.enabled', true);
    benchCan($this->cachedEvaluator, BenchUser::class, 1, 'posts.create', $scope);
    benchReport('evaluate() cached', benchmark(
        fn () => benchCan($this->cachedEvaluator, BenchUser::class, 1, 'posts.create', $scope),
    ));

    // Simulate write pressure: assign + invalidate + re-evaluate
    config()->set('policy-engine.cache.enabled', false);
    $assignCount = 0;
    benchReport('assign() + evaluate() cycle', benchmark(function () use (&$assignCount, $scope): void {
        $assignCount++;
        $userId = ($assignCount % 1000) + 1;
        $this->assignmentStore->assign(BenchUser::class, $userId, 'member', $scope);
        benchCan($this->defaultEvaluator, BenchUser::class, $userId, 'posts.create', $scope);
    }, iterations: 50));

    echo "\n";
    expect(true)->toBeTrue();
});

// --- Benchmarks: Large scale (10,000 users, 500 groups) ---

it('benchmarks large scale: 10,000 users, 500 groups', function (): void {
    seedGroupsPlatform($this, users: 10_000, groups: 500, groupsPerUser: 10);

    $assignmentCount = Assignment::query()->count();

    echo "\n\n  LARGE SCALE: 10,000 users, 500 groups, ~{$assignmentCount} assignments\n";
    echo '  '.str_repeat('-', 75)."\n";

    $scope = 'benchgroup::1';

    // Uncached reads
    config()->set('policy-engine.cache.enabled', false);
    benchReport('evaluate() uncached', benchmark(
        fn () => benchCan($this->defaultEvaluator, BenchUser::class, 1, 'posts.create', $scope),
    ));

    benchReport('hasRole() uncached', benchmark(
        fn () => benchHasRole($this->assignmentStore, BenchUser::class, 1, 'member', $scope),
    ));

    $benchUser = BenchUser::query()->find(1);
    benchReport('effectivePermissions() uncached', benchmark(
        fn () => $benchUser->effectivePermissions($scope),
        iterations: 50,
    ));

    // Cached reads
    config()->set('policy-engine.cache.enabled', true);
    benchCan($this->cachedEvaluator, BenchUser::class, 1, 'posts.create', $scope);
    benchReport('evaluate() cached', benchmark(
        fn () => benchCan($this->cachedEvaluator, BenchUser::class, 1, 'posts.create', $scope),
    ));

    // Different users (cold cache per user, warm DB)
    config()->set('policy-engine.cache.enabled', false);
    $userCycle = 0;
    benchReport('evaluate() across 100 different users', benchmark(function () use (&$userCycle, $scope): void {
        $userCycle++;
        $userId = ($userCycle % 100) + 1;
        benchCan($this->defaultEvaluator, BenchUser::class, $userId, 'posts.create', $scope);
    }));

    // 10 permission checks for same user (simulates page render)
    $permissions = [
        'posts.create', 'posts.read', 'posts.update.own', 'posts.delete.own',
        'comments.create', 'comments.delete', 'members.invite', 'members.remove',
        'settings.manage', 'posts.update.any',
    ];
    config()->set('policy-engine.cache.enabled', true);
    benchReport('10 evaluate() checks same user (page render)', benchmark(function () use ($permissions, $scope): void {
        foreach ($permissions as $perm) {
            benchCan($this->cachedEvaluator, BenchUser::class, 1, $perm, $scope);
        }
    }, iterations: 50));

    echo "\n";
    expect(true)->toBeTrue();
});
