<?php

declare(strict_types=1);

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Events\AssignmentCreated;
use DynamikDev\Marque\Events\AssignmentRevoked;
use DynamikDev\Marque\Models\Assignment;
use DynamikDev\Marque\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = app(AssignmentStore::class);

    // Create a role for FK constraint satisfaction.
    Role::query()->create(['id' => 'editor', 'name' => 'Editor']);
});

// --- assign ---

it('creates a new assignment', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor');

    expect(Assignment::query()->where('subject_type', 'App\\Models\\User')
        ->where('subject_id', 1)
        ->where('role_id', 'editor')
        ->exists())->toBeTrue();
});

it('is idempotent for duplicate assignments', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor');
    $this->store->assign('App\\Models\\User', 1, 'editor');

    expect(Assignment::query()->where('subject_type', 'App\\Models\\User')
        ->where('subject_id', 1)
        ->where('role_id', 'editor')
        ->count())->toBe(1);
});

it('global assign is idempotent and does not create duplicate rows', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor');
    $this->store->assign('App\\Models\\User', 1, 'editor');
    $this->store->assign('App\\Models\\User', 1, 'editor');

    expect(Assignment::query()
        ->where('subject_type', 'App\\Models\\User')
        ->where('subject_id', 1)
        ->where('role_id', 'editor')
        ->whereNull('scope')
        ->count())->toBe(1);
});

it('dispatches AssignmentCreated for new assignment', function (): void {
    Event::fake([AssignmentCreated::class]);

    $this->store->assign('App\\Models\\User', 1, 'editor', 'team::5');

    Event::assertDispatched(AssignmentCreated::class, function (AssignmentCreated $event): bool {
        return $event->assignment->subject_type === 'App\\Models\\User'
            && (int) $event->assignment->subject_id === 1
            && $event->assignment->role_id === 'editor'
            && $event->assignment->scope === 'team::5';
    });
});

it('does not dispatch AssignmentCreated for duplicate assignment', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor');

    Event::fake([AssignmentCreated::class]);

    $this->store->assign('App\\Models\\User', 1, 'editor');

    Event::assertNotDispatched(AssignmentCreated::class);
});

it('treats a unique-constraint violation as a silent no-op (TOCTOU race simulation)', function (): void {
    // Simulate the race: between the exists() check and create(), another process inserts the
    // same tuple. We hook the Assignment "creating" event to perform the racing insert, so by
    // the time our create() reaches the DB, the partial unique index from migration 0006 fires.
    $racingInserted = false;

    Assignment::creating(function (Assignment $assignment) use (&$racingInserted): bool {
        if ($racingInserted) {
            return true;
        }

        $racingInserted = true;

        // Use a fresh query (bypass model events) to insert the duplicate the way a parallel
        // process would.
        Assignment::query()->insert([
            'subject_type' => $assignment->subject_type,
            'subject_id' => $assignment->subject_id,
            'role_id' => $assignment->role_id,
            'scope' => $assignment->scope,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    });

    Event::fake([AssignmentCreated::class]);

    // Should not throw — the QueryException must be caught and treated as success.
    $this->store->assign('App\\Models\\User', 42, 'editor', 'team::99');

    // Exactly one row exists for the tuple (the racing insert), no duplicates.
    expect(Assignment::query()
        ->where('subject_type', 'App\\Models\\User')
        ->where('subject_id', 42)
        ->where('role_id', 'editor')
        ->where('scope', 'team::99')
        ->count())->toBe(1);

    // No AssignmentCreated event fires — this process did not perform the insert.
    Event::assertNotDispatched(AssignmentCreated::class);

    // Cleanup the listener so it does not leak into later tests.
    Assignment::flushEventListeners();
});

// --- revoke ---

it('removes an assignment', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor');

    $this->store->revoke('App\\Models\\User', 1, 'editor');

    expect(Assignment::query()->where('subject_type', 'App\\Models\\User')
        ->where('subject_id', 1)
        ->where('role_id', 'editor')
        ->count())->toBe(0);
});

it('dispatches AssignmentRevoked on removal', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor', 'team::5');

    Event::fake([AssignmentRevoked::class]);

    $this->store->revoke('App\\Models\\User', 1, 'editor', 'team::5');

    Event::assertDispatched(AssignmentRevoked::class, function (AssignmentRevoked $event): bool {
        return $event->assignment->subject_type === 'App\\Models\\User'
            && (int) $event->assignment->subject_id === 1
            && $event->assignment->role_id === 'editor'
            && $event->assignment->scope === 'team::5';
    });
});

it('does not dispatch AssignmentRevoked when nothing to revoke', function (): void {
    Event::fake([AssignmentRevoked::class]);

    $this->store->revoke('App\\Models\\User', 1, 'editor');

    Event::assertNotDispatched(AssignmentRevoked::class);
});

// --- forSubject ---

it('returns all assignments for a subject', function (): void {
    Role::query()->create(['id' => 'admin', 'name' => 'Admin']);

    $this->store->assign('App\\Models\\User', 1, 'editor');
    $this->store->assign('App\\Models\\User', 1, 'admin');
    $this->store->assign('App\\Models\\User', 1, 'editor', 'team::5');

    expect($this->store->forSubject('App\\Models\\User', 1))->toHaveCount(3);
});

it('returns only global assignments for a subject via forSubjectGlobal', function (): void {
    Role::query()->create(['id' => 'admin', 'name' => 'Admin']);

    $this->store->assign('App\\Models\\User', 1, 'editor');
    $this->store->assign('App\\Models\\User', 1, 'admin', 'team::5');

    $assignments = $this->store->forSubjectGlobal('App\\Models\\User', 1);

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->scope)->toBeNull();
});

it('returns global and scoped assignments via forSubjectGlobalAndScope', function (): void {
    Role::query()->create(['id' => 'admin', 'name' => 'Admin']);

    $this->store->assign('App\\Models\\User', 1, 'editor');
    $this->store->assign('App\\Models\\User', 1, 'admin', 'team::5');
    $this->store->assign('App\\Models\\User', 1, 'admin', 'team::9');

    $assignments = $this->store->forSubjectGlobalAndScope('App\\Models\\User', 1, 'team::5');

    expect($assignments)->toHaveCount(2);
    expect($assignments->pluck('scope')->all())->toContain(null, 'team::5');
});

// --- forSubjectInScope ---

it('filters assignments by scope', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor');
    $this->store->assign('App\\Models\\User', 1, 'editor', 'team::5');

    expect($this->store->forSubjectInScope('App\\Models\\User', 1, 'team::5'))->toHaveCount(1);
});

// --- subjectsInScope ---

it('returns all assignments in a scope', function (): void {
    $this->store->assign('App\\Models\\User', 1, 'editor', 'team::5');
    $this->store->assign('App\\Models\\User', 2, 'editor', 'team::5');
    $this->store->assign('App\\Models\\User', 3, 'editor', 'team::9');

    expect($this->store->subjectsInScope('team::5'))->toHaveCount(2);
});

it('filters subjects in scope by roleId', function (): void {
    Role::query()->create(['id' => 'admin', 'name' => 'Admin']);

    $this->store->assign('App\\Models\\User', 1, 'editor', 'team::5');
    $this->store->assign('App\\Models\\User', 2, 'admin', 'team::5');
    $this->store->assign('App\\Models\\User', 3, 'editor', 'team::5');

    expect($this->store->subjectsInScope('team::5', 'editor'))->toHaveCount(2);
});
