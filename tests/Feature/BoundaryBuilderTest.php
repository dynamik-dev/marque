<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Support\BoundaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->boundaryStore = app(BoundaryStore::class);
});

it('returns a BoundaryBuilder from Marque::boundary()', function (): void {
    expect(Marque::boundary('org::acme'))
        ->toBeInstanceOf(BoundaryBuilder::class);
});

it('sets the max permissions via permits()', function (): void {
    Marque::boundary('org::acme')->permits(['posts.*', 'comments.*']);

    $boundary = $this->boundaryStore->find('org::acme');

    expect($boundary)->not->toBeNull()
        ->and($boundary->max_permissions)->toBe(['posts.*', 'comments.*']);
});

it('removes the boundary via remove()', function (): void {
    $this->boundaryStore->set('org::acme', ['posts.*']);

    expect($this->boundaryStore->find('org::acme'))->not->toBeNull();

    Marque::boundary('org::acme')->remove();

    expect($this->boundaryStore->find('org::acme'))->toBeNull();
});

it('preserves the existing two-argument boundary() form for backwards compatibility', function (): void {
    Marque::boundary('team::5', ['posts.create', 'posts.read']);

    $boundary = $this->boundaryStore->find('team::5');

    expect($boundary)->not->toBeNull()
        ->and($boundary->max_permissions)->toBe(['posts.create', 'posts.read']);
});

it('replaces max permissions when permits() is called twice', function (): void {
    Marque::boundary('org::acme')
        ->permits(['posts.*'])
        ->permits(['comments.*']);

    $boundary = $this->boundaryStore->find('org::acme');

    expect($boundary->max_permissions)->toBe(['comments.*']);
});

it('returns self from permits() for chaining', function (): void {
    $builder = Marque::boundary('org::acme');

    expect($builder->permits(['posts.*']))->toBe($builder);
});
