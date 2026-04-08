# Checking Permissions in Blade Templates

`@can` works with dot-notated permissions out of the box — the Gate hook routes them through Policy Engine automatically.

## Showing content when a permission is held

```blade
@can('posts.create')
    <button>New Post</button>
@endcan
```


## Checking a scoped permission

```blade
@can('posts.create', $group)
    <button>New Post in {{ $group->name }}</button>
@endcan
```

Pass a `Scopeable` model or a raw scope string as the second argument. The directive checks permissions within that scope.

### Using a raw scope string

```blade
@can('posts.create', 'group::5')
    <button>New Post</button>
@endcan
```

## Showing fallback content

```blade
@can('posts.delete')
    <button>Delete Post</button>
@else
    <span>You cannot delete this post.</span>
@endcan
```

## Hiding content when a permission is denied

```blade
@cannot('posts.delete')
    <p>Contact an admin to delete posts.</p>
@endcannot
```

`@cannot` is the inverse of `@can`.

### Scoped cannot

```blade
@cannot('members.remove', $group)
    <p>You don't have permission to remove members from this group.</p>
@endcannot
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

## How @can handles both permissions and policies

`@can` now routes through the Gate hook for any dot-notated ability. Non-dot abilities (like `update` or `delete`) pass through to your model policies as usual.

```blade
{{-- Dot-notated — handled by Policy Engine via Gate hook --}}
@can('posts.create', $group)
    <button>New Post</button>
@endcan

{{-- Non-dot — hits PostPolicy::delete() --}}
@can('delete', $post)
    <button>Delete</button>
@endcan
```

The Gate hook only intercepts abilities containing a dot; everything else goes through standard Gate and Policy resolution.

## Behavior for unauthenticated users

All directives return `false` when no user is authenticated.
