# Testing Authorization

Marque authorization logic is testable with Pest and Laravel's built-in testing tools. Every example on this page resolves stores from the service container and uses the `Marque` facade for setup, so tests exercise the same contract-driven stack your application uses at runtime.

## Setting up your test file

Use `RefreshDatabase` and resolve the stores you need in `beforeEach`. This gives every test a clean database and direct access to permission and role management.

```php
<?php

use DynamikDev\Marque\Concerns\HasPermissions;
use DynamikDev\Marque\Contracts\PermissionStore;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\Facades\Marque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    $this->permissionStore = app(PermissionStore::class);
    $this->roleStore = app(RoleStore::class);

    $this->user = TestUser::query()->create(['name' => 'Alice']);
});

afterEach(function () {
    Schema::dropIfExists('users');
});
```

The test User model needs the `HasPermissions` trait. Define it at the top of your test file or in a shared test helper:

```php
class TestUser extends Model
{
    use HasPermissions;

    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];
}
```

> If you need Gate integration (e.g., testing `$user->can()`), your model must also implement `Illuminate\Contracts\Auth\Authenticatable` and use the `Authenticatable` trait.

## Testing basic permission checks

Register permissions, create a role with those permissions, assign it to a user, then assert with `can()` and `cannot()`.

```php
it('allows a user with the editor role to create posts', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create', 'posts.update']);

    $this->user->assign('editor');

    expect($this->user->can('posts.create'))->toBeTrue()
        ->and($this->user->can('posts.update'))->toBeTrue();
});

it('denies a user without the permission', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create']);

    $this->user->assign('editor');

    expect($this->user->cannot('posts.delete'))->toBeTrue();
});
```

`can()` and `cannot()` go through Laravel's Gate, which Marque intercepts for any dot-notated ability. This is the same path your controllers and middleware use.

### Testing without any role assigned

```php
it('denies everything when the user has no roles', function () {
    $this->permissionStore->register(['posts.create']);

    expect($this->user->cannot('posts.create'))->toBeTrue();
});
```

## Testing scoped permissions

Create a model that uses the `Scopeable` trait, then assign a role scoped to that model. The user can act within that scope but not others.

```php
use DynamikDev\Marque\Concerns\Scopeable;

class Team extends Model
{
    use Scopeable;

    protected $table = 'teams';
    protected $guarded = [];
    protected string $scopeType = 'team';
}
```

```php
beforeEach(function () {
    Schema::create('teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    // ... existing setup ...
});
```

```php
it('allows a user to act within their assigned scope', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create']);

    $team = Team::query()->create(['name' => 'Engineering']);
    $this->user->assign('editor', $team);

    expect($this->user->can('posts.create', [$team->toScope()]))->toBeTrue();
});

it('denies a user in a scope they are not assigned to', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create']);

    $engineering = Team::query()->create(['name' => 'Engineering']);
    $marketing = Team::query()->create(['name' => 'Marketing']);

    $this->user->assign('editor', $engineering);

    expect($this->user->can('posts.create', [$marketing->toScope()]))->toBeFalse();
});
```

Pass the scope string as the first element of the arguments array when using `can()` through the Gate.

### Testing that global assignments apply to all scopes

A globally assigned role grants access in every scope:

```php
it('allows a globally assigned role to act in any scope', function () {
    Marque::createRole('admin', 'Admin')
        ->grant(['posts.create']);

    $this->user->assign('admin');

    expect($this->user->can('posts.create', ['team::1']))->toBeTrue()
        ->and($this->user->can('posts.create', ['team::99']))->toBeTrue();
});
```

## Testing deny rules

Deny rules override allow rules. Grant broad permissions with a wildcard, then deny a specific action.

```php
it('denies a specific action when a deny rule overrides a wildcard allow', function () {
    Marque::createRole('moderator', 'Moderator')
        ->grant(['posts.*'])
        ->deny(['posts.delete']);

    $this->user->assign('moderator');

    expect($this->user->can('posts.create'))->toBeTrue()
        ->and($this->user->can('posts.update'))->toBeTrue()
        ->and($this->user->cannot('posts.delete'))->toBeTrue();
});
```

The `deny()` method on the role builder prefixes each permission with `!`, which the evaluator treats as an explicit deny. Deny always wins over allow.

