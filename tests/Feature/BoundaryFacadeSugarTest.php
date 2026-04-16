<?php

declare(strict_types=1);

use DynamikDev\Marque\Concerns\Scopeable;
use DynamikDev\Marque\Contracts\BoundaryStore;
use DynamikDev\Marque\Exceptions\ScopeUnresolvableException;
use DynamikDev\Marque\Facades\Marque;
use DynamikDev\Marque\Support\BoundaryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class BoundaryTestTeam extends Model
{
    use Scopeable;

    protected $table = 'boundary_test_teams';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('boundary_test_teams', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->boundaryStore = app(BoundaryStore::class);
});

afterEach(function (): void {
    Schema::dropIfExists('boundary_test_teams');
});

// --- createBoundary ---

it('returns a BoundaryBuilder from createBoundary', function (): void {
    expect(Marque::createBoundary('team::1'))->toBeInstanceOf(BoundaryBuilder::class);
});

it('accepts a Scopeable model in createBoundary', function (): void {
    $team = BoundaryTestTeam::create(['name' => 'Alpha']);

    expect(Marque::createBoundary($team))->toBeInstanceOf(BoundaryBuilder::class);
});

it('accepts a raw string in createBoundary', function (): void {
    expect(Marque::createBoundary('team::1'))->toBeInstanceOf(BoundaryBuilder::class);
});

it('sets max permissions via createBoundary permits', function (): void {
    $team = BoundaryTestTeam::create(['name' => 'Alpha']);

    Marque::createBoundary($team)->permits(['posts.create', 'posts.read']);

    $boundary = $this->boundaryStore->find('boundarytestteam::'.$team->getKey());

    expect($boundary)->not->toBeNull()
        ->and($boundary->max_permissions)->toBe(['posts.create', 'posts.read']);
});

// --- getBoundary ---

it('returns a Boundary model when found', function (): void {
    $team = BoundaryTestTeam::create(['name' => 'Alpha']);
    $scope = 'boundarytestteam::'.$team->getKey();

    $this->boundaryStore->set($scope, ['posts.create']);

    $boundary = Marque::getBoundary($team);

    expect($boundary)->not->toBeNull()
        ->and($boundary->scope)->toBe($scope)
        ->and($boundary->max_permissions)->toBe(['posts.create']);
});

it('returns null when boundary not found', function (): void {
    $team = BoundaryTestTeam::create(['name' => 'Alpha']);

    expect(Marque::getBoundary($team))->toBeNull();
});

it('accepts a raw string in getBoundary', function (): void {
    $this->boundaryStore->set('team::1', ['posts.create']);

    $boundary = Marque::getBoundary('team::1');

    expect($boundary)->not->toBeNull()
        ->and($boundary->scope)->toBe('team::1');
});

// --- boundary builder handle ---

it('sets permissions via boundary builder handle', function (): void {
    $team = BoundaryTestTeam::create(['name' => 'Alpha']);
    $scope = 'boundarytestteam::'.$team->getKey();

    Marque::boundary($team)->permits(['posts.create', 'posts.read']);

    $boundary = $this->boundaryStore->find($scope);

    expect($boundary)->not->toBeNull()
        ->and($boundary->max_permissions)->toBe(['posts.create', 'posts.read']);
});

it('removes a boundary via the builder handle', function (): void {
    $team = BoundaryTestTeam::create(['name' => 'Alpha']);
    $scope = 'boundarytestteam::'.$team->getKey();

    $this->boundaryStore->set($scope, ['posts.create']);
    expect($this->boundaryStore->find($scope))->not->toBeNull();

    Marque::boundary($team)->remove();

    expect($this->boundaryStore->find($scope))->toBeNull();
});

// --- permits behavior ---

it('replaces permissions on second permits call', function (): void {
    $team = BoundaryTestTeam::create(['name' => 'Alpha']);
    $scope = 'boundarytestteam::'.$team->getKey();

    Marque::createBoundary($team)
        ->permits(['posts.create', 'posts.read'])
        ->permits(['comments.create']);

    $boundary = $this->boundaryStore->find($scope);

    expect($boundary->max_permissions)->toBe(['comments.create']);
});

it('returns self from permits for chaining', function (): void {
    $builder = Marque::createBoundary('team::1');

    expect($builder->permits(['posts.create']))->toBe($builder);
});

it('throws ScopeUnresolvableException when createBoundary is given a null scope', function (): void {
    Marque::createBoundary(null);
})->throws(ScopeUnresolvableException::class, 'Boundary requires a non-null scope.');

it('throws ScopeUnresolvableException when boundary() is given a null scope', function (): void {
    Marque::boundary(null);
})->throws(ScopeUnresolvableException::class, 'Boundary requires a non-null scope.');
