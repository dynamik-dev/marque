<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Matchers\WildcardMatcher;

mutates(WildcardMatcher::class);

beforeEach(function (): void {
    $this->matcher = new WildcardMatcher;
});

// --- Exact match ---

it('matches identical permission strings exactly', function (): void {
    expect($this->matcher->matches('posts.create', 'posts.create'))->toBeTrue();
});

// --- Wildcard verb match ---

it('matches wildcard verb against any verb on the same resource', function (string $required): void {
    expect($this->matcher->matches('posts.*', $required))->toBeTrue();
})->with([
    'posts.create',
    'posts.delete',
    'posts.update.own',
]);

// --- Wildcard resource match ---

it('matches wildcard resource against any resource with the same verb', function (string $required): void {
    expect($this->matcher->matches('*.create', $required))->toBeTrue();
})->with([
    'posts.create',
    'comments.create',
]);

// --- Full wildcard ---

it('matches full wildcard (*.*) against any permission string', function (string $required): void {
    expect($this->matcher->matches('*.*', $required))->toBeTrue();
})->with([
    'posts.create',
    'comments.delete',
    'users.update.own',
]);

// --- Single star ---

it('matches single star (*) against any permission string', function (string $required): void {
    expect($this->matcher->matches('*', $required))->toBeTrue();
})->with([
    'posts.create',
    'comments.delete',
    'users.update.own',
]);

// --- No match: different verb ---

it('does not match when the verb differs', function (): void {
    expect($this->matcher->matches('posts.create', 'posts.delete'))->toBeFalse();
});

// --- No match: different resource ---

it('does not match when the resource differs', function (): void {
    expect($this->matcher->matches('posts.create', 'comments.create'))->toBeFalse();
});

// --- Scope: exact match ---

it('matches scoped permissions with identical scopes', function (): void {
    expect($this->matcher->matches('posts.create:group::5', 'posts.create:group::5'))->toBeTrue();
});

// --- Scope: unscoped grant covers scoped check ---

it('matches unscoped grant against a scoped required permission', function (): void {
    expect($this->matcher->matches('posts.create', 'posts.create:group::5'))->toBeTrue();
});

// --- Scope: scoped grant does not cover different scope ---

it('does not match when the granted scope differs from the required scope', function (): void {
    expect($this->matcher->matches('posts.create:group::5', 'posts.create:group::9'))->toBeFalse();
});

// --- Scope: scoped grant does not cover unscoped required ---

it('does not match a scoped grant against an unscoped required permission', function (): void {
    expect($this->matcher->matches('posts.create:group::5', 'posts.create'))->toBeFalse();
});

// --- Deep verb matching ---

it('matches deep verb wildcard against qualified verbs', function (string $required): void {
    expect($this->matcher->matches('posts.delete.*', $required))->toBeTrue();
})->with([
    'posts.delete.own',
    'posts.delete.any',
]);

// --- Deep verb: wildcard does not match the base verb alone ---

it('does not match deep verb wildcard against the base verb without qualifier', function (): void {
    expect($this->matcher->matches('posts.delete.*', 'posts.delete'))->toBeFalse();
});

// --- Wildcards combined with scopes ---

it('matches wildcard verb with scope against scoped permission', function (): void {
    expect($this->matcher->matches('posts.*:group::5', 'posts.create:group::5'))->toBeTrue();
});

it('matches wildcard verb without scope against scoped permission', function (): void {
    expect($this->matcher->matches('posts.*', 'posts.create:group::5'))->toBeTrue();
});

it('does not match wildcard verb with wrong scope', function (): void {
    expect($this->matcher->matches('posts.*:group::5', 'posts.create:group::9'))->toBeFalse();
});

// --- Edge cases ---

it('does not match when granted has more segments than required', function (): void {
    expect($this->matcher->matches('posts.create.own', 'posts.create'))->toBeFalse();
});

it('does not match when required has more segments than granted without wildcard', function (): void {
    expect($this->matcher->matches('posts.create', 'posts.create.own'))->toBeFalse();
});

it('matches single-segment wildcard against single-segment permission', function (): void {
    expect($this->matcher->matches('*', 'posts'))->toBeTrue();
});
