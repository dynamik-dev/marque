<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use DynamikDev\PolicyEngine\Evaluators\DefaultEvaluator;
use DynamikDev\PolicyEngine\Events\AuthorizationDenied;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = new EloquentPermissionStore;
    $this->roleStore = new EloquentRoleStore;
    $this->assignmentStore = new EloquentAssignmentStore;
    $this->boundaryStore = new EloquentBoundaryStore;

    $this->evaluator = new DefaultEvaluator(
        assignments: $this->assignmentStore,
        roles: $this->roleStore,
        boundaries: $this->boundaryStore,
        matcher: new WildcardMatcher,
    );
});

// --- can: basic allow ---

it('allows a permission when the subject has a matching role', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue();
});

// --- can: basic deny ---

it('denies a permission the subject does not have', function (): void {
    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'viewer');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeFalse();
});

// --- can: deny wins over allow ---

it('denies when an explicit deny rule overrides an allow', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.*']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'restricted');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.delete'))->toBeFalse();
});

// --- can: wildcard grants ---

it('allows via wildcard permission match', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.update']);
    $this->roleStore->save('editor', 'Editor', ['posts.*']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.read'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'posts.update'))->toBeTrue()
        ->and($this->evaluator->can('App\\Models\\User', 1, 'comments.create'))->toBeFalse();
});

// --- can: scoped evaluation ---

it('evaluates permissions within a scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'team-editor', 'team::5');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:team::5'))->toBeTrue();
});

it('denies scoped permission when subject only has a different scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'team-editor', 'team::5');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:team::99'))->toBeFalse();
});

it('allows scoped permission via global (unscoped) assignment', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:team::5'))->toBeTrue();
});

// --- can: boundary enforcement ---

it('allows permission when within boundary', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:org::acme'))->toBeTrue();
});

it('denies permission when outside boundary', function (): void {
    $this->permissionStore->register(['posts.create', 'billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['posts.create', 'billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    expect($this->evaluator->can('App\\Models\\User', 1, 'billing.manage:org::acme'))->toBeFalse();
});

it('allows permission when no boundary exists for scope', function (): void {
    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor', 'org::acme');

    expect($this->evaluator->can('App\\Models\\User', 1, 'posts.create:org::acme'))->toBeTrue();
});

// --- can: no assignment means deny ---

it('denies when the subject has no assignments', function (): void {
    expect($this->evaluator->can('App\\Models\\User', 99, 'posts.create'))->toBeFalse();
});

// --- can: AuthorizationDenied event ---

it('dispatches AuthorizationDenied when denied and log_denials is true', function (): void {
    Event::fake([AuthorizationDenied::class]);
    config()->set('policy-engine.log_denials', true);

    $this->evaluator->can('App\\Models\\User', 1, 'posts.create');

    Event::assertDispatched(AuthorizationDenied::class, function (AuthorizationDenied $event): bool {
        return $event->subject === 'App\\Models\\User:1'
            && $event->permission === 'posts.create'
            && $event->scope === null;
    });
});

it('does not dispatch AuthorizationDenied when log_denials is false', function (): void {
    Event::fake([AuthorizationDenied::class]);
    config()->set('policy-engine.log_denials', false);

    $this->evaluator->can('App\\Models\\User', 1, 'posts.create');

    Event::assertNotDispatched(AuthorizationDenied::class);
});

it('does not dispatch AuthorizationDenied when permission is allowed', function (): void {
    Event::fake([AuthorizationDenied::class]);
    config()->set('policy-engine.log_denials', true);

    $this->permissionStore->register(['posts.create']);
    $this->roleStore->save('editor', 'Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $this->evaluator->can('App\\Models\\User', 1, 'posts.create');

    Event::assertNotDispatched(AuthorizationDenied::class);
});

// --- explain ---

it('throws RuntimeException when explain mode is disabled', function (): void {
    config()->set('policy-engine.explain', false);

    $this->evaluator->explain('App\\Models\\User', 1, 'posts.create');
})->throws(\RuntimeException::class, 'Explain mode is disabled');

it('returns an EvaluationTrace when explain mode is enabled', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.create');

    expect($trace)
        ->toBeInstanceOf(EvaluationTrace::class)
        ->subject->toBe('App\\Models\\User:1')
        ->required->toBe('posts.create')
        ->result->toBe('allow')
        ->cacheHit->toBeFalse()
        ->assignments->toHaveCount(1)
        ->assignments->each->toHaveKeys(['role', 'scope', 'permissions_checked']);
});

it('returns deny trace when permission is not granted', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['posts.read']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'viewer');

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'posts.delete');

    expect($trace)
        ->result->toBe('deny')
        ->boundary->toBeNull();
});

it('includes boundary note in explain trace when boundary blocks', function (): void {
    config()->set('policy-engine.explain', true);

    $this->permissionStore->register(['billing.manage']);
    $this->roleStore->save('admin', 'Admin', ['billing.manage']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'admin', 'org::acme');
    $this->boundaryStore->set('org::acme', ['posts.*']);

    $trace = $this->evaluator->explain('App\\Models\\User', 1, 'billing.manage:org::acme');

    expect($trace)
        ->result->toBe('deny')
        ->boundary->toContain('Denied by boundary');
});

// --- effectivePermissions ---

it('returns all effective permissions for a subject', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toContain('posts.create', 'posts.read', 'posts.delete')
        ->toHaveCount(3);
});

it('excludes denied permissions from effective set', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read', 'posts.delete']);
    $this->roleStore->save('restricted', 'Restricted', ['!posts.delete']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'restricted');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.delete');
});

it('returns empty array when subject has no assignments', function (): void {
    expect($this->evaluator->effectivePermissions('App\\Models\\User', 99))->toBe([]);
});

it('returns scoped effective permissions including global assignments', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'billing.manage']);
    $this->roleStore->save('viewer', 'Viewer', ['posts.read']);
    $this->roleStore->save('team-editor', 'Team Editor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'viewer');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'team-editor', 'team::5');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1, 'team::5');

    expect($permissions)->toContain('posts.read', 'posts.create')
        ->not->toContain('billing.manage');
});

it('deduplicates permissions from multiple roles', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->roleStore->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->roleStore->save('contributor', 'Contributor', ['posts.create']);
    $this->assignmentStore->assign('App\\Models\\User', 1, 'editor');
    $this->assignmentStore->assign('App\\Models\\User', 1, 'contributor');

    $permissions = $this->evaluator->effectivePermissions('App\\Models\\User', 1);

    expect($permissions)->toHaveCount(2)
        ->toContain('posts.create', 'posts.read');
});
