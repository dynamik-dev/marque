# Listening to Events

The default store implementations dispatch Laravel events on every mutation. Use these for audit logging, webhook dispatch, cache invalidation, or any side effect that should follow an authorization change.

## Events dispatched by the package

| Event | Fired when... | Properties |
| --- | --- | --- |
| `PermissionCreated` | A new permission is registered | `string $permission` |
| `PermissionDeleted` | A permission is removed | `string $permission` |
| `RoleCreated` | A new role is created | `Role $role` |
| `RoleUpdated` | A role's name or permissions change | `Role $role`, `array $changes` |
| `RoleDeleted` | A role is deleted | `Role $role` |
| `AssignmentCreated` | A role is assigned to a subject | `Assignment $assignment` |
| `AssignmentRevoked` | An assignment is removed | `Assignment $assignment` |
| `AuthorizationDenied` | A `canDo()` check fails | `string $subject`, `string $permission`, `?string $scope` |
| `DocumentImported` | A policy document is imported (not dry run) | `ImportResult $result` |

All event classes live in `DynamikDev\PolicyEngine\Events\` and use readonly constructor properties.

## Listening to an event

```php
use DynamikDev\PolicyEngine\Events\AssignmentCreated;

class LogAssignment
{
    public function handle(AssignmentCreated $event): void
    {
        logger()->info('Role assigned', [
            'subject_type' => $event->assignment->subject_type,
            'subject_id' => $event->assignment->subject_id,
            'role' => $event->assignment->role_id,
            'scope' => $event->assignment->scope,
        ]);
    }
}
```

Register the listener in your `EventServiceProvider` or use attribute-based discovery.

## Tracking denied authorization attempts

```php
use DynamikDev\PolicyEngine\Events\AuthorizationDenied;

class TrackDenials
{
    public function handle(AuthorizationDenied $event): void
    {
        logger()->warning('Authorization denied', [
            'subject' => $event->subject,
            'permission' => $event->permission,
            'scope' => $event->scope,
        ]);
    }
}
```

`AuthorizationDenied` is only dispatched when `log_denials` is `true` in the config. The `$subject` property is a string like `App\Models\User:42`.

## Reacting to document imports

```php
use DynamikDev\PolicyEngine\Events\DocumentImported;

class NotifyAdminsOfImport
{
    public function handle(DocumentImported $event): void
    {
        $result = $event->result;

        if (count($result->rolesCreated) > 0 || count($result->rolesUpdated) > 0) {
            // notify admins
        }
    }
}
```

`DocumentImported` is not fired during dry runs.

## Events that trigger cache invalidation

The package registers an `InvalidatePermissionCache` listener that responds to:

- `AssignmentCreated`
- `AssignmentRevoked`
- `RoleUpdated`
- `RoleDeleted`
- `PermissionDeleted`

When any of these fire, the permission cache is flushed. This happens automatically — you don't need to configure it.

> `PermissionCreated`, `RoleCreated`, `AuthorizationDenied`, and `DocumentImported` do not trigger cache invalidation. Creating new permissions or roles doesn't affect existing cached evaluations.

## Events from custom store implementations

If you [swap a store implementation](swapping-implementations.md), dispatch the same events from your custom store to keep cache invalidation and listeners working:

```php
use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use Illuminate\Support\Facades\Event;

Event::dispatch(new AssignmentCreated($assignment));
```

If your custom store doesn't dispatch events, the cache won't invalidate automatically on changes.
