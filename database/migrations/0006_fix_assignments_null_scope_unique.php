<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('marque.table_prefix', '');
        $table = $prefix.'assignments';

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['subject_id', 'subject_type', 'role_id', 'scope']);
        });

        $coalesce = match (DB::getDriverName()) {
            'mysql' => "(COALESCE(scope, '__null__'))",
            default => "COALESCE(scope, '__null__')",
        };

        DB::statement(
            "CREATE UNIQUE INDEX {$table}_unique_assignment ON {$table} (subject_type, subject_id, role_id, {$coalesce})"
        );
    }

    public function down(): void
    {
        $prefix = config('marque.table_prefix', '');
        $table = $prefix.'assignments';

        match (DB::getDriverName()) {
            'mysql' => DB::statement("DROP INDEX {$table}_unique_assignment ON {$table}"),
            default => DB::statement("DROP INDEX IF EXISTS {$table}_unique_assignment"),
        };

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unique(['subject_id', 'subject_type', 'role_id', 'scope']);
        });
    }
};
