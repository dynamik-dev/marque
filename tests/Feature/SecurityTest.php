<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter;
use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\PolicyEngineManager;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
    $this->assignmentStore = new EloquentAssignmentStore;
    $this->boundaryStore = new EloquentBoundaryStore;
});

// --- Finding 1: System role protection in save() ---

it('rejects permission changes on a protected system role', function (): void {
    config()->set('policy-engine.protect_system_roles', true);

    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'posts.delete'], system: true);

    $this->roleStore->save('admin', 'Admin', ['posts.create'], system: true);
})->throws(RuntimeException::class, 'Cannot modify permissions on protected system role');

it('rejects flipping is_system flag off on a protected role', function (): void {
    config()->set('policy-engine.protect_system_roles', true);

    $this->roleStore->save('admin', 'Admin', [], system: true);

    $this->roleStore->save('admin', 'Admin', [], system: false);
})->throws(RuntimeException::class, 'Cannot remove system flag from protected role');

it('allows modifying a system role when protection is disabled', function (): void {
    config()->set('policy-engine.protect_system_roles', false);

    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'posts.delete'], system: true);

    $role = $this->roleStore->save('admin', 'Admin', ['posts.create'], system: true);

    expect($role->id)->toBe('admin');
    expect($this->roleStore->permissionsFor('admin'))->toBe(['posts.create']);
});

it('allows updating the name of a protected system role', function (): void {
    config()->set('policy-engine.protect_system_roles', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['posts.create'], system: true);

    $role = $this->roleStore->save('admin', 'Super Admin', ['posts.create'], system: true);

    expect($role->name)->toBe('Super Admin');
});

// --- Finding 3: Document import skips protected system roles ---

it('skips protected system roles during import', function (): void {
    config()->set('policy-engine.protect_system_roles', true);

    $this->permissionStore->register(['posts.create', 'posts.delete', 'billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'posts.delete', 'billing.manage'], system: true);

    $document = new PolicyDocument(
        version: '1.0',
        permissions: ['posts.create'],
        roles: [
            ['id' => 'admin', 'name' => 'Weakened Admin', 'permissions' => ['posts.create'], 'system' => false],
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create']],
        ],
    );

    $importer = new DefaultDocumentImporter(
        permissionStore: $this->permissionStore,
        roleStore: $this->roleStore,
        assignmentStore: $this->assignmentStore,
        boundaryStore: $this->boundaryStore,
    );

    $result = $importer->import($document, new ImportOptions(merge: true));

    // Admin role should be untouched
    $admin = $this->roleStore->find('admin');
    expect($admin->name)->toBe('Admin')
        ->and($admin->is_system)->toBeTrue()
        ->and($this->roleStore->permissionsFor('admin'))->toHaveCount(3);

    // Editor role should have been created
    expect($result->rolesCreated)->toBe(['editor']);

    // Warning about skipped role
    expect($result->warnings)->toContain("Skipped protected system role 'admin' during import");
});

// --- Finding 7: SQL LIKE wildcard injection ---

it('escapes LIKE wildcards in permission prefix filter', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete', 'comments.create']);

    $filtered = $this->permissionStore->all('%');

    expect($filtered)->toBeEmpty();
});

it('escapes underscore wildcard in permission prefix filter', function (): void {
    $this->permissionStore->register(['posts.create', 'p_sts.create']);

    $filtered = $this->permissionStore->all('p_sts');

    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()->id)->toBe('p_sts.create');
});

// --- Finding 5: Path validation in PolicyEngineManager ---

it('rejects import from a path outside the allowed directory', function (): void {
    config()->set('policy-engine.document_path', storage_path());

    $manager = app(PolicyEngineManager::class);

    $manager->import('/etc/passwd');
})->throws(InvalidArgumentException::class, 'Path must be within the allowed directory');

it('rejects export to a path outside the allowed directory', function (): void {
    config()->set('policy-engine.document_path', storage_path());

    $manager = app(PolicyEngineManager::class);

    $manager->exportToFile('/tmp/evil.json');
})->throws(InvalidArgumentException::class, 'Path must be within the allowed directory');

it('rejects export to a sibling directory that shares the allowed path prefix', function (): void {
    $storagePath = storage_path();

    if (! is_dir($storagePath)) {
        mkdir($storagePath, 0755, true);
    }

    config()->set('policy-engine.document_path', $storagePath);

    $sibling = $storagePath.'-sibling-'.uniqid('', true);

    mkdir($sibling, 0755, true);

    $manager = app(PolicyEngineManager::class);

    try {
        $manager->exportToFile($sibling.'/evil.json');
    } finally {
        if (file_exists($sibling.'/evil.json')) {
            unlink($sibling.'/evil.json');
        }

        if (is_dir($sibling)) {
            rmdir($sibling);
        }
    }
})->throws(InvalidArgumentException::class, 'Path must be within the allowed directory');

it('allows import from a path within the allowed directory', function (): void {
    $storagePath = storage_path();

    if (! is_dir($storagePath)) {
        mkdir($storagePath, 0755, true);
    }

    config()->set('policy-engine.document_path', $storagePath);

    $path = $storagePath.'/test-policy.json';
    file_put_contents($path, json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create'],
        'roles' => [],
    ]));

    $manager = app(PolicyEngineManager::class);
    $result = $manager->import($path);

    expect($result->permissionsCreated)->toBe(['posts.create']);

    unlink($path);
});

// --- Finding 6: deny_unbounded_scopes ---

it('denies scoped permission when no boundary exists and deny_unbounded_scopes is enabled', function (): void {
    config()->set('policy-engine.deny_unbounded_scopes', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'company::99');

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'posts.create:company::99'))->toBeFalse();
});

it('allows scoped permission when no boundary exists and deny_unbounded_scopes is disabled', function (): void {
    config()->set('policy-engine.deny_unbounded_scopes', false);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'company::99');

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'posts.create:company::99'))->toBeTrue();
});

// --- Finding 8: Wildcard deny across roles in scoped context ---

it('denies wildcard-denied permission even when another role grants it in scoped context', function (): void {
    $this->permissionStore->register(['billing.view', 'billing.refund']);
    $this->roleStore->save('billing-admin', 'Billing Admin', ['billing.*']);
    $this->roleStore->save('refund-restricted', 'Refund Restricted', ['!billing.refund']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'billing-admin', 'org::1');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'refund-restricted', 'org::1');

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'billing.refund:org::1'))->toBeFalse()
        ->and($evaluator->can('App\\Models\\User', 1, 'billing.view:org::1'))->toBeTrue();
});

// --- Finding 9: Full wildcard grant with wildcard deny ---

it('denies posts.* when full wildcard grant exists but posts deny rule present', function (): void {
    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('superadmin', 'Super Admin', ['*.*']);
    $this->roleStore->save('no-posts', 'No Posts', ['!posts.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'superadmin');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'no-posts');

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeFalse()
        ->and($evaluator->can('App\\Models\\User', 1, 'billing.manage'))->toBeTrue();
});

// --- Finding 10: Boundary enforcement on global assignment checking scoped permission ---

it('enforces boundary on scoped check even when user has global wildcard assignment', function (): void {
    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['*.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    $evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );

    expect($evaluator->can('App\\Models\\User', 1, 'billing.manage:org::acme'))->toBeFalse()
        ->and($evaluator->can('App\\Models\\User', 1, 'posts.create:org::acme'))->toBeTrue();
});
