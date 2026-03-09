<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Contracts\BoundaryStore;
use DynamikDev\PolicyEngine\Contracts\DocumentExporter;
use DynamikDev\PolicyEngine\Contracts\DocumentImporter;
use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PermissionStore;
use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Contracts\ScopeResolver;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentExporter;
use DynamikDev\PolicyEngine\Documents\DefaultDocumentImporter;
use DynamikDev\PolicyEngine\Documents\JsonDocumentParser;
use DynamikDev\PolicyEngine\Evaluators\CachedEvaluator;
use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;
use DynamikDev\PolicyEngine\PrimitivesManager;
use DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use DynamikDev\PolicyEngine\Stores\EloquentPermissionStore;
use DynamikDev\PolicyEngine\Stores\EloquentRoleStore;

it('boots without errors', function (): void {
    expect(app()->getProviders(\DynamikDev\PolicyEngine\PolicyEngineServiceProvider::class))
        ->not->toBeEmpty();
});

it('resolves PermissionStore to EloquentPermissionStore', function (): void {
    expect(app(PermissionStore::class))->toBeInstanceOf(EloquentPermissionStore::class);
});

it('resolves RoleStore to EloquentRoleStore', function (): void {
    expect(app(RoleStore::class))->toBeInstanceOf(EloquentRoleStore::class);
});

it('resolves AssignmentStore to EloquentAssignmentStore', function (): void {
    expect(app(AssignmentStore::class))->toBeInstanceOf(EloquentAssignmentStore::class);
});

it('resolves BoundaryStore to EloquentBoundaryStore', function (): void {
    expect(app(BoundaryStore::class))->toBeInstanceOf(EloquentBoundaryStore::class);
});

it('resolves Matcher to WildcardMatcher', function (): void {
    expect(app(Matcher::class))->toBeInstanceOf(WildcardMatcher::class);
});

it('resolves ScopeResolver to ModelScopeResolver', function (): void {
    expect(app(ScopeResolver::class))->toBeInstanceOf(ModelScopeResolver::class);
});

it('resolves DocumentParser to JsonDocumentParser', function (): void {
    expect(app(DocumentParser::class))->toBeInstanceOf(JsonDocumentParser::class);
});

it('resolves DocumentImporter to DefaultDocumentImporter', function (): void {
    expect(app(DocumentImporter::class))->toBeInstanceOf(DefaultDocumentImporter::class);
});

it('resolves DocumentExporter to DefaultDocumentExporter', function (): void {
    expect(app(DocumentExporter::class))->toBeInstanceOf(DefaultDocumentExporter::class);
});

it('resolves Evaluator to CachedEvaluator', function (): void {
    expect(app(Evaluator::class))->toBeInstanceOf(CachedEvaluator::class);
});

it('resolves PrimitivesManager as singleton', function (): void {
    $first = app(PrimitivesManager::class);
    $second = app(PrimitivesManager::class);

    expect($first)->toBe($second);
});

it('merges config from package config file', function (): void {
    expect(config('policy-engine'))->toBeArray();
    expect(config('policy-engine.cache.enabled'))->toBeBool();
    expect(config('policy-engine.protect_system_roles'))->toBeBool();
});
