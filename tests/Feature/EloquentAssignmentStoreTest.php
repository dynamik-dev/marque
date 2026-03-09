<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Events\AssignmentCreated;
use DynamikDev\PolicyEngine\Events\AssignmentRevoked;
use DynamikDev\PolicyEngine\Models\Assignment;
use DynamikDev\PolicyEngine\Models\Role;
use DynamikDev\PolicyEngine\Stores\EloquentAssignmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->store = new EloquentAssignmentStore;

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
