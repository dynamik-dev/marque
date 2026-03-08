# Integrating with Model Policies

Policy Engine handles permission data — which roles have which permissions in which scopes. Laravel model policies handle business logic — ownership checks, time-based rules, state flags. Use them together: the policy delegates permission questions to `canDo()` and handles the business rules itself.

## Deciding when to use a policy

| Scenario | Use a policy? |
| --- | --- |
| Pure permission check (`posts.create`) | No — use `canDo()` directly |
| Ownership check (`.own` vs `.any`) | Yes |
| Time-based rules (locked after 24h) | Yes |
| State-based (pinned, archived, draft) | Yes |
| Compound (permission + business rule) | Yes |

If the authorization decision depends only on a permission string, skip the policy. If it depends on the resource being acted on, write a policy.

## Writing a policy that uses canDo

```php
class PostPolicy
{
    public function create(User $user, Group $group): bool
    {
        return $user->canDo('posts.create', scope: $group);
    }

    public function update(User $user, Post $post): bool
    {
        $group = $post->group;

        if ($user->canDo('posts.update.any', scope: $group)) {
            return true;
        }

        return $user->canDo('posts.update.own', scope: $group)
            && $post->user_id === $user->id;
    }

    public function delete(User $user, Post $post): bool
    {
        $group = $post->group;

        if ($post->is_pinned) {
            return $user->canDo('posts.delete.pinned', scope: $group);
        }

        if ($user->canDo('posts.delete.any', scope: $group)) {
            return true;
        }

        return $user->canDo('posts.delete.own', scope: $group)
            && $post->user_id === $user->id;
    }
}
```

The policy never hardcodes role names. It asks `canDo()` about permissions and applies business logic (ownership, pinned state) on top.

## Using the policy in a controller

```php
class PostController extends Controller
{
    public function update(Post $post)
    {
        $this->authorize('update', $post);

        // update post...
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        // delete post...
    }
}
```

Standard Laravel authorization — `$this->authorize()`, `Gate::allows()`, `@can` in Blade. The policy handles the `canDo()` call internally.

## Registering the policy

```php
// app/Providers/AuthServiceProvider.php
protected $policies = [
    Post::class => PostPolicy::class,
];
```

Or use automatic policy discovery if your policy follows Laravel's naming convention.

## Using @can alongside @canDo in Blade

```blade
{{-- Pure permission check — no resource involved --}}
@canDo('posts.create', $group)
    <button>New Post</button>
@endcanDo

{{-- Resource-level check — hits PostPolicy::delete() --}}
@can('delete', $post)
    <button>Delete</button>
@endcan
```

`@canDo` checks the permission directly. `@can` routes through the policy. Both work in the same template.

> Policies should never contain role names (`if ($user->hasRole('admin'))`). Use permission checks with `canDo()` instead. Roles are a way to group permissions — policies should only care about what a user can do, not what role they hold.
