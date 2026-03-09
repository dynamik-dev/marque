<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Models\Boundary;
use DynamikDev\PolicyEngine\Stores\EloquentBoundaryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

mutates(EloquentBoundaryStore::class);

beforeEach(function (): void {
    $this->store = new EloquentBoundaryStore;
});

// --- set ---

it('creates a new boundary for a scope', function (): void {
    $this->store->set('org::acme', ['posts.*', 'comments.*']);

    $boundary = Boundary::query()->where('scope', 'org::acme')->first();

    expect($boundary)
        ->not->toBeNull()
        ->scope->toBe('org::acme')
        ->max_permissions->toBe(['posts.*', 'comments.*']);
});

it('updates an existing boundary for the same scope', function (): void {
    $this->store->set('org::acme', ['posts.*']);

    $this->store->set('org::acme', ['posts.*', 'comments.*', 'files.read']);

    expect(Boundary::query()->where('scope', 'org::acme')->count())->toBe(1);

    $boundary = Boundary::query()->where('scope', 'org::acme')->first();

    expect($boundary->max_permissions)->toBe(['posts.*', 'comments.*', 'files.read']);
});

// --- remove ---

it('deletes an existing boundary', function (): void {
    $this->store->set('org::acme', ['posts.*']);

    $this->store->remove('org::acme');

    expect(Boundary::query()->where('scope', 'org::acme')->exists())->toBeFalse();
});

it('does not error when removing a non-existing boundary', function (): void {
    $this->store->remove('org::nonexistent');

    expect(Boundary::query()->count())->toBe(0);
});

// --- find ---

it('returns the boundary model when it exists', function (): void {
    $this->store->set('org::acme', ['posts.*', 'comments.*']);

    $boundary = $this->store->find('org::acme');

    expect($boundary)
        ->not->toBeNull()
        ->toBeInstanceOf(Boundary::class)
        ->scope->toBe('org::acme')
        ->max_permissions->toBe(['posts.*', 'comments.*']);
});

it('returns null when no boundary exists for the scope', function (): void {
    expect($this->store->find('org::nonexistent'))->toBeNull();
});
