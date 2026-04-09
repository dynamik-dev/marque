# Listening to Events

The default stores dispatch events on every mutation — use them for audit logging, webhooks, or cache invalidation.

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
| `BoundarySet` | A boundary is created or updated | `Boundary $boundary` |
| `BoundaryRemoved` | A boundary is removed | `string $scope` |
| `AuthorizationDenied` | A permission check fails | `string $subject`, `string $permission`, `?string $scope` |
| `DocumentImported` | A policy document is imported (not dry run) | `ImportResult $result` |

All event classes live in `DynamikDev\Marque\Events\` and use readonly constructor properties.

## Listening to an event

```php
use DynamikDev\Marque\Events\AssignmentCreated;

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
use DynamikDev\Marque\Events\AuthorizationDenied;

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
use DynamikDev\Marque\Events\DocumentImported;

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
- `BoundarySet`
- `BoundaryRemoved`

Cache is flushed automatically when any of these fire.

> `PermissionCreated`, `RoleCreated`, `AuthorizationDenied`, and `DocumentImported` do not trigger cache invalidation. Creating new permissions or roles doesn't affect existing cached evaluations.

## Events from custom store implementations

If you [swap a store implementation](swapping-implementations.md), dispatch the same events from your custom store to keep cache invalidation and listeners working:

```php
use DynamikDev\Marque\Events\AssignmentCreated;
use Illuminate\Support\Facades\Event;

Event::dispatch(new AssignmentCreated($assignment));
```

If your custom store doesn't dispatch events, the cache won't invalidate automatically on changes.
