<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Resolvers\IdentityPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->assignments = app(AssignmentStore::class);
    $this->roles = app(RoleStore::class);
    $this->resolver = new IdentityPolicyResolver($this->assignments, $this->roles);
});

it('returns an empty collection when principal has no assignments', function (): void {
    $request = new EvaluationRequest(
        principal: new Principal('App\\Models\\User', 1),
        action: 'posts.create',
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toBeEmpty();
});

it('produces Allow statements from role permissions', function (): void {
    $this->roles->save('editor', 'Editor', ['posts.create', 'posts.read']);
    $this->assignments->assign('App\\Models\\User', 1, 'editor');

    $request = new EvaluationRequest(
        principal: new Principal('App\\Models\\User', 1),
        action: 'posts.create',
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toHaveCount(2);

    $actions = $statements->pluck('action')->all();
    expect($actions)->toContain('posts.create')
        ->and($actions)->toContain('posts.read');

    $statements->each(function (PolicyStatement $stmt): void {
        expect($stmt->effect)->toBe(Effect::Allow)
            ->and($stmt->source)->toBe('role:editor');
    });
});

it('produces Deny statements from !-prefixed permissions', function (): void {
    $this->roles->save('restricted', 'Restricted', ['posts.read', '!posts.delete']);
    $this->assignments->assign('App\\Models\\User', 1, 'restricted');

    $request = new EvaluationRequest(
        principal: new Principal('App\\Models\\User', 1),
        action: 'posts.delete',
    );

    $statements = $this->resolver->resolve($request);

    $denyStatements = $statements->filter(fn (PolicyStatement $s) => $s->effect === Effect::Deny);
    expect($denyStatements)->toHaveCount(1);

    $deny = $denyStatements->first();
    expect($deny->action)->toBe('posts.delete')
        ->and($deny->source)->toBe('role:restricted');

    $allowStatements = $statements->filter(fn (PolicyStatement $s) => $s->effect === Effect::Allow);
    expect($allowStatements)->toHaveCount(1)
        ->and($allowStatements->first()->action)->toBe('posts.read');
});

it('includes scoped assignments when context has a scope', function (): void {
    $this->roles->save('viewer', 'Viewer', ['posts.read']);
    $this->assignments->assign('App\\Models\\User', 2, 'viewer', 'team::7');

    $request = new EvaluationRequest(
        principal: new Principal('App\\Models\\User', 2),
        action: 'posts.read',
        context: new Context(scope: 'team::7'),
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toHaveCount(1)
        ->and($statements->first()->action)->toBe('posts.read')
        ->and($statements->first()->effect)->toBe(Effect::Allow)
        ->and($statements->first()->source)->toBe('role:viewer');
});

it('includes both global and scoped assignments when context has a scope', function (): void {
    $this->roles->save('editor', 'Editor', ['posts.create']);
    $this->roles->save('viewer', 'Viewer', ['posts.read']);

    $this->assignments->assign('App\\Models\\User', 3, 'editor');
    $this->assignments->assign('App\\Models\\User', 3, 'viewer', 'team::9');

    $request = new EvaluationRequest(
        principal: new Principal('App\\Models\\User', 3),
        action: 'posts.create',
        context: new Context(scope: 'team::9'),
    );

    $statements = $this->resolver->resolve($request);

    expect($statements)->toHaveCount(2);

    $sources = $statements->pluck('source')->all();
    expect($sources)->toContain('role:editor')
        ->and($sources)->toContain('role:viewer');

    $actions = $statements->pluck('action')->all();
    expect($actions)->toContain('posts.create')
        ->and($actions)->toContain('posts.read');
});
