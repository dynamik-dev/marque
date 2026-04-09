<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Resolvers\BoundaryPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->permissionStore = app(PermissionStore::class);
    $this->boundaryStore = app(BoundaryStore::class);
    $this->matcher = app(Matcher::class);
});

function makeBoundaryResolver(
    BoundaryStore $boundaries,
    Matcher $matcher,
    PermissionStore $permissionStore,
    bool $denyUnboundedScopes = false,
    bool $enforceOnGlobal = false,
): BoundaryPolicyResolver {
    return new BoundaryPolicyResolver(
        boundaries: $boundaries,
        matcher: $matcher,
        permissionStore: $permissionStore,
        denyUnboundedScopes: $denyUnboundedScopes,
        enforceOnGlobal: $enforceOnGlobal,
    );
}

function makeBoundaryRequest(string $action = 'posts.create', ?string $scope = null): EvaluationRequest
{
    return new EvaluationRequest(
        principal: new Principal(type: 'App\\Models\\User', id: 1),
        action: $action,
        context: new Context(scope: $scope),
    );
}

it('returns empty when context has no scope and enforce_on_global is false', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);
    $this->boundaryStore->set('org::acme', ['posts.*']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: false,
        enforceOnGlobal: false,
    );

    $statements = $resolver->resolve(makeBoundaryRequest(scope: null));

    expect($statements)->toBeEmpty();
});

it('returns empty when scope has no boundary and deny_unbounded is false', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: false,
        enforceOnGlobal: false,
    );

    $statements = $resolver->resolve(makeBoundaryRequest(scope: 'org::unknown'));

    expect($statements)->toBeEmpty();
});

it('produces Deny statements for permissions outside the boundary ceiling', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.update', 'posts.delete']);
    $this->boundaryStore->set('org::acme', ['posts.read']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: false,
        enforceOnGlobal: false,
    );

    $statements = $resolver->resolve(makeBoundaryRequest(scope: 'org::acme'));

    $deniedActions = $statements->pluck('action')->sort()->values()->all();

    expect($statements)->not->toBeEmpty()
        ->and($deniedActions)->toContain('posts.create')
        ->and($deniedActions)->toContain('posts.update')
        ->and($deniedActions)->toContain('posts.delete')
        ->and($deniedActions)->not->toContain('posts.read');

    $statements->each(function (PolicyStatement $stmt): void {
        expect($stmt->effect)->toBe(Effect::Deny);
    });
});

it('produces Deny-all when deny_unbounded_scopes is true and scope has no boundary', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: true,
        enforceOnGlobal: false,
    );

    $statements = $resolver->resolve(makeBoundaryRequest(scope: 'org::unknown'));

    expect($statements)->toHaveCount(3);

    $statements->each(function (PolicyStatement $stmt): void {
        expect($stmt->effect)->toBe(Effect::Deny);
    });

    $deniedActions = $statements->pluck('action')->sort()->values()->all();
    expect($deniedActions)->toContain('posts.create')
        ->and($deniedActions)->toContain('posts.read')
        ->and($deniedActions)->toContain('posts.delete');
});

it('all deny statements from a scoped boundary have source starting with boundary:', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete']);
    $this->boundaryStore->set('org::acme', ['posts.read']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: false,
        enforceOnGlobal: false,
    );

    $statements = $resolver->resolve(makeBoundaryRequest(scope: 'org::acme'));

    expect($statements)->not->toBeEmpty();

    $statements->each(function (PolicyStatement $stmt): void {
        expect($stmt->source)->toStartWith('boundary:');
    });
});

it('all deny statements from an unbounded deny have source starting with boundary:', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: true,
        enforceOnGlobal: false,
    );

    $statements = $resolver->resolve(makeBoundaryRequest(scope: 'org::unknown'));

    expect($statements)->not->toBeEmpty();

    $statements->each(function (PolicyStatement $stmt): void {
        expect($stmt->source)->toStartWith('boundary:');
    });
});

it('ceiling with wildcard pattern allows all matching permissions through', function (): void {
    $this->permissionStore->register(['posts.create', 'posts.read', 'posts.delete', 'billing.manage']);
    $this->boundaryStore->set('org::acme', ['posts.*']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: false,
        enforceOnGlobal: false,
    );

    $statements = $resolver->resolve(makeBoundaryRequest(scope: 'org::acme'));

    $deniedActions = $statements->pluck('action')->all();

    expect($deniedActions)->toContain('billing.manage')
        ->and($deniedActions)->not->toContain('posts.create')
        ->and($deniedActions)->not->toContain('posts.read')
        ->and($deniedActions)->not->toContain('posts.delete');
});

it('produces no deny statements when scope has no boundary and deny_unbounded is false with a scope', function (): void {
    $this->permissionStore->register(['posts.create']);

    $resolver = makeBoundaryResolver(
        boundaries: $this->boundaryStore,
        matcher: $this->matcher,
        permissionStore: $this->permissionStore,
        denyUnboundedScopes: false,
        enforceOnGlobal: false,
    );

    // Scope exists in request, but no boundary is defined for it
    $statements = $resolver->resolve(makeBoundaryRequest(scope: 'org::nope'));

    expect($statements)->toBeEmpty();
});