### Testing deny rules across multiple roles

When a user has two roles and one denies what the other allows, the deny wins:

```php
it('denies when any assigned role has a deny rule', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.*']);

    Marque::createRole('restricted', 'Restricted')
        ->deny(['posts.delete']);

    $this->user->assign('editor');
    $this->user->assign('restricted');

    expect($this->user->can('posts.create'))->toBeTrue()
        ->and($this->user->cannot('posts.delete'))->toBeTrue();
});
```

## Testing boundaries

Boundaries set a permission ceiling on a scope. Even if a role grants `*.*`, the boundary restricts which permissions are available within that scope.

```php
use DynamikDev\Marque\Contracts\BoundaryStore;

it('blocks permissions outside the boundary ceiling', function () {
    Marque::createRole('admin', 'Admin')
        ->grant(['*.*']);

    Marque::boundary('org::acme')->permits(['posts.*']);

    $this->user->assign('admin', 'org::acme');

    expect($this->user->can('posts.create', ['org::acme']))->toBeTrue()
        ->and($this->user->cannot('billing.manage', ['org::acme']))->toBeTrue();
});
```

The admin role grants everything, but the boundary on `org::acme` caps permissions at `posts.*`. Any permission outside that ceiling is denied.

### Testing that boundaries do not affect other scopes

```php
it('does not apply a boundary to a different scope', function () {
    Marque::createRole('admin', 'Admin')
        ->grant(['*.*']);

    Marque::boundary('org::acme')->permits(['posts.*']);

    $this->user->assign('admin', 'org::other');

    expect($this->user->can('billing.manage', ['org::other']))->toBeTrue();
});
```

### Testing permissions within the boundary ceiling

```php
it('allows permissions that fall within the boundary', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create', 'posts.update']);

    Marque::boundary('org::acme')->permits(['posts.*', 'comments.*']);

    $this->user->assign('editor', 'org::acme');

    expect($this->user->can('posts.create', ['org::acme']))->toBeTrue()
        ->and($this->user->can('posts.update', ['org::acme']))->toBeTrue();
});
```

## Testing resource policies

Resource policies attach authorization rules to specific model types. Use `Marque::resource()` to declare that owners of a resource can perform certain actions.

Your resource model needs the `HasResourcePolicies` trait and a `resourceAttributes()` method that exposes the ownership field:

```php
use DynamikDev\Marque\Concerns\HasResourcePolicies;

class Post extends Model
{
    use HasResourcePolicies;

    protected $table = 'posts';
    protected $fillable = ['title', 'user_id'];

    protected function resourceAttributes(): array
    {
        return [
            'user_id' => $this->user_id,
        ];
    }
}
```

Your User model needs a `principalAttributes()` method returning its `id`:

```php
class TestUser extends Model
{
    use HasPermissions;

    protected $table = 'users';
    protected $guarded = [];

    protected function principalAttributes(): array
    {
        return ['id' => $this->getKey()];
    }
}
```

```php
it('allows the owner to update their own post', function () {
    $this->permissionStore->register(['posts.update']);

    Marque::resource(Post::class)
        ->ownedBy('user_id')
        ->ownerCan('posts.update');

    $post = Post::query()->create([
        'title' => 'My Post',
        'user_id' => $this->user->getKey(),
    ]);

    expect(
        Gate::forUser($this->user)->allows('posts.update', [$post])
    )->toBeTrue();
});

it('denies a non-owner from updating the post', function () {
    $this->permissionStore->register(['posts.update']);

    Marque::resource(Post::class)
        ->ownedBy('user_id')
        ->ownerCan('posts.update');

    $bob = TestUser::query()->create(['name' => 'Bob']);

    $post = Post::query()->create([
        'title' => 'My Post',
        'user_id' => $this->user->getKey(),
    ]);

    expect(
        Gate::forUser($bob)->allows('posts.update', [$post])
    )->toBeFalse();
});
```

The `ownedBy('user_id')` call tells Marque to compare the principal's `id` attribute against the resource's `user_id` attribute. When they match, the `ownerCan` permissions are granted.

### Testing conditional resource policies

Use `when()` to attach permissions that only apply when the resource has certain attribute values:

