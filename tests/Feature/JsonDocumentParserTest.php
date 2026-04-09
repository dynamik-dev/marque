<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\DTOs\PolicyDocument;

beforeEach(function (): void {
    $this->parser = app(DocumentParser::class);
});

// --- parse() v1 format ---

it('parses a valid full v1 document', function (): void {
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

it('parses a partial v1 document with only roles', function (): void {
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
})->throws(InvalidArgumentException::class, 'Invalid JSON');

it('throws InvalidArgumentException for non-object JSON', function (): void {
    $this->parser->parse('"just a string"');
})->throws(InvalidArgumentException::class, 'Invalid JSON');

// --- parse() v2 format ---

it('parses a v2 document with roles keyed by id', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'permissions' => ['posts.create', 'posts.read', 'posts.update'],
        'roles' => [
            'editor' => [
                'permissions' => ['posts.create', 'posts.read', 'posts.update'],
                'conditions' => [
                    'posts.update' => [
                        ['type' => 'attribute_equals', 'parameters' => ['subject_key' => 'dept', 'resource_key' => 'dept']],
                    ],
                ],
            ],
        ],
        'assignments' => [],
        'boundaries' => [
            'free-tier' => ['max_permissions' => ['posts.read']],
        ],
        'resource_policies' => [],
    ]);

    $document = $this->parser->parse($json);

    expect($document->version)->toBe('2.0');
    expect($document->roles)->toHaveCount(1);

    // Parser normalizes v2 roles into v1-compatible array-of-objects
    $editorRole = $document->roles[0];
    expect($editorRole['id'])->toBe('editor');
    expect($editorRole['permissions'])->toBe(['posts.create', 'posts.read', 'posts.update']);

    // Boundaries normalized to v1-compatible format
    expect($document->boundaries)->toHaveCount(1);
    expect($document->boundaries[0]['scope'])->toBe('free-tier');
    expect($document->boundaries[0]['max_permissions'])->toBe(['posts.read']);
});

it('parses v2 resource_policies array', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'permissions' => [],
        'roles' => [],
        'assignments' => [],
        'boundaries' => [],
        'resource_policies' => [
            [
                'resource_type' => 'post',
                'resource_id' => null,
                'effect' => 'Allow',
                'action' => 'posts.read',
                'principal_pattern' => '*',
                'conditions' => [],
            ],
        ],
    ]);

    $document = $this->parser->parse($json);

    expect($document->resourcePolicies)->toHaveCount(1);
    expect($document->resourcePolicies[0]['resource_type'])->toBe('post');
    expect($document->resourcePolicies[0]['effect'])->toBe('Allow');
    expect($document->resourcePolicies[0]['action'])->toBe('posts.read');
    expect($document->resourcePolicies[0]['principal_pattern'])->toBe('*');
    expect($document->resourcePolicies[0]['resource_id'])->toBeNull();
});

it('parses v2 system role flag', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'permissions' => ['posts.read'],
        'roles' => [
            'admin' => ['permissions' => ['posts.read'], 'system' => true],
            'viewer' => ['permissions' => ['posts.read']],
        ],
        'assignments' => [],
        'boundaries' => [],
        'resource_policies' => [],
    ]);

    $document = $this->parser->parse($json);

    expect($document->roles)->toHaveCount(2);

    $admin = collect($document->roles)->firstWhere('id', 'admin');
    expect($admin['system'])->toBeTrue();

    $viewer = collect($document->roles)->firstWhere('id', 'viewer');
    expect($viewer)->not->toHaveKey('system');
});

it('returns empty resourcePolicies when not present in document', function (): void {
    $json = json_encode(['version' => '1.0']);

    $document = $this->parser->parse($json);

    expect($document->resourcePolicies)->toBe([]);
});

// --- serialize() ---

it('serializes a document to v2 format with pretty-printed JSON', function (): void {
    $document = new PolicyDocument(
        version: '1.0',
        permissions: ['posts.create'],
        roles: [
            ['id' => 'admin', 'name' => 'Admin', 'permissions' => ['posts.create']],
        ],
    );

    $json = $this->parser->serialize($document);
    $decoded = json_decode($json, associative: true);

    // Always outputs version 2.0
    expect($decoded['version'])->toBe('2.0');
    expect($decoded['permissions'])->toBe(['posts.create']);

    // Roles converted to v2 keyed format
    expect($decoded['roles'])->toBeArray();
    expect($decoded['roles'])->toHaveKey('admin');
    expect($decoded['roles']['admin']['permissions'])->toBe(['posts.create']);

    expect($decoded['assignments'])->toBe([]);
    expect($decoded['boundaries'])->toBe([]);
    expect($decoded['resource_policies'])->toBe([]);
});

