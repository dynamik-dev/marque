<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\DocumentImporter;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\ImportOptions;
use DynamikDev\Marque\DTOs\PolicyDocument;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\MarqueManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);
    $this->assignmentStore = app(AssignmentStore::class);
    $this->boundaryStore = app(BoundaryStore::class);
});

// --- Finding 1: System role protection in save() ---

it('rejects permission changes on a protected system role', function (): void {
    config()->set('marque.protect_system_roles', true);

    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'posts.delete'], system: true);

    $this->roleStore->save('admin', 'Admin', ['posts.create'], system: true);
})->throws(RuntimeException::class, 'Cannot modify permissions on protected system role');

it('rejects flipping is_system flag off on a protected role', function (): void {
    config()->set('marque.protect_system_roles', true);

    $this->roleStore->save('admin', 'Admin', [], system: true);

    $this->roleStore->save('admin', 'Admin', [], system: false);
})->throws(RuntimeException::class, 'Cannot remove system flag from protected role');

it('allows modifying a system role when protection is disabled', function (): void {
    config()->set('marque.protect_system_roles', false);

    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'posts.delete'], system: true);

    $role = $this->roleStore->save('admin', 'Admin', ['posts.create'], system: true);

    expect($role->id)->toBe('admin');
    expect($this->roleStore->permissionsFor('admin'))->toBe(['posts.create']);
});

it('allows updating the name of a protected system role', function (): void {
    config()->set('marque.protect_system_roles', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('admin', 'Admin', ['posts.create'], system: true);

    $role = $this->roleStore->save('admin', 'Super Admin', ['posts.create'], system: true);

    expect($role->name)->toBe('Super Admin');
});

// --- Finding 3: Document import skips protected system roles ---

it('skips protected system roles during import', function (): void {
    config()->set('marque.protect_system_roles', true);

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

    $importer = app(DocumentImporter::class);

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

// --- Finding 5: Path validation in MarqueManager ---

it('rejects import from a path outside the allowed directory', function (): void {
    config()->set('marque.document_path', storage_path());

    $manager = app(MarqueManager::class);

    $manager->import('/etc/passwd');
})->throws(InvalidArgumentException::class, 'Path must be within the allowed directory');

it('rejects export to a path outside the allowed directory', function (): void {
    config()->set('marque.document_path', storage_path());

    $manager = app(MarqueManager::class);

    $manager->exportToFile('/tmp/evil.json');
})->throws(InvalidArgumentException::class, 'Path must be within the allowed directory');

it('rejects export to a sibling directory that shares the allowed path prefix', function (): void {
    $storagePath = storage_path();

    if (! is_dir($storagePath)) {
        mkdir($storagePath, 0755, true);
    }

    config()->set('marque.document_path', $storagePath);

    $sibling = $storagePath.'-sibling-'.uniqid('', true);

    mkdir($sibling, 0755, true);

    $manager = app(MarqueManager::class);

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

    config()->set('marque.document_path', $storagePath);

    $path = $storagePath.'/test-policy.json';
    file_put_contents($path, json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create'],
        'roles' => [],
    ]));

    $manager = app(MarqueManager::class);
    $result = $manager->import($path);

    expect($result->permissionsCreated)->toBe(['posts.create']);

    unlink($path);
});

/**
 * Helper: evaluate a permission for a subject using the new v2 Evaluator contract.
 */
function securityEval(Evaluator $evaluator, string $type, string|int $id, string $action, ?string $scope = null): bool
{
    $result = $evaluator->evaluate(new EvaluationRequest(
        principal: new Principal(type: $type, id: $id),
        action: $action,
        resource: null,
        context: new Context(scope: $scope),
    ));

    return $result->decision === Decision::Allow;
}

// --- Finding 6: deny_unbounded_scopes ---

it('denies scoped permission when no boundary exists and deny_unbounded_scopes is enabled', function (): void {
    config()->set('marque.deny_unbounded_scopes', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'company::99');

    $evaluator = app(Evaluator::class);

    expect(securityEval($evaluator, 'App\\Models\\User', 1, 'posts.create', 'company::99'))->toBeFalse();
});

it('allows scoped permission when no boundary exists and deny_unbounded_scopes is disabled', function (): void {
    config()->set('marque.deny_unbounded_scopes', false);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'company::99');

    $evaluator = app(Evaluator::class);

    expect(securityEval($evaluator, 'App\\Models\\User', 1, 'posts.create', 'company::99'))->toBeTrue();
});

