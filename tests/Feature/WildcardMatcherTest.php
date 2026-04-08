<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\Matcher;

beforeEach(function (): void {
    $this->matcher = app(Matcher::class);
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

// --- Mid-pattern wildcard with repeated segments ---

it('matches mid-pattern wildcard when post-wildcard literal repeats', function (): void {
    expect($this->matcher->matches('a.*.b.c', 'a.b.b.c'))->toBeTrue();
});

it('matches mid-pattern wildcard consuming multiple segments', function (): void {
    expect($this->matcher->matches('a.*.c', 'a.b.d.c'))->toBeTrue();
});

it('matches mid-pattern wildcard consuming exactly one segment', function (): void {
    expect($this->matcher->matches('a.*.c', 'a.b.c'))->toBeTrue();
});

it('does not match mid-pattern wildcard when trailing segments differ', function (): void {
    expect($this->matcher->matches('a.*.c', 'a.b.d'))->toBeFalse();
});

it('matches pattern with multiple wildcards', function (): void {
    expect($this->matcher->matches('a.*.b.*.c', 'a.x.b.y.c'))->toBeTrue();
});

it('matches resource wildcard with admin suffix', function (): void {
    expect($this->matcher->matches('resources.*.admin', 'resources.billing.admin'))->toBeTrue();
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

// --- Multi-wildcard patterns ---

it('matches *.*.own multi-wildcard pattern', function (): void {
    expect($this->matcher->matches('*.*.own', 'posts.delete.own'))->toBeTrue()
        ->and($this->matcher->matches('*.*.own', 'comments.update.own'))->toBeTrue();
});

it('does not match *.*.own against permission without .own suffix', function (): void {
    expect($this->matcher->matches('*.*.own', 'posts.delete'))->toBeFalse()
        ->and($this->matcher->matches('*.*.own', 'posts.delete.any'))->toBeFalse();
});

// --- Empty and special character edge cases ---

it('does not match empty granted string against any permission', function (): void {
    expect($this->matcher->matches('', 'posts.create'))->toBeFalse();
});

it('does not match any granted string against empty required permission', function (): void {
    expect($this->matcher->matches('posts.create', ''))->toBeFalse()
        ->and($this->matcher->matches('*', ''))->toBeFalse()
        ->and($this->matcher->matches('*.*', ''))->toBeFalse();
});

it('does not match empty string against empty string', function (): void {
    expect($this->matcher->matches('', ''))->toBeFalse();
});

// --- Special characters and long strings ---

it('matches permissions containing unicode characters', function (): void {
    expect($this->matcher->matches('posts.créer', 'posts.créer'))->toBeTrue()
        ->and($this->matcher->matches('posts.*', 'posts.créer'))->toBeTrue()
        ->and($this->matcher->matches('投稿.作成', '投稿.作成'))->toBeTrue();
});

it('does not match permissions with spaces as equivalent', function (): void {
    expect($this->matcher->matches('posts.create', 'posts .create'))->toBeFalse();
});

it('handles long permission strings within segment limit', function (): void {
    $long = implode('.', array_fill(0, 10, 'segment'));
    expect($this->matcher->matches($long, $long))->toBeTrue()
        ->and($this->matcher->matches('*', $long))->toBeTrue();
});

// --- Segment count limit ---

it('returns false when granted pattern exceeds 10 segments', function (): void {
    $granted = implode('.', array_fill(0, 11, 'segment'));
    expect($this->matcher->matches($granted, 'segment.segment'))->toBeFalse();
});

it('returns false when required pattern exceeds 10 segments', function (): void {
    $required = implode('.', array_fill(0, 11, 'segment'));
    expect($this->matcher->matches('segment.segment', $required))->toBeFalse();
});

it('matches patterns at exactly 10 segments', function (): void {
    $pattern = implode('.', array_fill(0, 10, 'segment'));
    expect($this->matcher->matches($pattern, $pattern))->toBeTrue();
});
