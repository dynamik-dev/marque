<?php

declare(strict_types=1);

use DynamikDev\PolicyEngine\Contracts\RoleStore;
use DynamikDev\PolicyEngine\Models\Permission;
use DynamikDev\PolicyEngine\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /*
     * Enable FK enforcement for SQLite — without this, SQLite silently
     * ignores foreign key violations and the test would pass vacuously.
     */
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA foreign_keys = ON');
    }

    $this->store = app(RoleStore::class);
});

it('stores wildcard and deny permissions on a role without FK violation', function (): void {
    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.delete']);

    $this->store->save('moderator', 'Moderator', ['posts.*', '!posts.delete']);

    $permissions = RolePermission::query()
        ->where('role_id', 'moderator')
        ->pluck('permission_id')
        ->sort()
        ->values()
        ->all();

    expect($permissions)->toBe(['!posts.delete', 'posts.*']);
});

it('retrieves wildcard and deny permissions via permissionsFor', function (): void {
    Permission::query()->create(['id' => 'posts.create']);
    Permission::query()->create(['id' => 'posts.delete']);

    $this->store->save('moderator', 'Moderator', ['posts.*', '!posts.delete']);

    $permissions = $this->store->permissionsFor('moderator');

    expect($permissions)
        ->toHaveCount(2)
        ->toContain('posts.*')
        ->toContain('!posts.delete');
});
