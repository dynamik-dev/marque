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
            $table->json('conditions')->nullable()->after('permission_id');
        });
    }

    public function down(): void
    {
        $prefix = config('marque.table_prefix', '');

        Schema::table($prefix.'role_permissions', function (Blueprint $table): void {
            $table->dropColumn('conditions');
        });
    }
};