it('serializes v2 roles correctly when already in keyed format', function (): void {
    $document = new PolicyDocument(
        version: '2.0',
        permissions: ['posts.read'],
        roles: [
            'editor' => ['permissions' => ['posts.read']],
        ],
    );

    $json = $this->parser->serialize($document);
    $decoded = json_decode($json, associative: true);

    expect($decoded['roles'])->toHaveKey('editor');
    expect($decoded['roles']['editor']['permissions'])->toBe(['posts.read']);
});

it('serializes boundaries in v2 keyed format', function (): void {
    $document = new PolicyDocument(
        version: '1.0',
        boundaries: [
            ['scope' => 'team::5', 'max_permissions' => ['posts.read']],
        ],
    );

    $json = $this->parser->serialize($document);
    $decoded = json_decode($json, associative: true);

    expect($decoded['boundaries'])->toHaveKey('team::5');
    expect($decoded['boundaries']['team::5']['max_permissions'])->toBe(['posts.read']);
});

it('includes resource_policies in serialized output', function (): void {
    $document = new PolicyDocument(
        version: '2.0',
        resourcePolicies: [
            [
                'resource_type' => 'post',
                'resource_id' => null,
                'effect' => 'Allow',
                'action' => 'posts.read',
                'principal_pattern' => '*',
                'conditions' => [],
            ],
        ],
    );

    $json = $this->parser->serialize($document);
    $decoded = json_decode($json, associative: true);

    expect($decoded['resource_policies'])->toHaveCount(1);
    expect($decoded['resource_policies'][0]['action'])->toBe('posts.read');
});

it('produces JSON with unescaped slashes', function (): void {
    $document = new PolicyDocument(
        permissions: ['api/posts.create'],
    );

    $json = $this->parser->serialize($document);

    expect($json)->toContain('api/posts.create');
    expect($json)->not->toContain('api\\/posts.create');
});

it('round-trips a v2 document through serialize and parse', function (): void {
    $original = new PolicyDocument(
        version: '2.0',
        permissions: ['posts.create', 'posts.delete', '!posts.publish'],
        roles: [
            'editor' => ['permissions' => ['posts.create', 'posts.delete']],
            'viewer' => ['permissions' => ['posts.read'], 'system' => true],
        ],
        assignments: [
            ['subject' => 'user::1', 'role' => 'editor'],
            ['subject' => 'user::2', 'role' => 'viewer', 'scope' => 'group::5'],
        ],
        boundaries: [
            'group::5' => ['max_permissions' => ['posts.create', 'posts.read']],
        ],
        resourcePolicies: [],
    );

    $restored = $this->parser->parse($this->parser->serialize($original));

    expect($restored->version)->toBe('2.0');
    expect($restored->permissions)->toBe($original->permissions);
    expect($restored->assignments)->toBe($original->assignments);
    expect($restored->resourcePolicies)->toBe([]);

    // Roles: serialized to v2 keyed and parsed back to v1 indexed format
    expect($restored->roles)->toHaveCount(2);
    $editorRole = collect($restored->roles)->firstWhere('id', 'editor');
    expect($editorRole['permissions'])->toBe(['posts.create', 'posts.delete']);

    // Boundaries: serialized to v2 keyed and parsed back to v1 indexed format
    expect($restored->boundaries)->toHaveCount(1);
    expect($restored->boundaries[0]['scope'])->toBe('group::5');
    expect($restored->boundaries[0]['max_permissions'])->toBe(['posts.create', 'posts.read']);
});

// --- validate() ---

