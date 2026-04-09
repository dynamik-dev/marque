<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('marque.table_prefix', '');

        Schema::table($prefix.'role_permissions', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                // SQLite does not support dropping foreign keys — the constraint
                // is not enforced by default, and rebuilding the table is handled
                // automatically by Laravel's SQLite schema grammar when needed.
                return;
            }

            $table->dropForeign(['permission_id']);
        });
    }

    public function down(): void
    {
        $prefix = config('marque.table_prefix', '');

        Schema::table($prefix.'role_permissions', function (Blueprint $table) use ($prefix): void {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                return;
            }

            $table->foreign('permission_id')
                ->references('id')
                ->on($prefix.'permissions')
                ->cascadeOnDelete();
        });
    }
};
