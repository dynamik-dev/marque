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

        Schema::table($prefix.'assignments', function (Blueprint $table) use ($prefix): void {
            $table->index(
                ['subject_type', 'subject_id', 'scope'],
                $prefix.'assignments_subject_scope_index',
            );
        });
    }

    public function down(): void
    {
        $prefix = config('marque.table_prefix', '');

        Schema::table($prefix.'assignments', function (Blueprint $table) use ($prefix): void {
            $table->dropIndex($prefix.'assignments_subject_scope_index');
        });
    }
};