it('validates a valid full v1 document', function (): void {
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

it('validates a valid v2 document', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'permissions' => ['posts.create', 'posts.read'],
        'roles' => [
            'editor' => ['permissions' => ['posts.create', 'posts.read']],
        ],
        'assignments' => [
            ['subject' => 'user::1', 'role' => 'editor'],
        ],
        'boundaries' => [
            'free-tier' => ['max_permissions' => ['posts.read']],
        ],
        'resource_policies' => [
            ['resource_type' => 'post', 'effect' => 'Allow', 'action' => 'posts.read'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result->valid)->toBeTrue();
    expect($result->errors)->toBe([]);
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

it('rejects v1 roles missing required keys', function (): void {
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

it('rejects v2 roles missing permissions key', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'roles' => [
            'admin' => ['name' => 'Admin'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[admin] is missing required key: permissions');
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

it('rejects v1 boundaries missing required keys', function (): void {
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

it('rejects v2 boundaries missing max_permissions key', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'boundaries' => [
            'free-tier' => [],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('boundaries[free-tier] is missing required key: max_permissions');
});

it('rejects resource_policies missing required keys', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'resource_policies' => [
            ['principal_pattern' => '*'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('resource_policies[0] is missing required key: resource_type');
    expect($result->errors)->toContain('resource_policies[0] is missing required key: effect');
    expect($result->errors)->toContain('resource_policies[0] is missing required key: action');
});

it('rejects roles that is not an array', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => 'not-an-array',
    ]);

    $result = $this->parser->validate($json);

    expect($result)
        ->valid->toBeFalse();

    expect($result->errors)->toContain('roles must be an array');
});

it('rejects a non-array v1 role entry', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => ['not-an-object', 42],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[0] must be an object');
    expect($result->errors)->toContain('roles[1] must be an object');
});

it('rejects assignments that is not an array', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'assignments' => 'not-an-array',
    ]);

    $result = $this->parser->validate($json);

    expect($result)
        ->valid->toBeFalse();

    expect($result->errors)->toContain('assignments must be an array');
});

it('rejects a non-array assignment entry', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'assignments' => ['not-an-object', true],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('assignments[0] must be an object');
    expect($result->errors)->toContain('assignments[1] must be an object');
});

it('rejects boundaries that is not an array', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'boundaries' => 'not-an-array',
    ]);

    $result = $this->parser->validate($json);

    expect($result)
        ->valid->toBeFalse();

    expect($result->errors)->toContain('boundaries must be an array');
});

it('rejects a non-array v1 boundary entry', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'boundaries' => ['not-an-object', 99],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('boundaries[0] must be an object');
    expect($result->errors)->toContain('boundaries[1] must be an object');
});

it('rejects v1 role permissions that is a string instead of an array', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => 'posts.create'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[0].permissions must be an array');
});

it('rejects v1 role permissions containing non-string items', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => [
            ['id' => 'editor', 'name' => 'Editor', 'permissions' => [123, null]],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[0].permissions[0] must be a string');
    expect($result->errors)->toContain('roles[0].permissions[1] must be a string');
});

it('rejects v2 role permissions containing non-string items', function (): void {
    $json = json_encode([
        'version' => '2.0',
        'roles' => [
            'editor' => ['permissions' => [123, null]],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[editor].permissions[0] must be a string');
    expect($result->errors)->toContain('roles[editor].permissions[1] must be a string');
});

it('rejects v1 boundary max_permissions that is a string instead of an array', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'boundaries' => [
            ['scope' => 'group::5', 'max_permissions' => 'posts.*'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('boundaries[0].max_permissions must be an array');
});

it('rejects v1 boundary max_permissions containing non-string items', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'boundaries' => [
            ['scope' => 'group::5', 'max_permissions' => [123]],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('boundaries[0].max_permissions[0] must be a string');
});

it('rejects non-string v1 role id', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => [
            ['id' => 42, 'name' => 'Admin', 'permissions' => ['posts.create']],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[0].id must be a string');
});

it('rejects non-string v1 role name', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'roles' => [
            ['id' => 'admin', 'name' => true, 'permissions' => ['posts.create']],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('roles[0].name must be a string');
});

it('rejects non-string assignment subject', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'assignments' => [
            ['subject' => 123, 'role' => 'editor'],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('assignments[0].subject must be a string');
});

it('rejects non-string assignment role', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'assignments' => [
            ['subject' => 'user::1', 'role' => ['editor']],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('assignments[0].role must be a string');
});

it('rejects non-string v1 boundary scope', function (): void {
    $json = json_encode([
        'version' => '1.0',
        'boundaries' => [
            ['scope' => 42, 'max_permissions' => ['posts.create']],
        ],
    ]);

    $result = $this->parser->validate($json);

    expect($result)->valid->toBeFalse();
    expect($result->errors)->toContain('boundaries[0].scope must be a string');
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
