<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Documents\JsonDocumentParser;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;

beforeEach(function (): void {
    $this->parser = new JsonDocumentParser;
});

// --- parse() ---

it('parses a valid full document', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create', 'posts.delete'],
        'roles' => [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.delete']],
        ],
        'assignments' => [
            ['subject' => 'user::1', 'role' => 'editor', 'scope' => 'group::5'],
        ],
        'boundaries' => [
            ['scope' => 'group::5', 'max_permissions' => ['posts.create']],
        ],
    ]);

    $document = $this->parser->parse($json);

    expect($document)
        ->toBeInstanceOf(PolicyDocument::class)
        ->version->toBe('1.0')
        ->permissions->toBe(['posts.create', 'posts.delete'])
        ->roles->toHaveCount(1)
        ->assignments->toHaveCount(1)
        ->boundaries->toHaveCount(1);

    expect($document->roles[0])
        ->toBe(['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.delete']]);

    expect($document->assignments[0])
        ->toBe(['subject' => 'user::1', 'role' => 'editor', 'scope' => 'group::5']);

    expect($document->boundaries[0])
        ->toBe(['scope' => 'group::5', 'max_permissions' => ['posts.create']]);
});

it('parses a partial document with only roles', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => [
            ['id' => 'viewer', 'name' => 'Viewer', 'permissions' => ['posts.read']],
        ],
    ]);

    $document = $this->parser->parse($json);

    expect($document)
        ->version->toBe('1.0')
        ->permissions->toBe([])
        ->roles->toHaveCount(1)
        ->assignments->toBe([])
        ->boundaries->toBe([]);
});

it('defaults version to 1.0 when missing', function (): void {
    $json = json_encode(['permissions' => ['posts.create']]);

    $document = $this->parser->parse($json);

    expect($document->version)->toBe('1.0');
});

it('throws InvalidArgumentException for invalid JSON', function (): void {
    $this->parser->parse('not valid json {{{');
})->throws(\InvalidArgumentException::class, 'Invalid JSON');

it('throws InvalidArgumentException for non-object JSON', function (): void {
    $this->parser->parse('"just a string"');
})->throws(\InvalidArgumentException::class, 'Invalid JSON');

// --- serialize() ---

it('serializes a document to pretty-printed JSON', function (): void {
    $document = new PolicyDocument(
        version: '1.0',
        permissions: ['posts.create'],
        roles: [
            ['id' => 'admin', 'name' => 'Admin', 'permissions' => ['posts.create']],
        ],
    );

    $json = $this->parser->serialize($document);
    $decoded = json_decode($json, associative: true);

    expect($decoded)->toBe([
        'version' => '1.0',
        'permissions' => ['posts.create'],
        'roles' => [
            ['id' => 'admin', 'name' => 'Admin', 'permissions' => ['posts.create']],
        ],
        'assignments' => [],
        'boundaries' => [],
    ]);
});

it('produces JSON with unescaped slashes', function (): void {
    $document = new PolicyDocument(
        permissions: ['api/posts.create'],
    );

    $json = $this->parser->serialize($document);

    expect($json)->toContain('api/posts.create');
    expect($json)->not->toContain('api\\/posts.create');
});

it('round-trips a document through serialize and parse', function (): void {
    $original = new PolicyDocument(
        version: '2.0',
        permissions: ['posts.create', 'posts.delete', '!posts.publish'],
        roles: [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => ['posts.create', 'posts.delete']],
            ['id' => 'viewer', 'name' => 'Viewer', 'permissions' => ['posts.read'], 'system' => true],
        ],
        assignments: [
            ['subject' => 'user::1', 'role' => 'editor'],
            ['subject' => 'user::2', 'role' => 'viewer', 'scope' => 'group::5'],
        ],
        boundaries: [
            ['scope' => 'group::5', 'max_permissions' => ['posts.create', 'posts.read']],
        ],
    );

    $restored = $this->parser->parse($this->parser->serialize($original));

    expect($restored->version)->toBe($original->version);
    expect($restored->permissions)->toBe($original->permissions);
    expect($restored->roles)->toBe($original->roles);
    expect($restored->assignments)->toBe($original->assignments);
    expect($restored->boundaries)->toBe($original->boundaries);
});

// --- validate() ---

it('validates a valid full document', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create'],
        'roles' => [
            ['id' => 'admin', 'name' => 'Admin', 'permissions' => ['posts.create']],
        ],
        'assignments' => [
            ['subject' => 'user::1', 'role' => 'admin'],
        ],
        'boundaries' => [
            ['scope' => 'group::5', 'max_permissions' => ['posts.create']],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)
        ->valid->toBeTrue()
        ->errors->toBe([]);
});

it('validates a minimal document with only version', function (): void {
    $json = json_encode(['version' => '1.0']);

    $result = $this->parser->validate($json);

    expect($result->valid)->toBeTrue();
});

it('rejects invalid JSON', function (): void {
    $result = $this->parser->validate('{{not json');

    expect($result)
        ->valid->toBeFalse()
        ->errors->toHaveCount(1);

    expect($result->errors[0])->toContain('Invalid JSON');
});

it('rejects a document missing the version field', function (): void {
    $json = json_encode(['permissions' => ['posts.create']]);

    $result = $this->parser->validate($json);

    expect($result)
        ->valid->toBeFalse()
        ->errors->toContain('Missing required field: version');
});

it('rejects non-string permissions', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'permissions' => ['posts.create', 42, true],
    ]);

    $result = $this->parser->validate($json);

    expect($result)
        ->valid->toBeFalse();

    expect($result->errors)->toContain('permissions[1] must be a string');
    expect($result->errors)->toContain('permissions[2] must be a string');
});

it('rejects roles missing required keys', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => [
            ['id' => 'admin'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[0] is missing required key: name');
    expect($result->errors)->toContain('roles[0] is missing required key: permissions');
});

it('rejects assignments missing required keys', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'assignments' => [
            ['scope' => 'group::5'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('assignments[0] is missing required key: subject');
    expect($result->errors)->toContain('assignments[0] is missing required key: role');
});

it('rejects boundaries missing required keys', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'boundaries' => [
            ['scope' => 'group::5'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('boundaries[0] is missing required key: max_permissions');
});

it('collects multiple validation errors', function (): void {
    $json = json_encode([
        'permissions' => [123],
        'roles' => [['id' => 'admin']],
        'assignments' => [['scope' => 'group::5']],
        'boundaries' => [[]],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect(count($result->errors))->toBeGreaterThanOrEqual(6);
});
