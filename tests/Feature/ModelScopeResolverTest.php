<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Resolvers\ModelScopeResolver;
use Illuminate\Database\Eloquent\Model;

beforeEach(function (): void {
    $this->resolver = new ModelScopeResolver;
});

// --- Null input ---

it('returns null when given null', function (): void {
    expect($this->resolver->resolve(null))->toBeNull();
});

// --- String input ---

it('returns the same string when given a string scope', function (): void {
    expect($this->resolver->resolve('group::5'))->toBe('group::5');
});

it('returns an empty string when given an empty string', function (): void {
    expect($this->resolver->resolve(''))->toBe('');
});

// --- Scopeable model ---

it('calls toScope() on an Eloquent Model that has the method', function (): void {
    $model = new class extends Model
    {
        protected $table = 'test_models';

        public function toScope(): string
        {
            return 'team::42';
        }
    };

    expect($this->resolver->resolve($model))->toBe('team::42');
});

// --- Invalid inputs ---

it('throws InvalidArgumentException for an integer', function (): void {
    $this->resolver->resolve(123);
})->throws(InvalidArgumentException::class);

it('throws InvalidArgumentException for an array', function (): void {
    $this->resolver->resolve(['group::5']);
})->throws(InvalidArgumentException::class);

it('throws InvalidArgumentException for a plain object without toScope()', function (): void {
    $this->resolver->resolve(new stdClass);
})->throws(InvalidArgumentException::class);

it('throws InvalidArgumentException for an Eloquent Model without toScope()', function (): void {
    $model = new class extends Model
    {
        protected $table = 'test_models';
    };

    $this->resolver->resolve($model);
})->throws(InvalidArgumentException::class);