// --- Finding 8: Wildcard deny across roles in scoped context ---

it('denies wildcard-denied permission even when another role grants it in scoped context', function (): void {
    $this->permissionStore->register(['billing.view', 'billing.refund']);
    $this->roleStore->save('billing-admin', 'Billing Admin', ['billing.*']);
    $this->roleStore->save('refund-restricted', 'Refund Restricted', ['!billing.refund']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'billing-admin', 'org::1');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'refund-restricted', 'org::1');

    $evaluator = app(Evaluator::class);

    expect(securityEval($evaluator, 'App\\Models\\User', 1, 'billing.refund', 'org::1'))->toBeFalse()
        ->and(securityEval($evaluator, 'App\\Models\\User', 1, 'billing.view', 'org::1'))->toBeTrue();
});

// --- Finding 9: Full wildcard grant with wildcard deny ---

it('denies posts.* when full wildcard grant exists but posts deny rule present', function (): void {
    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('superadmin', 'Super Admin', ['*.*']);
    $this->roleStore->save('no-posts', 'No Posts', ['!posts.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'superadmin');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'no-posts');

    $evaluator = app(Evaluator::class);

    expect(securityEval($evaluator, 'App\\Models\\User', 1, 'posts.create'))->toBeFalse()
        ->and(securityEval($evaluator, 'App\\Models\\User', 1, 'billing.manage'))->toBeTrue();
});

// --- Finding 10: Boundary enforcement on global assignment checking scoped permission ---

it('enforces boundary on scoped check even when user has global wildcard assignment', function (): void {
    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('superadmin', 'Super Admin', ['*.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'superadmin');
    $this->boundaryStore->set('org::restricted', ['posts.*']);

    $evaluator = app(Evaluator::class);

    // billing.manage is outside the boundary ceiling for org::restricted.
    expect(securityEval($evaluator, 'App\\Models\\User', 1, 'billing.manage', 'org::restricted'))->toBeFalse();

    // posts.create is within the boundary ceiling.
    expect(securityEval($evaluator, 'App\\Models\\User', 1, 'posts.create', 'org::restricted'))->toBeTrue();
});

// --- Finding 11: PathValidator rejects paths with non-existent parent directories ---

it('rejects import from a path whose parent directory does not exist', function (): void {
    config()->set('marque.document_path', storage_path());

    $manager = app(MarqueManager::class);

    $manager->import(storage_path('nonexistent-dir/policy.json'));
})->throws(InvalidArgumentException::class, 'Path must be within the allowed directory');

// --- task-6.10: PathValidator fails closed when document_path is unset ---

it('rejects import when marque.document_path is not configured', function (): void {
    config()->set('marque.document_path', null);

    $manager = app(MarqueManager::class);

    $manager->import('/etc/passwd');
})->throws(RuntimeException::class, 'Marque document path is not configured');

it('rejects import when marque.document_path is an empty string', function (): void {
    config()->set('marque.document_path', '');

    $manager = app(MarqueManager::class);

    $manager->import('/etc/passwd');
})->throws(RuntimeException::class, 'Marque document path is not configured');

it('rejects export when marque.document_path is not configured', function (): void {
    config()->set('marque.document_path', null);

    $manager = app(MarqueManager::class);

    $manager->exportToFile('/tmp/evil.json');
})->throws(RuntimeException::class, 'Marque document path is not configured');
