# Checking Permissions in Blade Templates

The package registers three Blade directives — `@canDo`, `@cannotDo`, and `@hasRole` — for conditionally rendering UI based on the authenticated user's permissions.

## Showing content when a permission is held

```blade
@canDo('posts.create')
    <button>New Post</button>
@endcanDo
```

The button renders only if the authenticated user holds the `posts.create` permission.

## Checking a scoped permission

```blade
@canDo('posts.create', $group)
    <button>New Post in {{ $group->name }}</button>
@endcanDo
```

Pass a `Scopeable` model or a raw scope string as the second argument. The directive checks permissions within that scope.

### Using a raw scope string

```blade
@canDo('posts.create', 'group::5')
    <button>New Post</button>
@endcanDo
```

## Showing fallback content

```blade
@canDo('posts.delete')
    <button>Delete Post</button>
@else
    <span>You cannot delete this post.</span>
@endcanDo
```

All three directives support `@else`.

## Hiding content when a permission is held

```blade
@cannotDo('posts.delete')
    <p>Contact an admin to delete posts.</p>
@endcannotDo
```

`@cannotDo` is the inverse of `@canDo`.

### Scoped cannotDo

```blade
@cannotDo('members.remove', $group)
    <p>You don't have permission to remove members from this group.</p>
@endcannotDo
```

## Checking role membership

```blade
@hasRole('moderator', $group)
    <a href="{{ route('group.modqueue', $group) }}">Mod Queue</a>
@endhasRole
```

The first argument is the role ID, the second is an optional scope.

### Checking a global role

```blade
@hasRole('admin')
    <a href="/admin">Admin Panel</a>
@endhasRole
```

### With fallback

```blade
@hasRole('admin')
    <span>Admin</span>
@else
    <span>Member</span>
@endhasRole
```

## How these relate to standard Blade directives

These directives check permissions through the Policy Engine evaluation pipeline. Laravel's built-in `@can` and `@cannot` still work and hit your model policies as usual.

```blade
{{-- Policy Engine — checks canDo() --}}
@canDo('posts.create', $group)
    <button>New Post</button>
@endcanDo

{{-- Standard Laravel — hits PostPolicy --}}
@can('delete', $post)
    <button>Delete Post</button>
@endcan
```

Use `@canDo` for pure permission checks. Use `@can` when authorization depends on the state of a specific resource (ownership, flags). See [integrating with model policies](integrating-with-model-policies.md) for when to use which.

## Behavior for unauthenticated users

All three directives return `false` when no user is authenticated. Content inside the directive is hidden, and `@else` content is shown.
