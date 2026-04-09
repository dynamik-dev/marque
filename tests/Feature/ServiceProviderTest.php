<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Contracts\DocumentExporter;
use DynamikDev\Marque\Contracts\DocumentImporter;
use DynamikDev\Marque\Contracts\DocumentParser;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Contracts\ScopeResolver;
use DynamikDev\Marque\Documents\DefaultDocumentExporter;
use DynamikDev\Marque\Documents\DefaultDocumentImporter;
use DynamikDev\Marque\Documents\JsonDocumentParser;
use DynamikDev\Marque\Evaluators\CachedEvaluator;
use DynamikDev\Marque\MarqueManager;
use DynamikDev\Marque\MarqueServiceProvider;
use DynamikDev\Marque\Matchers\WildcardMatcher;
use DynamikDev\Marque\Resolvers\ModelScopeResolver;
use DynamikDev\Marque\Stores\EloquentAssignmentStore;
use DynamikDev\Marque\Stores\EloquentBoundaryStore;
use DynamikDev\Marque\Stores\EloquentPermissionStore;
use DynamikDev\Marque\Stores\EloquentRoleStore;

it('boots without errors', function (): void {
    expect(app()->getProviders(MarqueServiceProvider::class))
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

it('resolves MarqueManager as singleton', function (): void {
    $first = app(MarqueManager::class);
    $second = app(MarqueManager::class);

    expect($first)->toBe($second);
});

it('merges config from package config file', function (): void {
    expect(config('marque'))->toBeArray();
    expect(config('marque.cache.enabled'))->toBeBool();
    expect(config('marque.protect_system_roles'))->toBeBool();
});