```php
it('allows anyone to view a published post', function () {
    $this->permissionStore->register(['posts.view']);

    Marque::resource(Post::class)
        ->when(['status' => 'published'], function ($policy) {
            $policy->anyoneCan('posts.view');
        });

    $reader = TestUser::query()->create(['name' => 'Reader']);

    $published = Post::query()->create([
        'title' => 'Public',
        'user_id' => $this->user->getKey(),
        'status' => 'published',
    ]);

    $draft = Post::query()->create([
        'title' => 'Draft',
        'user_id' => $this->user->getKey(),
        'status' => 'draft',
    ]);

    expect(
        Gate::forUser($reader)->allows('posts.view', [$published])
    )->toBeTrue();

    expect(
        Gate::forUser($reader)->allows('posts.view', [$draft])
    )->toBeFalse();
});
```

> The `resourceAttributes()` method on the model must include every attribute referenced in `when()` conditions. If `status` is not returned by `resourceAttributes()`, the condition cannot match.

## Debugging tests with explain()

When a permission check fails unexpectedly, `explain()` returns an `EvaluationResult` with the decision, the source that decided it, and (when tracing is enabled) the matched policy statements.

```php
use DynamikDev\Marque\Enums\Decision;

it('explains why a permission was denied', function () {
    config(['marque.trace' => true]);

    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create']);

    $this->user->assign('editor');

    $result = $this->user->explain('posts.delete');

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('default-deny');
});
```

The `decidedBy` field tells you what caused the decision. Common values:

- `default-deny` means no resolver produced a matching allow statement
- `role:editor` means the `editor` role's allow statement matched
- `role:restricted` means that role's deny statement won
- `boundary:org::acme` means the boundary blocked the permission

### Inspecting matched statements

Enable the `trace` config to populate the `matchedStatements` array:

```php
it('shows which statements matched', function () {
    config(['marque.trace' => true]);

    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create', 'posts.update']);

    $this->user->assign('editor');

    $result = $this->user->explain('posts.create');

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->matchedStatements)->not->toBeEmpty();
});
```

### Debugging scoped permission failures

Pass the scope to `explain()` to trace a scoped check:

```php
it('explains a scoped denial', function () {
    config(['marque.trace' => true]);

    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create']);

    $this->user->assign('editor', 'team::1');

    $result = $this->user->explain('posts.create', scope: 'team::99');

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->decidedBy)->toBe('default-deny');
});
```

The user has the `editor` role in `team::1`, but the check is against `team::99`. The trace confirms no statements matched for that scope.

> Keep `marque.trace` set to `false` in production. Enable it only in test environments or for targeted debugging. See [Protecting the explain trace](../extending/security-considerations.md#protecting-the-explain-trace) for details.

## Asserting effective permissions

Use `effectivePermissions()` to get the full list of net-allowed permissions for a user. This is useful for snapshot-style assertions on a role's grant set.

```php
it('returns all effective permissions for a user', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create', 'posts.read', 'posts.update']);

    Marque::createRole('restricted', 'Restricted')
        ->deny(['posts.update']);

    $this->user->assign('editor');
    $this->user->assign('restricted');

    $permissions = $this->user->effectivePermissions();

    expect($permissions)
        ->toContain('posts.create', 'posts.read')
        ->not->toContain('posts.update');
});
```

> `effectivePermissions()` without a scope argument only returns permissions from global assignments. Pass a scope to include scoped assignments.

## Testing role assignments directly

Use `hasRole()` to assert assignment state without going through the evaluator:

```php
it('confirms a role is assigned', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create']);

    $this->user->assign('editor');

    expect($this->user->hasRole('editor'))->toBeTrue()
        ->and($this->user->hasRole('admin'))->toBeFalse();
});

it('confirms a scoped role assignment', function () {
    Marque::createRole('editor', 'Editor')
        ->grant(['posts.create']);

    $this->user->assign('editor', 'team::5');

    expect($this->user->hasRole('editor', 'team::5'))->toBeTrue()
        ->and($this->user->hasRole('editor', 'team::99'))->toBeFalse()
        ->and($this->user->hasRole('editor'))->toBeFalse();
});
```

> `hasRole()` with a scope checks only scoped assignments, not global ones. A user assigned `editor` globally will return `false` for `hasRole('editor', 'team::5')`. This is different from the evaluator, where a global assignment grants access in all scopes.
